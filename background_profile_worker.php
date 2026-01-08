<?php
/**
 * NSFW Profile Background Worker
 * ===============================
 * Standalone process that generates NSFW profiles without interfering with gameplay.
 *
 * RUN: php background_profile_worker.php
 *
 * This runs completely independently of the game. It:
 * 1. Checks the queue for NPCs needing profiles
 * 2. Calls Grok API directly (not through game's LLM pipeline)
 * 3. Saves results to database
 * 4. Pauses if an OStim scene is active
 * 5. Sleeps between requests to avoid rate limiting
 */

// Bootstrap
$path = dirname(__FILE__) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once($path . "conf" . DIRECTORY_SEPARATOR . "conf.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "postgresql.class.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "npc_master.class.php");
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . "nsfw_data.php");

// Config
define('WORKER_SLEEP_BETWEEN_PROFILES', 3); // Seconds between API calls
define('WORKER_SLEEP_WHEN_IDLE', 10); // Seconds when queue is empty
define('WORKER_SLEEP_DURING_SCENE', 30); // Seconds to wait during OStim scene
define('WORKER_MAX_RETRIES', 3);

$GLOBALS['db'] = new sql();

echo "[NSFW Worker] Starting background profile generator...\n";
echo "[NSFW Worker] Press Ctrl+C to stop\n\n";

// Main loop
while (true) {
    try {
        // Check if OStim scene is active - if so, pause
        if (isOstimSceneActive()) {
            echo "[NSFW Worker] OStim scene active - pausing...\n";
            sleep(WORKER_SLEEP_DURING_SCENE);
            continue;
        }

        // Get next NPC from queue
        $nextNpc = getNextFromQueue();

        if (!$nextNpc) {
            // Queue empty - sleep longer
            sleep(WORKER_SLEEP_WHEN_IDLE);
            continue;
        }

        echo "[NSFW Worker] Processing: {$nextNpc['npc_name']}\n";

        // Generate profile
        $result = generateProfileForNpc($nextNpc['npc_name'], $nextNpc['queue_data']);

        if ($result['success']) {
            echo "[NSFW Worker] SUCCESS: {$nextNpc['npc_name']} - saved profile\n";
            removeFromQueue($nextNpc['id']);
        } else {
            echo "[NSFW Worker] FAILED: {$nextNpc['npc_name']} - {$result['error']}\n";
            incrementRetryCount($nextNpc['id'], $result['error']);
        }

        // Sleep between requests to avoid rate limiting
        sleep(WORKER_SLEEP_BETWEEN_PROFILES);

    } catch (Exception $e) {
        echo "[NSFW Worker] ERROR: " . $e->getMessage() . "\n";
        sleep(WORKER_SLEEP_WHEN_IDLE);
    }
}

/**
 * Check if an OStim scene is currently active
 */
