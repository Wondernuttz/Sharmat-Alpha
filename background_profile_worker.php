#!/usr/bin/php
<?php
// NSFW Profile Background Worker - generates queued NSFW profiles in the background.
// RUN: php background_profile_worker.php (foreground) | --daemon (detached, auto-started by context.php).
// Daemon mode forks+setsid to survive the Apache request, writes a PID file, idle-exits after WORKER_MAX_IDLE_SECONDS.

$daemon = in_array('--daemon', $argv);

// Bootstrap
$path = dirname(__FILE__) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
$enginePath = realpath($path) . '/';
$pidFile = $enginePath . 'log/nsfw_profile_worker.pid';
$logFile = $enginePath . 'log/nsfw_profile_worker.log';

// Daemonize: fork + setsid so we detach from the Apache request that spawned us
if ($daemon && function_exists('pcntl_fork')) {
    $pid = pcntl_fork();
    if ($pid < 0) { exit(1); }
    if ($pid > 0) { file_put_contents($pidFile, $pid); exit(0); }
    posix_setsid();
    fclose(STDIN); fclose(STDOUT); fclose(STDERR);
    $STDIN = fopen('/dev/null', 'r');
    $STDOUT = fopen($logFile, 'a'); if ($STDOUT === false) { $STDOUT = fopen('/dev/null', 'a'); }
    $STDERR = fopen($logFile, 'a'); if ($STDERR === false) { $STDERR = fopen('/dev/null', 'a'); }
}

// Clean up PID file on exit
register_shutdown_function(function() use ($pidFile, $daemon) { if ($daemon) { @unlink($pidFile); } });

require_once($path . "conf" . DIRECTORY_SEPARATOR . "conf.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "postgresql.class.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "npc_master.class.php");
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . "nsfw_data.php");

// Config
define('WORKER_SLEEP_BETWEEN_PROFILES', 3); // Seconds between API calls
define('WORKER_SLEEP_WHEN_IDLE', 10); // Seconds when queue is empty
define('WORKER_SLEEP_DURING_SCENE', 30); // Seconds to wait during OStim scene
define('WORKER_SCENE_ACTIVE_TIMEOUT', 300); // Stale /tmp player-scene markers stop blocking after this
define('WORKER_MAX_RETRIES', 3);
define('WORKER_MAX_IDLE_SECONDS', 30); // Build the queued profiles, then leave; respawns when the next NPC is added

$GLOBALS['db'] = new sql();

// generateProfileForNpc() require's config_manager.php from FUNCTION scope, so conf.sample.php's global $DBDRIVER
// default ("postgresql") lands as a local and never reaches $GLOBALS - config_manager.php:12 then require's
// lib/.class.php and fatals, crashing the worker on the FIRST NPC (queue never drains). Set it at global scope here.
if (empty($GLOBALS['DBDRIVER'])) { $GLOBALS['DBDRIVER'] = 'postgresql'; }

echo "[NSFW Worker] Starting background profile generator...\n";
echo "[NSFW Worker] Press Ctrl+C to stop\n\n";

