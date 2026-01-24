<?php
/**
 * NSFW Profile Generation Queue
 * ==============================
 * Implements queued NSFW profile generation to prevent rate limiting
 * when entering areas with many NPCs.
 *
 * FLOW:
 * 1. prerequest.php queues NPC for profile generation (instead of fire-and-forget)
 * 2. context.php processes 1-2 queued profiles per request cycle
 * 3. Full NPC context (including slave/prostitute status) is passed to Grok
 *
 * This ensures:
 * - No rate limiting when walking into a room with 20 NPCs
 * - Grok gets full context about NPC's current game state
 * - Profiles are generated in order, one at a time
 */

// Maximum profiles to process per request cycle
define('NSFW_QUEUE_BATCH_SIZE', 2);

// Maximum retries before abandoning a profile generation
define('NSFW_QUEUE_MAX_RETRIES', 3);

/**
 * Get current playthrough profile ID for Paradox isolation
 * Prevents cross-playthrough contamination of NSFW profiles
 */
function _nsfwGetCurrentProfileId() {
    // Check if there's an active playthrough profile
    // This is set by the Paradox/playthrough system
    if (isset($GLOBALS['CHIM_CORE_CURRENT_PROFILE_DATA']['id'])) {
        return (int)$GLOBALS['CHIM_CORE_CURRENT_PROFILE_DATA']['id'];
    }

    // Fallback: Get from the current NPC's profile_id
    if (isset($GLOBALS['CHIM_CORE_CURRENT_NPC_DATA']['profile_id'])) {
        return (int)$GLOBALS['CHIM_CORE_CURRENT_NPC_DATA']['profile_id'];
    }

    // No profile context - use 0 as default (will work but no isolation)
    return 0;
}

/**
 * Queue an NPC for NSFW profile generation
 *
 * @param string $npcName The NPC name
 * @param array $gameContext Current game context (slave status, relationships, etc.)
 * @return bool Success status
 */
function _nsfwQueueProfileGeneration($npcName, $gameContext = []) {
    if (!isset($GLOBALS['db']) || !$GLOBALS['db']) {
        error_log("[NSFW-QUEUE] Cannot queue: no database connection");
        return false;
    }

    // Get current playthrough profile ID for Paradox isolation
    $profileId = _nsfwGetCurrentProfileId();

    // Build rich context from game state
    $queueData = [
        'npc_name' => $npcName,
        'profile_id' => $profileId, // Track which playthrough this is for
        'queued_at' => date('Y-m-d H:i:s'),
        // Game state context - LLM needs to know this
        'game_context' => [
            'is_slave' => $gameContext['is_slave'] ?? false,
            'slave_affinity' => $gameContext['slave_affinity'] ?? null,
            'slave_owner' => $gameContext['slave_owner'] ?? null,
            'is_prostitute' => $gameContext['is_prostitute'] ?? false,
            'is_courtesan' => $gameContext['is_courtesan'] ?? false,
            'current_location' => $gameContext['current_location'] ?? null,
            'player_name' => $gameContext['player_name'] ?? null,
            'relationship_with_player' => $gameContext['relationship_with_player'] ?? null,
            'faction_info' => $gameContext['faction_info'] ?? null,
            'mod_source' => $gameContext['mod_source'] ?? null
        ]
    ];

    $jsonData = json_encode($queueData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        $escapedJson = $GLOBALS['db']->escape($jsonData);
        $escapedName = $GLOBALS['db']->escape($npcName);

        // Upsert: Replace any existing queue for this NPC+profile combo
        // UNIQUE constraint on (npc_name, profile_id) prevents cross-playthrough issues
        $GLOBALS['db']->query(
            "INSERT INTO nsfw_profile_queue (npc_name, profile_id, queue_data, created_at)
             VALUES ('{$escapedName}', {$profileId}, '{$escapedJson}', NOW())
             ON CONFLICT (npc_name, profile_id) DO UPDATE SET queue_data = '{$escapedJson}', created_at = NOW(), retry_count = 0"
        );

        error_log("[NSFW-QUEUE] Queued profile generation for: {$npcName} (profile: {$profileId})");
        return true;

    } catch (Exception $e) {
        // Table might not exist yet - try to create it
        if (strpos($e->getMessage(), 'does not exist') !== false) {
            _nsfwCreateQueueTable();
            // Retry once
            try {
                $GLOBALS['db']->query(
                    "INSERT INTO nsfw_profile_queue (npc_name, profile_id, queue_data, created_at)
                     VALUES ('{$escapedName}', {$profileId}, '{$escapedJson}', NOW())
                     ON CONFLICT (npc_name, profile_id) DO UPDATE SET queue_data = '{$escapedJson}', created_at = NOW(), retry_count = 0"
                );
                return true;
            } catch (Exception $e2) {
                error_log("[NSFW-QUEUE] Failed to queue after table creation: " . $e2->getMessage());
                return false;
            }
        }
        error_log("[NSFW-QUEUE] Failed to queue: " . $e->getMessage());
        return false;
    }
}