function isOstimSceneActive() {
    try {
        // Check for any NPC with intimacy level > 0
        $result = $GLOBALS['db']->fetchOne("
            SELECT COUNT(*) as cnt FROM core_npc_master
            WHERE extended_data::text LIKE '%\"level\":2%'
            OR extended_data::text LIKE '%\"level\": 2%'
        ");
        return ($result['cnt'] ?? 0) > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get next NPC from the queue
 */
function getNextFromQueue() {
    try {
        // First ensure queue table exists
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

        return $GLOBALS['db']->fetchOne("
            SELECT id, npc_name, queue_data
            FROM nsfw_profile_queue
            WHERE retry_count < " . WORKER_MAX_RETRIES . "
            ORDER BY created_at ASC
            LIMIT 1
        ");
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Remove NPC from queue after successful processing
 */
function removeFromQueue($id) {
    $GLOBALS['db']->execQuery("DELETE FROM nsfw_profile_queue WHERE id = {$id}");
}

/**
 * Increment retry count on failure
 */
function incrementRetryCount($id, $error) {
    $escapedError = $GLOBALS['db']->escape($error);
    $GLOBALS['db']->execQuery("
        UPDATE nsfw_profile_queue
        SET retry_count = retry_count + 1, last_error = '{$escapedError}'
        WHERE id = {$id}
    ");
}

/**
 * Generate NSFW profile for an NPC using Grok
 */
function generateProfileForNpc($npcName, $queueDataJson) {
    $queueData = is_array($queueDataJson) ? $queueDataJson : json_decode($queueDataJson, true);
    $gameContext = $queueData['game_context'] ?? [];

    // Get NPC bio data
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
        return ['success' => false, 'error' => 'NPC not found in database'];
    }

    // Build NPC context
    $npcContext = "NPC Name: {$npcName}\n";
    if (!empty($npcBio['gender'])) $npcContext .= "Gender: {$npcBio['gender']}\n";
    if (!empty($npcBio['race'])) $npcContext .= "Race: {$npcBio['race']}\n";
    if (!empty($npcBio['core'])) $npcContext .= "Core Summary: {$npcBio['core']}\n";
    if (!empty($npcBio['personality'])) $npcContext .= "Personality: {$npcBio['personality']}\n";
    if (!empty($npcBio['occupation'])) $npcContext .= "Occupation: {$npcBio['occupation']}\n";
    if (!empty($npcBio['speechstyle'])) $npcContext .= "Speech Style: {$npcBio['speechstyle']}\n";
    if (!empty($npcBio['appearance'])) $npcContext .= "Appearance: {$npcBio['appearance']}\n";
    if (!empty($npcBio['relationships'])) $npcContext .= "Relationships: {$npcBio['relationships']}\n";
    if (!empty($npcBio['goals'])) $npcContext .= "Goals: {$npcBio['goals']}\n";
    if (!empty($npcBio['npc_static_bio'])) $npcContext .= "Biography: {$npcBio['npc_static_bio']}\n";

    // Add game state context
    $gameStateContext = "\n=== CURRENT GAME STATE ===\n";
    if (!empty($gameContext['is_slave'])) {
        $gameStateContext .= "*** THIS NPC IS A SLAVE ***\n";
        if (!empty($gameContext['slave_owner'])) {
            $gameStateContext .= "Owner: {$gameContext['slave_owner']}\n";
        }
    }
    if (!empty($gameContext['is_prostitute']) || !empty($gameContext['is_courtesan'])) {
        $gameStateContext .= "*** THIS NPC WORKS AS A PROSTITUTE ***\n";
    }
    $npcContext .= $gameStateContext;

    // Load speak styles
    $styleRow = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_speak_styles'");
    $speakStylesWithDesc = "";
    if ($styleRow && !empty($styleRow['value'])) {
        $allStyles = json_decode($styleRow['value'], true) ?: [];
        foreach ($allStyles as $name => $styleData) {
            $speakStylesWithDesc .= "- {$name}: " . ($styleData['description'] ?? '') . "\n";
        }
    }

    // Build prompt
    $prompt = <<<PROMPT
You are generating an NSFW character profile for a Skyrim NPC.

NPC CONTEXT:
{$npcContext}

Return this EXACT JSON structure:
{
  "sex_prompt": "2-4 sentences in SECOND PERSON describing intimate behavior. If SLAVE, reflect enslaved mentality. If PROSTITUTE, reflect professional approach.",
  "speak_style": "one_of_the_valid_options",
  "profanity_level": 3,
  "kinks": ["kink1", "kink2", "kink3"],
  "secret_kinks": ["secret1", "secret2", "secret3"],
  "is_slave": false,
  "slave_speak_style": null,
  "slave_obedience": null,
  "slave_resentment": null,
  "is_prostitute": false,
  "prostitute_type": null,
  "prostitute_price_modifier": null,
  "prostitute_services": null,
  "spousal_status": "single",
  "spouse_names": "",
  "sexual_orientation": "heterosexual",
  "relationship_preference": "monogamous"
}

SPEAK STYLES (pick ONE):
{$speakStylesWithDesc}

SLAVE DETECTION: Set is_slave=true if enslaved. Fill slave_speak_style, slave_obedience (1-10), slave_resentment (1-10).

PROSTITUTE DETECTION: Set is_prostitute=true if sex worker. Fill prostitute_type, prostitute_price_modifier (0.5-2.0), prostitute_services array.

Output ONLY valid JSON.
PROMPT;

    // Get Grok connector
    $connectorName = getGrokConnector();
    if (!$connectorName) {
        return ['success' => false, 'error' => 'No Grok connector found'];
    }

    // Call Grok API
    require_once $GLOBALS['ENGINE_PATH'] . "lib/core/llm_connector.class.php";
    $connector = new LLMConnector();
    $connector->Load($connectorName);

    $response = $connector->sendMessages([
        ['role' => 'user', 'content' => $prompt]
    ]);

    if (empty($response)) {
        return ['success' => false, 'error' => 'Empty response from Grok'];
    }

    // Parse JSON
    $jsonResponse = preg_replace('/```json?\s*/i', '', $response);
    $jsonResponse = preg_replace('/```\s*$/', '', $jsonResponse);
    $jsonResponse = trim($jsonResponse);

    $parsed = json_decode($jsonResponse, true);
    if (!$parsed || !isset($parsed['sex_prompt'])) {
        return ['success' => false, 'error' => 'Invalid JSON from Grok'];
    }

    // Save to database with advisory locking
    // This prevents conflicts if player is editing the same NPC in the UI
    // Uses the same advisory lock pattern as NsfwData for consistency

    $lockKey = "npc_extended_data_" . strtolower($npcName);
    $lockId = crc32($lockKey);

    // Try to acquire lock - non-blocking so we don't stall if UI has it
    $lockResult = $GLOBALS['db']->fetchOne("SELECT pg_try_advisory_lock($lockId) as locked");
    if (!$lockResult || $lockResult['locked'] !== true) {
        return ['success' => false, 'error' => 'NPC locked by UI - will retry'];
    }

    try {
        // Get current NPC data
        $escapedName = $GLOBALS['db']->escape($npcName);
        $npcRow = $GLOBALS['db']->fetchOne("
            SELECT id, extended_data FROM core_npc_master
            WHERE LOWER(npc_name) = LOWER('{$escapedName}')
        ");

        if (!$npcRow) {
            $GLOBALS['db']->execQuery("SELECT pg_advisory_unlock($lockId)");
            return ['success' => false, 'error' => 'NPC not found'];
        }

        // Get current extended_data
        $extData = json_decode($npcRow['extended_data'] ?? '{}', true) ?: [];

    // Save all fields
    $extData['sex_prompt'] = $parsed['sex_prompt'];
    $extData['sex_speech_style'] = $parsed['speak_style'] ?? 'passionate';
    $extData['nsfw_profanity_level'] = $parsed['profanity_level'] ?? 3;
    $extData['kinks'] = $parsed['kinks'] ?? [];
    $extData['secret_kinks'] = $parsed['secret_kinks'] ?? [];

    // Slave fields
    $extData['is_slave'] = $parsed['is_slave'] ?? false;
    if (!empty($parsed['is_slave'])) {
        $extData['slave_speak_style'] = $parsed['slave_speak_style'] ?? 'submissive';
        $extData['slave_obedience'] = $parsed['slave_obedience'] ?? 5;
        $extData['slave_resentment'] = $parsed['slave_resentment'] ?? 5;
    }

    // Prostitute fields
    $extData['is_prostitute'] = $parsed['is_prostitute'] ?? false;
    if (!empty($parsed['is_prostitute'])) {
        $extData['prostitute_type'] = $parsed['prostitute_type'] ?? 'streetwalker';
        $extData['prostitute_price_modifier'] = $parsed['prostitute_price_modifier'] ?? 1.0;
        $extData['prostitute_services'] = $parsed['prostitute_services'] ?? ['standard'];
    }

        $extData['spousal_status'] = $parsed['spousal_status'] ?? 'single';
        $extData['spouse_names'] = $parsed['spouse_names'] ?? '';
        $extData['sexual_orientation'] = $parsed['sexual_orientation'] ?? 'heterosexual';
        $extData['relationship_preference'] = $parsed['relationship_preference'] ?? 'monogamous';
        $extData['nsfw_source'] = 'ai-background';
        $extData['nsfw_generated_at'] = time();

        // Write directly with our lock held
        $newExtData = $GLOBALS['db']->escape(json_encode($extData));
        $npcId = $npcRow['id'];
        $GLOBALS['db']->execQuery("
            UPDATE core_npc_master
            SET extended_data = '{$newExtData}'
            WHERE id = {$npcId}
        ");

        // Release advisory lock
        $GLOBALS['db']->execQuery("SELECT pg_advisory_unlock($lockId)");

        return ['success' => true, 'profile' => $parsed];

    } catch (Exception $e) {
        // Always release lock on error
        $GLOBALS['db']->execQuery("SELECT pg_advisory_unlock($lockId)");
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Find Grok connector
 */
function getGrokConnector() {
    // Check settings first
    $settingsRow = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_settings'");
    if ($settingsRow && !empty($settingsRow['value'])) {
        $settings = json_decode($settingsRow['value'], true);
        if (!empty($settings['sex_prompt_connector'])) {
            return $settings['sex_prompt_connector'];
        }
    }

    // Find Grok or Mistral
    $connectors = $GLOBALS['db']->fetchAll("SELECT label FROM core_llm_connector ORDER BY label");
    foreach ($connectors as $conn) {
        $name = strtolower($conn['label']);
        if (strpos($name, 'grok') !== false || strpos($name, 'mistral') !== false) {
            return $conn['label'];
        }
    }

    return $connectors[0]['label'] ?? null;
}