$idleSince = time();

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
            // Queue empty - daemon idle-exits after a while so it dies with the server (respawns on next NPC met)
            if ($daemon && (time() - $idleSince) >= WORKER_MAX_IDLE_SECONDS) {
                @file_put_contents($logFile, date('[Y-m-d H:i:s]') . " [NSFW Worker] idle-exit after " . WORKER_MAX_IDLE_SECONDS . "s\n", FILE_APPEND);
                exit(0);
            }
            sleep(WORKER_SLEEP_WHEN_IDLE);
            continue;
        }

        $idleSince = time(); // reset idle timer on real work
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
    $sceneActivePath = sys_get_temp_dir() . "/nsfw_player_scene_active.txt";
    $sceneEndedPath = sys_get_temp_dir() . "/nsfw_scene_ended.txt";

    $sceneActiveTime = is_file($sceneActivePath) ? (int)(@file_get_contents($sceneActivePath) ?: 0) : 0;
    if ($sceneActiveTime <= 0) {
        return false;
    }

    $sceneEndedTime = is_file($sceneEndedPath) ? (int)(@file_get_contents($sceneEndedPath) ?: 0) : 0;
    if ($sceneEndedTime > 0 && $sceneEndedTime >= $sceneActiveTime) {
        return false;
    }

    $sceneActiveAge = time() - $sceneActiveTime;
    if ($sceneActiveAge < 0) {
        return true;
    }

    if ($sceneActiveAge >= WORKER_SCENE_ACTIVE_TIMEOUT) {
        return false;
    }

    return true;
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
    $allStyles = [];
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

    // Get Grok connector
    $connectorName = getGrokConnector();
    if (!$connectorName) {
        return ['success' => false, 'error' => 'No Grok connector found'];
    }

    // Call LLM via shared completion (proven curl path; config_manager.php already required above)
    $llmErr = null;
    $response = nsfwLlmComplete($prompt, $connectorName, $llmErr);
    if (empty($response)) {
        return ['success' => false, 'error' => $llmErr ?: 'Empty response from LLM'];
    }

    // Parse JSON
    $jsonResponse = preg_replace('/```json?\s*/i', '', $response);
    $jsonResponse = preg_replace('/```\s*$/', '', $jsonResponse);
    $jsonResponse = trim($jsonResponse);

    $parsed = json_decode($jsonResponse, true);
    if (!$parsed || !isset($parsed['sex_prompt'])) {
        return ['success' => false, 'error' => 'Invalid JSON from Grok'];
    }

    $parsed['speak_style'] = nsfwNormalizeGeneratedSpeakStyle($parsed['speak_style'] ?? 'auto', $allStyles, 'auto');
    if (!empty($parsed['is_slave'])) {
        $parsed['slave_speak_style'] = nsfwNormalizeGeneratedSpeakStyle($parsed['slave_speak_style'] ?? $parsed['speak_style'], $allStyles, $parsed['speak_style']);
    }

    // Save to nsfw_npc_data table (NOT core_npc_master.extended_data)
    // This prevents conflicts if player is editing the same NPC in the UI
    // Uses advisory lock pattern for consistency
    require_once __DIR__ . '/nsfw_data.php';

    $lockKey = "nsfw_npc_data_" . strtolower($npcName);
    $lockId = crc32($lockKey);

    // Try to acquire lock - non-blocking so we don't stall if UI has it
    $lockResult = $GLOBALS['db']->fetchOne("SELECT pg_try_advisory_lock($lockId) as locked");
    // Postgres returns booleans as the string 't'/'f' through this DB layer, so a strict === true NEVER matched and
    // EVERY NPC looked "locked by UI" - the queue could never drain even once the worker ran. Accept both forms.
    $lockedVal = is_array($lockResult) ? ($lockResult['locked'] ?? null) : null;
    $gotLock = ($lockedVal === true || $lockedVal === 't' || $lockedVal === 'true' || $lockedVal === '1' || $lockedVal === 1);
    if (!$gotLock) {
        return ['success' => false, 'error' => 'NPC locked by UI - will retry'];
    }

    try {
        // Get current NSFW data from nsfw_npc_data table
        $extData = NsfwNpcData::get($npcName);

        // Save all fields
        $extData['sex_prompt'] = $parsed['sex_prompt'];
        $extData['sex_speech_style'] = $parsed['speak_style'] ?? 'passionate';
        $extData['nsfw_profanity_level'] = $parsed['profanity_level'] ?? 3;
        $extData['nsfw_kinks'] = $parsed['kinks'] ?? [];
        $extData['nsfw_secret_kinks'] = $parsed['secret_kinks'] ?? [];

        // Slave fields
        $extData['is_slave'] = $parsed['is_slave'] ?? false;
        if (!empty($parsed['is_slave'])) {
            // Nested key is what the UI + scene injection read (merge, preserve other cues)
            $extData['slave_speak_styles'] = is_array($extData['slave_speak_styles'] ?? null) ? $extData['slave_speak_styles'] : [];
            $extData['slave_speak_styles']['speak_style'] = $parsed['slave_speak_style'] ?? 'submissive';
            if (!empty($parsed['slave_scene_cues']))   { $extData['slave_speak_styles']['scene_cues']   = $parsed['slave_scene_cues']; }
            if (!empty($parsed['slave_climax_positive']))     { $extData['slave_speak_styles']['slave_climax_positive']     = $parsed['slave_climax_positive']; }
            if (!empty($parsed['slave_climax_neutral'])) { $extData['slave_speak_styles']['slave_climax_neutral'] = $parsed['slave_climax_neutral']; }
            if (!empty($parsed['slave_climax_negative']))     { $extData['slave_speak_styles']['slave_climax_negative']     = $parsed['slave_climax_negative']; }
            if (!empty($parsed['slave_owner_climax'])) { $extData['slave_speak_styles']['owner_climax']  = $parsed['slave_owner_climax']; }
            if (!empty($parsed['slave_aftermath']))    { $extData['slave_speak_styles']['aftermath']     = $parsed['slave_aftermath']; }
        }

        // Prostitute fields
        $extData['is_prostitute'] = $parsed['is_prostitute'] ?? false;
        if (!empty($parsed['is_prostitute'])) {
            $extData['prostitute_type'] = $parsed['prostitute_type'] ?? 'streetwalker';
            // Nested copy is what the UI Type dropdown reads
            $extData['prostitute_pricing'] = is_array($extData['prostitute_pricing'] ?? null) ? $extData['prostitute_pricing'] : [];
            $extData['prostitute_pricing']['prostitute_type'] = $extData['prostitute_type'];
        }

        // Promiscuous mark (NOT prostitution - disposition, never charges). Mutually exclusive:
        // slave/prostitute win; a manually set mark is preserved (generation may add, never strip).
        $extData['is_slut'] = (!empty($parsed['is_slut']) || !empty($extData['is_slut']))
            && empty($extData['is_slave']) && empty($extData['is_prostitute']);

        $extData['spousal_status'] = $parsed['spousal_status'] ?? 'single';
        $extData['spouse_names'] = $parsed['spouse_names'] ?? '';
        $extData['sexual_orientation'] = $parsed['sexual_orientation'] ?? 'heterosexual';
        $extData['relationship_preference'] = $parsed['relationship_preference'] ?? 'monogamous';
        $extData['nsfw_source'] = 'ai-background';
        $extData['nsfw_generated_at'] = time();

        // Save to nsfw_npc_data table
        NsfwNpcData::save($npcName, $extData);

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
        if (!empty($settings['AUTO_GENERATE_CONNECTOR'])) {
            return $settings['AUTO_GENERATE_CONNECTOR'];
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