/**
 * Process pending NSFW profile generations
 * Called from context.php to generate 1-2 profiles per request cycle
 * Only processes queue items for the CURRENT playthrough (Paradox isolation)
 *
 * @param int $limit Max number to process per request
 * @return array Results of processing
 */
function _nsfwProcessQueue($limit = NSFW_QUEUE_BATCH_SIZE) {
    if (!isset($GLOBALS['db']) || !$GLOBALS['db']) {
        return ['processed' => 0, 'error' => 'no database'];
    }

    // Get current playthrough profile ID - only process items for THIS playthrough
    $currentProfileId = _nsfwGetCurrentProfileId();

    $results = ['processed' => 0, 'errors' => [], 'retried' => 0, 'abandoned' => 0];

    try {
        // Get pending generations for CURRENT PLAYTHROUGH only (oldest first, prioritize fewer retries)
        // This prevents cross-playthrough contamination - won't process another playthrough's queued NPCs
        $rows = $GLOBALS['db']->fetchAll(
            "SELECT id, npc_name, profile_id, queue_data, COALESCE(retry_count, 0) as retry_count
             FROM nsfw_profile_queue
             WHERE profile_id = {$currentProfileId}
             ORDER BY COALESCE(retry_count, 0) ASC, created_at ASC
             LIMIT {$limit}"
        );

        if (empty($rows)) {
            return $results;
        }

        $successIds = [];
        $retryIds = [];
        $abandonIds = [];

        foreach ($rows as $row) {
            $data = json_decode($row['queue_data'], true);
            $retryCount = intval($row['retry_count']);
            $npcName = $row['npc_name'];

            if (!$data) {
                $successIds[] = $row['id']; // Invalid data, just delete
                continue;
            }

            try {
                // Generate the NSFW profile with full context
                $generateResult = _nsfwGenerateProfileWithContext($npcName, $data['game_context'] ?? []);

                if ($generateResult['success']) {
                    error_log("[NSFW-QUEUE] Successfully generated profile for: {$npcName}");
                    $successIds[] = $row['id'];
                    $results['processed']++;
                } else {
                    throw new Exception($generateResult['error'] ?? 'Unknown generation error');
                }

            } catch (Exception $e) {
                $errorMsg = $e->getMessage();
                $results['errors'][] = "NPC {$npcName}: " . $errorMsg;

                if ($retryCount >= NSFW_QUEUE_MAX_RETRIES) {
                    error_log("[NSFW-QUEUE] ABANDONED after {$retryCount} retries: {$npcName} - {$errorMsg}");
                    $abandonIds[] = $row['id'];
                    $results['abandoned']++;
                } else {
                    $retryIds[] = ['id' => $row['id'], 'error' => substr($errorMsg, 0, 500)];
                    $results['retried']++;
                    error_log("[NSFW-QUEUE] Retry {$retryCount}/" . NSFW_QUEUE_MAX_RETRIES . " for {$npcName}: {$errorMsg}");
                }
            }
        }

        // Delete successfully processed entries
        if (!empty($successIds)) {
            $idList = implode(',', array_map('intval', $successIds));
            $GLOBALS['db']->query("DELETE FROM nsfw_profile_queue WHERE id IN ({$idList})");
        }

        // Delete abandoned entries
        if (!empty($abandonIds)) {
            $idList = implode(',', array_map('intval', $abandonIds));
            $GLOBALS['db']->query("DELETE FROM nsfw_profile_queue WHERE id IN ({$idList})");
        }

        // Increment retry count for failed entries
        foreach ($retryIds as $retry) {
            $id = intval($retry['id']);
            $escapedError = $GLOBALS['db']->escape($retry['error']);
            $GLOBALS['db']->query(
                "UPDATE nsfw_profile_queue
                 SET retry_count = COALESCE(retry_count, 0) + 1,
                     last_error = '{$escapedError}'
                 WHERE id = {$id}"
            );
        }

    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'does not exist') === false) {
            error_log("[NSFW-QUEUE] Queue processing error: " . $e->getMessage());
        }
    }

    return $results;
}

/**
 * Generate NSFW profile with full game context
 * This calls the LLM with enhanced context about slave/prostitute status
 *
 * @param string $npcName The NPC name
 * @param array $gameContext Game state context
 * @return array Result with success/error
 */
function _nsfwGenerateProfileWithContext($npcName, $gameContext = []) {
    try {
        // Get NPC data from core_npc_master
        $escapedName = $GLOBALS['db']->escape($npcName);
        $npcBio = $GLOBALS['db']->fetchOne("
            SELECT npc_name, personality, occupation, speechstyle, gender, race,
                   appearance, relationships, skills, goals, npc_static_bio,
                   prompt_head, core, extended_data
            FROM core_npc_master
            WHERE LOWER(npc_name) = LOWER('{$escapedName}')
            LIMIT 1
        ");

        if (!$npcBio) {
            // Try partial match
            $npcBio = $GLOBALS['db']->fetchOne("
                SELECT npc_name, personality, occupation, speechstyle, gender, race,
                       appearance, relationships, skills, goals, npc_static_bio,
                       prompt_head, core, extended_data
                FROM core_npc_master
                WHERE LOWER(npc_name) LIKE LOWER('%{$escapedName}%')
                LIMIT 1
            ");
        }

        // Build NPC context
        $npcContext = "NPC Name: {$npcName}\n";
        if ($npcBio) {
            if (!empty($npcBio['gender'])) $npcContext .= "Gender: {$npcBio['gender']}\n";
            if (!empty($npcBio['race'])) $npcContext .= "Race: {$npcBio['race']}\n";
            if (!empty($npcBio['core'])) $npcContext .= "Core Summary: {$npcBio['core']}\n";
            if (!empty($npcBio['personality'])) $npcContext .= "Personality: {$npcBio['personality']}\n";
            if (!empty($npcBio['occupation'])) $npcContext .= "Occupation: {$npcBio['occupation']}\n";
            if (!empty($npcBio['speechstyle'])) $npcContext .= "Speech Style: {$npcBio['speechstyle']}\n";
            if (!empty($npcBio['appearance'])) $npcContext .= "Appearance: {$npcBio['appearance']}\n";
            if (!empty($npcBio['relationships'])) $npcContext .= "Relationships: {$npcBio['relationships']}\n";
            if (!empty($npcBio['goals'])) $npcContext .= "Goals: {$npcBio['goals']}\n";
            if (!empty($npcBio['skills'])) $npcContext .= "Skills: {$npcBio['skills']}\n";
            if (!empty($npcBio['npc_static_bio'])) $npcContext .= "Biography: {$npcBio['npc_static_bio']}\n";
            if (!empty($npcBio['prompt_head'])) $npcContext .= "Character Prompt: {$npcBio['prompt_head']}\n";
        }

        // Add CRITICAL game state context
        $gameStateContext = "\n=== CURRENT GAME STATE (IMPORTANT) ===\n";

        if (!empty($gameContext['is_slave'])) {
            $gameStateContext .= "*** THIS NPC IS CURRENTLY A SLAVE ***\n";
            if (!empty($gameContext['slave_owner'])) {
                $gameStateContext .= "Slave Owner: {$gameContext['slave_owner']}\n";
            }
            if (isset($gameContext['slave_affinity'])) {
                $affinity = $gameContext['slave_affinity'];
                if ($affinity < -50) {
                    $gameStateContext .= "Slave Attitude: Hateful, resentful, wants freedom\n";
                } else if ($affinity < 0) {
                    $gameStateContext .= "Slave Attitude: Reluctant, unhappy but compliant\n";
                } else if ($affinity < 50) {
                    $gameStateContext .= "Slave Attitude: Accepting, growing attached\n";
                } else {
                    $gameStateContext .= "Slave Attitude: Devoted, loves their owner\n";
                }
            }
        }

        if (!empty($gameContext['is_prostitute']) || !empty($gameContext['is_courtesan'])) {
            $gameStateContext .= "*** THIS NPC WORKS AS A PROSTITUTE ***\n";
            if (!empty($gameContext['is_courtesan'])) {
                $gameStateContext .= "Type: Courtesan/High-class escort\n";
            }
        }

        if (!empty($gameContext['relationship_with_player'])) {
            $gameStateContext .= "Relationship with Player: {$gameContext['relationship_with_player']}\n";
        }

        if (!empty($gameContext['faction_info'])) {
            $gameStateContext .= "Faction: {$gameContext['faction_info']}\n";
        }

        if (!empty($gameContext['mod_source'])) {
            $gameStateContext .= "Mod Source: {$gameContext['mod_source']}\n";
        }

        $npcContext .= $gameStateContext;

        // Load speak styles from JSONB (single source of truth)
        $styleRow = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_speak_styles'");
        $speakStylesWithDesc = "";
        if ($styleRow && !empty($styleRow['value'])) {
            $allStyles = json_decode($styleRow['value'], true) ?: [];
            foreach ($allStyles as $name => $styleData) {
                $speakStylesWithDesc .= "- {$name}: " . ($styleData['description'] ?? '') . "\n";
            }
        }

        // Load custom or default prompt template from config_manager
        require_once __DIR__ . '/config_manager.php';
        $templateRow = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_ai_prompt_template'");
        $promptTemplate = '';
        if ($templateRow && !empty($templateRow['value'])) {
            $promptTemplate = $templateRow['value'];
        } else {
            $promptTemplate = getDefaultAiPromptTemplate();
        }

        // Replace placeholders in the template
        $prompt = str_replace('{NPC_CONTEXT}', $npcContext, $promptTemplate);
        $prompt = str_replace('{SPEAK_STYLES}', $speakStylesWithDesc, $prompt);

        // Get connector
        $settingsRow = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_settings'");
        $settings = [];
        if ($settingsRow && !empty($settingsRow['value'])) {
            $settings = json_decode($settingsRow['value'], true) ?: [];
        }

        $connectorName = $settings['sex_prompt_connector'] ?? $settings['AUTO_GENERATE_CONNECTOR'] ?? null;
        if (!$connectorName) {
            // Find Grok or Mistral
            $connectors = $GLOBALS['db']->fetchAll("SELECT label FROM core_llm_connector ORDER BY label");
            foreach ($connectors as $conn) {
                $name = strtolower($conn['label']);
                if (strpos($name, 'grok') !== false || strpos($name, 'mistral') !== false) {
                    $connectorName = $conn['label'];
                    break;
                }
            }
            if (!$connectorName && !empty($connectors)) {
                $connectorName = $connectors[0]['label'];
            }
        }

        if (!$connectorName) {
            return ['success' => false, 'error' => 'No LLM connector available'];
        }

        // Use LLM connector
        require_once $GLOBALS['ENGINE_PATH'] . "lib/core/llm_connector.class.php";
        $connector = new LLMConnector();
        $connector->Load($connectorName);

        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];

        $response = $connector->sendMessages($messages);

        if (empty($response)) {
            return ['success' => false, 'error' => 'Empty LLM response'];
        }

        // Parse JSON response
        $jsonResponse = $response;
        // Clean up markdown if present
        $jsonResponse = preg_replace('/```json?\s*/i', '', $jsonResponse);
        $jsonResponse = preg_replace('/```\s*$/', '', $jsonResponse);
        $jsonResponse = trim($jsonResponse);

        $parsed = json_decode($jsonResponse, true);
        if (!$parsed || !isset($parsed['sex_prompt'])) {
            return ['success' => false, 'error' => 'Invalid JSON response from LLM'];
        }

        // Save the profile to nsfw_npc_data table (NOT core_npc_master.extended_data)
        require_once __DIR__ . '/nsfw_data.php';
        $extData = NsfwNpcData::get($npcName);

        // Update NSFW data with generated profile
        $extData['sex_prompt'] = $parsed['sex_prompt'];
        $extData['sex_speech_style'] = $parsed['speak_style'] ?? 'auto';
        $extData['profanity_level'] = $parsed['profanity_level'] ?? 3;
        $extData['kinks'] = $parsed['kinks'] ?? [];
        $extData['secret_kinks'] = $parsed['secret_kinks'] ?? [];
        $extData['is_prostitute'] = $parsed['is_prostitute'] ?? false;
        $extData['prostitute_type'] = $parsed['prostitute_type'] ?? null;
        $extData['is_slave'] = $parsed['is_slave'] ?? false;
        $extData['spousal_status'] = $parsed['spousal_status'] ?? 'single';
        $extData['spouse_names'] = $parsed['spouse_names'] ?? '';
        $extData['sexual_orientation'] = $parsed['sexual_orientation'] ?? 'heterosexual';
        $extData['relationship_preference'] = $parsed['relationship_preference'] ?? 'monogamous';
        $extData['source'] = 'auto-generated';
        $extData['nsfw_generation_completed'] = time();

        // Save to nsfw_npc_data table
        NsfwNpcData::save($npcName, $extData);

        return ['success' => true, 'profile' => $parsed];

    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Create the queue table if it doesn't exist
 * Uses profile_id for Paradox/playthrough isolation
 */
function _nsfwCreateQueueTable() {
    try {
        $GLOBALS['db']->query("
            CREATE TABLE IF NOT EXISTS nsfw_profile_queue (
                id SERIAL PRIMARY KEY,
                npc_name VARCHAR(255) NOT NULL,
                profile_id INTEGER NOT NULL DEFAULT 0,
                queue_data JSONB NOT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                retry_count INTEGER DEFAULT 0,
                last_error TEXT,
                UNIQUE(npc_name, profile_id)
            )
        ");
        // Add index for efficient playthrough-scoped queries
        $GLOBALS['db']->query("
            CREATE INDEX IF NOT EXISTS idx_nsfw_queue_profile
            ON nsfw_profile_queue(profile_id, retry_count, created_at)
        ");
        error_log("[NSFW-QUEUE] Created nsfw_profile_queue table with Paradox isolation");
    } catch (Exception $e) {
        error_log("[NSFW-QUEUE] Failed to create queue table: " . $e->getMessage());
    }
}

/**
 * Get queue status (for debugging/UI)
 * Returns status for current playthrough only
 */
function _nsfwGetQueueStatus() {
    try {
        $profileId = _nsfwGetCurrentProfileId();
        $count = $GLOBALS['db']->fetchOne(
            "SELECT COUNT(*) as cnt FROM nsfw_profile_queue WHERE profile_id = {$profileId}"
        );
        return ['pending' => intval($count['cnt'] ?? 0), 'profile_id' => $profileId];
    } catch (Exception $e) {
        return ['pending' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Check if NPC is already in queue for current playthrough
 */
function _nsfwIsInQueue($npcName) {
    try {
        $profileId = _nsfwGetCurrentProfileId();
        $escapedName = $GLOBALS['db']->escape($npcName);
        $result = $GLOBALS['db']->fetchOne(
            "SELECT id FROM nsfw_profile_queue WHERE npc_name = '{$escapedName}' AND profile_id = {$profileId}"
        );
        return !empty($result);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Clear queue for current playthrough (for testing/reset)
 */
function _nsfwClearQueue() {
    try {
        $profileId = _nsfwGetCurrentProfileId();
        $GLOBALS['db']->query("DELETE FROM nsfw_profile_queue WHERE profile_id = {$profileId}");
        error_log("[NSFW-QUEUE] Cleared queue for profile: {$profileId}");
        return true;
    } catch (Exception $e) {
        error_log("[NSFW-QUEUE] Failed to clear queue: " . $e->getMessage());
        return false;
    }
}

?>
