<?php

// This is called at the very beginning, before any context is created
// These events are NOT fast commands - they trigger NPC dialogue responses
// Fast commands bypass normal LLM flow and don't generate dialogue

// These events trigger dialogue:
// - ext_nsfw_sexcene: Scene changes
// - ext_nsfw_npc_scene: NPC-to-NPC scenes
// - ext_nsfw_npc_invite: NPC-to-NPC invite phase
// - ext_nsfw_npc_orgasm: NPC orgasm in NPC-to-NPC scene
// - ext_nsfw_physics / ext_nsfw_physics_raw: HIGGS grabs, CBPC touches

// Only VR item events and fertility notifications stay as fast commands (silent processing)
$GLOBALS["external_fast_commands"][]="fertility_notification";
$GLOBALS["external_fast_commands"][]="ext_nsfw_devices";    // Devious Devices worn-device state (silent store)
$GLOBALS["external_fast_commands"][]="ext_nsfw_player_drink"; // player consumed a drink/potion (silent: track alcohol + roll whiskey dick)
$GLOBALS["external_fast_commands"][]="ext_nsfw_vampire";      // Papyrus reports an NPC's vampire race-keyword status (silent: store is_vampire)
$GLOBALS["external_fast_commands"][]="_speech_abort";       // Client speech interrupts must not wait behind MAIN.
// CONSENT BARK: the game fires this the instant a PLAYER scene turns explicit (tier 3). Registering it as a fast
// command lets it BYPASS the MAIN semaphore so the accept/refuse decision resolves instantly. prerequest.php then
// rewrites it to "ext_nsfw_sexcene" (after the semaphore) so it reuses the entire proven scene + consent-gate path.
$GLOBALS["external_fast_commands"][]="ext_nsfw_consent_bark";

// BLOCKED events - these should NOT hit the LLM at all
$GLOBALS["external_fast_commands"][]="nsfw_blocked_cooldown";
$GLOBALS["external_fast_commands"][]="nsfw_blocked_duplicate";
$GLOBALS["external_fast_commands"][]="nsfw_blocked_scene_ended";
$GLOBALS["external_fast_commands"][]="nsfw_blocked_policy";
$GLOBALS["external_fast_commands"][]="nsfw_blocked_blank_input";


require_once(__DIR__."/common.php");

function aiagentNsfwIsSceneActiveForPreprocess($timeoutSeconds = 300)
{
    $sceneActivePath = sys_get_temp_dir() . "/nsfw_scene_active.txt";
    $sceneEndedPath = sys_get_temp_dir() . "/nsfw_scene_ended.txt";
    $sceneActiveTime = is_file($sceneActivePath) ? (int)(file_get_contents($sceneActivePath) ?: 0) : 0;
    $sceneEndedTime  = is_file($sceneEndedPath) ? (int)(file_get_contents($sceneEndedPath) ?: 0) : 0;
    return $sceneActiveTime > 0 && (time() - $sceneActiveTime) < $timeoutSeconds && $sceneActiveTime >= $sceneEndedTime;
}

function aiagentNsfwBoolForPreprocess($value)
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return ((int)$value) !== 0;
    }
    if (is_string($value)) {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
    return false;
}

function aiagentNsfwPlayerInputSpeechTextForPreprocess($rawInput)
{
    $speech = trim((string)$rawInput);
    if ($speech === '') {
        return '';
    }

    // Strip the CHIM target suffix that remains even when STT returns no words.
    $speech = preg_replace('/\s*\((?:talking|whispering|shouting)\s+to\s+[^)]*\)\s*$/i', '', $speech);

    if (strpos($speech, ':') !== false) {
        $speech = preg_replace('/^[^:]+:\s*/', '', $speech);
        $speech = preg_replace('/\s*\((?:talking|whispering|shouting)\s+to\s+[^)]*\)\s*$/i', '', $speech);
    }

    return trim((string)$speech);
}

function aiagentNsfwIsBlankPlayerInputForPreprocess($event, $rawInput)
{
    if (!in_array((string)$event, ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s', 'narrator_inputtext', 'narrator_inputtext_s'], true)) {
        return false;
    }

    return aiagentNsfwPlayerInputSpeechTextForPreprocess($rawInput) === '';
}

function aiagentNsfwActorHasActiveSceneForPreprocess($actorName, $timeoutSeconds = 300)
{
    $timeoutSeconds = max(1, (int)$timeoutSeconds);
    if (!aiagentNsfwIsSceneActiveForPreprocess($timeoutSeconds)) {
        return false;
    }

    $actorName = trim((string)$actorName);
    if ($actorName === '' || strcasecmp($actorName, 'unknown') === 0) {
        return true;
    }
    if (empty($GLOBALS["db"]) || !class_exists('NsfwNpcData')) {
        return true;
    }

    try {
        $extended = NsfwNpcData::get($actorName);
    } catch (Exception $e) {
        error_log("[AIAGENTNSFW] Active scene actor lookup failed for {$actorName}: " . $e->getMessage());
        return true;
    }

    $intimacy = $extended["aiagent_nsfw_intimacy_data"] ?? [];
    if (!is_array($intimacy) || empty($intimacy)) {
        return false;
    }

    $sceneStart = (int)($intimacy["scene_start_time"] ?? 0);
    $lastNpcUpdate = (int)($intimacy["last_npc_scene_update_time"] ?? 0);
    $lastSceneTouch = max($sceneStart, $lastNpcUpdate);
    if ($lastSceneTouch > 0 && (time() - $lastSceneTouch) > $timeoutSeconds) {
        return false;
    }

    $phase = strtolower(trim((string)($intimacy["scene_phase"] ?? '')));
    $level = (int)($intimacy["level"] ?? 0);
    $hasSceneActors = is_array($intimacy["scene_actors"] ?? null) && !empty($intimacy["scene_actors"]);
    $hasSceneText = !empty($intimacy["current_scene_name"]) || !empty($intimacy["current_scene_desc"]);

    return $level > 0
        || aiagentNsfwBoolForPreprocess($intimacy["sex_started"] ?? false)
        || aiagentNsfwBoolForPreprocess($intimacy["had_sex_in_scene"] ?? false)
        || aiagentNsfwBoolForPreprocess($intimacy["is_active_participant"] ?? false)
        || $hasSceneActors
        || $hasSceneText
        || in_array($phase, ['affection', 'tier_prompt', 'accepted', 'engaged', 'invite', 'rejected'], true);
}

function aiagentNsfwNpcSceneStaleSecondsForPreprocess()
{
    $staleSeconds = function_exists('_getNsfwSetting') ? (int)_getNsfwSetting('NPC_SCENE_STALE_SECONDS', 330) : 330;
    return max(60, $staleSeconds);
}

function aiagentNsfwClearStaleNpcScenesForPreprocess($staleSeconds = null)
{
    static $lastCleanup = 0;
    if (empty($GLOBALS["db"])) {
        return;
    }

    $now = time();
    if (($now - $lastCleanup) < 10) {
        return;
    }
    $lastCleanup = $now;

    $staleSeconds = $staleSeconds !== null ? max(90, (int)$staleSeconds) : aiagentNsfwNpcSceneStaleSecondsForPreprocess();
    $cutoff = $now - $staleSeconds;

    try {
        $GLOBALS["db"]->execQuery("
            UPDATE nsfw_npc_data
            SET extended_data = jsonb_set(
                extended_data,
                '{aiagent_nsfw_intimacy_data}',
                COALESCE(extended_data->'aiagent_nsfw_intimacy_data', '{}'::jsonb)
                || jsonb_build_object(
                    'level', 0,
                    'sex_disposal', 10,
                    'orgasmed', false,
                    'sex_started', false,
                    'is_naked', 0,
                    'scene_phase', NULL,
                    'tier_prompt_sent', NULL,
                    'cached_tier_prompt', '',
                    'current_scene_desc', NULL,
                    'current_scene_name', NULL,
                    'current_scene_tags', NULL,
                    'accepted_sex', false,
                    'accepted_affection', false,
                    'had_sex_in_scene', false,
                    'refusal_expressed', false,
                    'forced_scene', false,
                    'request_scene_stop', false,
                    'stop_command_sent', false,
                    'last_scene_stop_time', NULL,
                    'scene_stop_retry_count', 0,
                    'last_forced_refusal_scene_key', NULL,
                    'last_refusal_speech_time', NULL,
                    'last_refusal_speech_key', NULL,
                    'refused_until_scene_end', false,
                    'scene_is_idle', NULL,
                    'scene_start_time', NULL,
                    'scene_actors', NULL,
                    'raw_scene_actor_slots', NULL,
                    'actor_roles', NULL,
                    'my_role_tags', jsonb_build_array(),
                    'current_primary_partner', NULL,
                    'is_active_participant', false,
                    'show_normal_kinks', false,
                    'show_secret_kinks', false,
                    'is_transaction', false,
                    'payment_confirmed', false,
                    'service_completed', false,
                    'is_npc_scene', false,
                    'npc_scene_partner', NULL,
                    'npc_scene_thread_id', NULL,
                    'npc_scene_id', NULL,
                    'npc_scene_player_distance', NULL,
                    'last_npc_scene_update_time', NULL,
                    'npc_affinity_gate_disabled', false,
                    'partner_affinity', NULL,
                    'partner_tier', NULL,
                    'npc_refusal_dialogue_only', false
                ),
                true
            )
            WHERE extended_data IS NOT NULL
              AND extended_data ? 'aiagent_nsfw_intimacy_data'
              AND extended_data->'aiagent_nsfw_intimacy_data'->>'is_npc_scene' = 'true'
              AND COALESCE(
                    NULLIF(extended_data->'aiagent_nsfw_intimacy_data'->>'last_npc_scene_update_time', '')::bigint,
                    NULLIF(extended_data->'aiagent_nsfw_intimacy_data'->>'scene_start_time', '')::bigint,
                    0
                  ) > 0
              AND COALESCE(
                    NULLIF(extended_data->'aiagent_nsfw_intimacy_data'->>'last_npc_scene_update_time', '')::bigint,
                    NULLIF(extended_data->'aiagent_nsfw_intimacy_data'->>'scene_start_time', '')::bigint,
                    0
                  ) < {$cutoff}
        ");
    } catch (Exception $e) {
        error_log("[AIAGENTNSFW] Stale NPC scene preprocessing cleanup failed: " . $e->getMessage());
    }
}

function aiagentNsfwNormalizeSceneActorName($name)
{
    $name = trim((string)$name);
    if ($name === '') {
        return '';
    }

    $name = preg_replace('/^\(?\s*beings in range:\s*/iu', '', $name);
    if (function_exists('stripActorStateSuffix')) {
        $name = stripActorStateSuffix($name);
    } else {
        $name = preg_replace('/\s*\((?:far away|too far away|busy|hostile|in combat|dead|disabled|unavailable)\)\s*$/iu', '', $name);
    }

    return strtolower(trim((string)$name));
}

function aiagentNsfwActiveSceneParticipantMap()
{
    static $participantMap = null;
    if ($participantMap !== null) {
        return $participantMap;
    }

    $participantMap = [];
    if (empty($GLOBALS["db"])) {
        return $participantMap;
    }

    try {
        aiagentNsfwClearStaleNpcScenesForPreprocess();
        $rows = $GLOBALS["db"]->fetchAll("
            SELECT npc_name
            FROM nsfw_npc_data
            WHERE extended_data IS NOT NULL
              AND extended_data ? 'aiagent_nsfw_intimacy_data'
              AND (
                  extended_data->'aiagent_nsfw_intimacy_data'->>'is_active_participant' = 'true'
                  OR jsonb_typeof(extended_data->'aiagent_nsfw_intimacy_data'->'scene_actors') = 'array'
              )
        ");
        foreach ($rows as $row) {
            $normalized = aiagentNsfwNormalizeSceneActorName($row['npc_name'] ?? '');
            if ($normalized !== '') {
                $participantMap[$normalized] = true;
            }
        }
    } catch (Exception $e) {
        error_log("[AIAGENTNSFW] Active scene participant lookup failed: " . $e->getMessage());
    }

    return $participantMap;
}

// Recently-touched (non-blocked) NPCs from the physics contact ledger, most recent first. During physical
// contact the DLL's spatial snapshot is provably wrong (partners stamped "far away" at arm's length), and
// contact encounters never stamp the scene marker - so contact is its own presence signal (fix 2026-07-01).
function aiagentNsfwRecentContactActorsForPreprocess($windowSeconds = null)
{
    @require_once __DIR__ . "/contact_state.php";
    if (!function_exists('aiagentNsfwContactLoadLedger')) { return []; }
    if ($windowSeconds === null) { $windowSeconds = (int)_getNsfwSetting('CONTACT_INPUT_REDIRECT_SECONDS', 45); }
    $windowSeconds = max(5, (int)$windowSeconds);
    $now = time();
    $actors = [];
    foreach (aiagentNsfwContactLoadLedger() as $row) {
        if (!is_array($row) || !empty($row['isBlocked'])) { continue; }
        $name = trim((string)($row['actorName'] ?? ''));
        $ts = (int)($row['updated_at'] ?? 0);
        if ($name === '' || $ts <= 0 || ($now - $ts) > $windowSeconds) { continue; }
        if (!isset($actors[$name]) || $ts > $actors[$name]) { $actors[$name] = $ts; }
    }
    arsort($actors);
    return $actors;
}

function aiagentNsfwSanitizeSceneParticipantFarAway($payload)
{
    $payload = (string)$payload;
    if (stripos($payload, 'far away') === false) {
        return $payload;
    }

    $participantMap = aiagentNsfwIsSceneActiveForPreprocess() ? aiagentNsfwActiveSceneParticipantMap() : [];
    // CONTACT-AWARE (fix 2026-07-01): log-proven that partners get tagged "(far away)" mid-contact with no
    // scene marker -> rechat found no responder and narrator hijacked the reply. Touched NPCs are present.
    foreach (aiagentNsfwRecentContactActorsForPreprocess() as $contactName => $contactTs) {
        $contactKey = aiagentNsfwNormalizeSceneActorName($contactName);
        if ($contactKey !== '') { $participantMap[$contactKey] = $contactName; }
    }
    if (empty($participantMap)) {
        return $payload;
    }

    $parts = preg_split('/([\/|]+)/u', $payload, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts) || empty($parts)) {
        return $payload;
    }

    $changed = false;
    foreach ($parts as $idx => $part) {
        if ($idx % 2 === 1 || stripos($part, 'far away') === false) {
            continue;
        }

        $normalized = aiagentNsfwNormalizeSceneActorName($part);
        if ($normalized !== '' && isset($participantMap[$normalized])) {
            $parts[$idx] = preg_replace('/\s*\((?:far away|too far away)\)\s*/iu', '', $part);
            $changed = true;
        }
    }

    return $changed ? implode('', $parts) : $payload;
}

function aiagentNsfwPlayerInterruptPath()
{
    return sys_get_temp_dir() . "/nsfw_player_interrupt.json";
}

function aiagentNsfwMarkPlayerInterruptForPreprocess($eventName)
{
    $payload = [
        'time' => time(),
        'event' => (string)$eventName,
        'ts' => (float)($GLOBALS["gameRequest"][1] ?? 0),
        'gamets' => (float)($GLOBALS["gameRequest"][2] ?? 0),
    ];
    @file_put_contents(aiagentNsfwPlayerInterruptPath(), json_encode($payload));
}

function aiagentNsfwHasRecentPlayerInterruptForRequest($requestTs, $suppressSeconds)
{
    $suppressSeconds = max(1, (int)$suppressSeconds);
    $backlogSeconds = max(60, $suppressSeconds);
    $marker = json_decode((string)@file_get_contents(aiagentNsfwPlayerInterruptPath()), true);
    if (is_array($marker)) {
        $markerTime = (int)($marker['time'] ?? 0);
        $markerTs = (float)($marker['ts'] ?? 0);
        $age = $markerTime > 0 ? time() - $markerTime : PHP_INT_MAX;
        $queuedBeforeInterrupt = $requestTs > 0 && $markerTs > 0 && $requestTs <= $markerTs && $age <= $backlogSeconds;
        $insideInterruptWindow = $age <= $suppressSeconds;
        if ($queuedBeforeInterrupt || $insideInterruptWindow) {
            return true;
        }
    }

    if (!empty($GLOBALS["db"])) {
        try {
            $requestTsSql = (float)$requestTs;
            $recentCutoff = time() - $backlogSeconds;
            $row = $GLOBALS["db"]->fetchOne(
                "SELECT rowid
                 FROM eventlog
                 WHERE type='user_input'
                   AND localts >= {$recentCutoff}
                   AND ({$requestTsSql} <= 0 OR ts > {$requestTsSql})
                 ORDER BY rowid DESC
                 LIMIT 1"
            );
            return !empty($row);
        } catch (Exception $e) {
            error_log("[AIAGENTNSFW] Player interrupt lookup failed: " . $e->getMessage());
        }
    }

    return false;
}

function aiagentNsfwCleanNpcSceneCadenceActorForPreprocess($actor)
{
    $actor = trim((string)$actor);
    $actor = preg_replace('/^\(Context[^)]*\)\s*/u', '', $actor);
    if (strpos($actor, ':') !== false) {
        $actor = trim(substr($actor, strrpos($actor, ':') + 1));
    }
    return trim($actor);
}

function aiagentNsfwNpcSceneActorKeyForPreprocess($actor)
{
    $actor = aiagentNsfwCleanNpcSceneCadenceActorForPreprocess($actor);
    if ($actor === '') {
        return '';
    }
    return function_exists('aiagentNsfwNormalizeSceneActorName')
        ? aiagentNsfwNormalizeSceneActorName($actor)
        : strtolower($actor);
}

function aiagentNsfwNpcSceneActorByKeyForPreprocess($actors, $actorKey)
{
    $actorKey = trim((string)$actorKey);
    if ($actorKey === '' || !is_array($actors)) {
        return '';
    }

    foreach ($actors as $actor) {
        if (aiagentNsfwNpcSceneActorKeyForPreprocess($actor) === $actorKey) {
            return aiagentNsfwCleanNpcSceneCadenceActorForPreprocess($actor);
        }
    }

    return '';
}

function aiagentNsfwNpcScenePartnerForSpeakerForPreprocess($speaker, $actors)
{
    $speakerKey = aiagentNsfwNpcSceneActorKeyForPreprocess($speaker);
    if ($speakerKey === '' || !is_array($actors)) {
        return '';
    }

    foreach ($actors as $actor) {
        if (aiagentNsfwNpcSceneActorKeyForPreprocess($actor) !== $speakerKey) {
            return aiagentNsfwCleanNpcSceneCadenceActorForPreprocess($actor);
        }
    }

    return '';
}

function aiagentNsfwNpcScenePickSpeakerForPreprocess($actors, $lastSpeakerKey = '')
{
    if (!is_array($actors) || empty($actors)) {
        return '';
    }

    foreach ($actors as $actor) {
        $actor = aiagentNsfwCleanNpcSceneCadenceActorForPreprocess($actor);
        if ($actor === '') {
            continue;
        }
        if ($lastSpeakerKey === '' || aiagentNsfwNpcSceneActorKeyForPreprocess($actor) !== $lastSpeakerKey) {
            return $actor;
        }
    }

    return aiagentNsfwCleanNpcSceneCadenceActorForPreprocess($actors[0] ?? '');
}

function aiagentNsfwStripChimPayloadPrefixForPreprocess($payload)
{
    $payload = trim((string)$payload);
    if ($payload === '') {
        return '';
    }
    if (($cp = strpos($payload, ')')) !== false) {
        $afterParen = substr($payload, $cp + 1);
        if (($col = strpos($afterParen, ':')) !== false) {
            return trim(substr($afterParen, $col + 1));
        }
    }
    return $payload;
}

function aiagentNsfwDecodeNpcSceneActorsForPreprocess($payload)
{
    $payload = trim((string)$payload);
    if ($payload === '' || preg_match('/^(?:dist|distance|roles)=/i', $payload)) {
        return [];
    }
    if (stripos($payload, 'actors=') === 0) {
        $payload = substr($payload, 7);
    }
    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        return [];
    }
    $json = json_decode($decoded, true);
    if (!is_array($json)) {
        return [];
    }
    $actors = [];
    foreach ($json as $actor) {
        $actor = aiagentNsfwCleanNpcSceneCadenceActorForPreprocess($actor);
        if ($actor === '' || (function_exists('nsfwIsNarratorName') && nsfwIsNarratorName($actor))) {
            continue;
        }
        $actors[] = $actor;
    }
    return array_values(array_unique($actors));
}

function aiagentNsfwExtractDistanceForPreprocess($fields)
{
    foreach ($fields as $field) {
        $field = trim((string)$field);
        if (preg_match('/^(?:dist|distance)=(-?\d+(?:\.\d+)?)$/i', $field, $m)) {
            return (float)$m[1];
        }
    }
    return null;
}

function aiagentNsfwNormalizePhysicsBodyPartForPreprocess($bodyPart)
{
    $key = strtolower(trim((string)$bodyPart));
    if ($key === '') {
        return 'body';
    }
    switch ($key) {
        case 'breast':
        case 'boob':
        case 'boobs':
            return 'breasts';
        case 'ass':
        case 'rear':
        case 'bottom':
            return 'butt';
        case 'vagina':
        case 'vaginal':
        case 'pelvis':
            return 'pussy';
        case 'anus':
            return 'anal';
        case 'cock':
        case 'genitals':
        case 'crotch':
            return 'penis';
        case 'hair':
        case 'scalp':
            return 'head';
        default:
            return $key;
    }
}

function aiagentNsfwPhysicsReleasePathForPreprocess($actorName, $bodyPart = '')
{
    $key = strtolower(trim((string)$actorName));
    if ($key === '') {
        $key = 'unknown';
    }
    $bodyKey = aiagentNsfwNormalizePhysicsBodyPartForPreprocess($bodyPart);
    return sys_get_temp_dir() . "/nsfw_physics_recent_release_" . md5($key . '|' . $bodyKey) . ".txt";
}

function aiagentNsfwPhysicsActorReleasePathForPreprocess($actorName)
{
    $key = strtolower(trim((string)$actorName));
    if ($key === '') {
        $key = 'unknown';
    }
    return sys_get_temp_dir() . "/nsfw_physics_recent_release_" . md5($key) . ".txt";
}

function aiagentNsfwMarkPhysicsReleaseForPreprocess($actorName, $bodyPart = '')
{
    $now = time();
    @file_put_contents(aiagentNsfwPhysicsReleasePathForPreprocess($actorName, $bodyPart), $now);
    @file_put_contents(aiagentNsfwPhysicsActorReleasePathForPreprocess($actorName), $now);
}

function aiagentNsfwHasRecentPhysicsReleaseForPreprocess($actorName, $seconds = 2, $bodyPart = '')
{
    $seconds = max(1, (int)$seconds);
    $bodyKey = aiagentNsfwNormalizePhysicsBodyPartForPreprocess($bodyPart);
    $lastRelease = @file_get_contents(aiagentNsfwPhysicsReleasePathForPreprocess($actorName, $bodyKey));
    if ($lastRelease !== false && (time() - (int)$lastRelease) < $seconds) {
        return true;
    }

    // Do not let a recent arm/leg/head release eat a high-confidence sensitive touch.
    if (in_array($bodyKey, ['breasts', 'butt', 'pussy', 'anal', 'penis'], true)) {
        return false;
    }

    $lastActorRelease = @file_get_contents(aiagentNsfwPhysicsActorReleasePathForPreprocess($actorName));
    return $lastActorRelease !== false && (time() - (int)$lastActorRelease) < $seconds;
}

function aiagentNsfwParseNpcSceneCadencePayloadForPreprocess($payload)
{
    $rawPayload = aiagentNsfwStripChimPayloadPrefixForPreprocess($payload);
    $parts = explode('^', $rawPayload);
    if (count($parts) < 4) {
        return null;
    }

    $actors = [];
    foreach ([$parts[0] ?? '', $parts[1] ?? ''] as $actor) {
        $actor = aiagentNsfwCleanNpcSceneCadenceActorForPreprocess($actor);
        if ($actor !== '' && !(function_exists('nsfwIsNarratorName') && nsfwIsNarratorName($actor))) {
            $actors[] = $actor;
        }
    }
    for ($i = 6; $i < count($parts); $i++) {
        $decodedActors = aiagentNsfwDecodeNpcSceneActorsForPreprocess($parts[$i]);
        if (!empty($decodedActors)) {
            $actors = array_merge($actors, $decodedActors);
        }
    }
    $actors = array_values(array_unique(array_filter($actors, function ($actor) {
        return aiagentNsfwCleanNpcSceneCadenceActorForPreprocess($actor) !== '';
    })));

    $thread = preg_replace('/[^0-9-]/', '', (string)($parts[2] ?? 'unknown'));
    if ($thread === '') {
        $thread = 'unknown';
    }

    return [
        'event' => 'ext_nsfw_npc_scene',
        'thread' => $thread,
        'scene' => trim((string)($parts[3] ?? '')),
        'actors' => $actors,
        'distance' => aiagentNsfwExtractDistanceForPreprocess(array_slice($parts, 6)),
    ];
}

function aiagentNsfwParsePlayerlessSexSceneCadencePayloadForPreprocess($payload)
{
    $parts = explode('/', (string)$payload);
    if (count($parts) < 5) {
        return null;
    }

    $playerName = trim((string)($GLOBALS['PLAYER_NAME'] ?? 'Player'));
    $sceneName = trim((string)($parts[0] ?? ''));
    $stageName = trim((string)($parts[2] ?? $sceneName));
    $actorInfos = array_slice($parts, 3);
    $actors = [];
    $distance = null;
    foreach ($actorInfos as $actorInfo) {
        $actorFields = explode('^', (string)$actorInfo);
        $actorName = aiagentNsfwCleanNpcSceneCadenceActorForPreprocess($actorFields[0] ?? '');
        if ($actorName === '' || (function_exists('nsfwIsNarratorName') && nsfwIsNarratorName($actorName))) {
            continue;
        }
        if ($playerName !== '' && strcasecmp($actorName, $playerName) === 0) {
            return null;
        }
        $actors[] = $actorName;
        $actorDistance = aiagentNsfwExtractDistanceForPreprocess(array_slice($actorFields, 1));
        if ($actorDistance !== null && ($distance === null || $actorDistance < $distance)) {
            $distance = $actorDistance;
        }
    }
    $actors = array_values(array_unique($actors));
    if (count($actors) < 2) {
        return null;
    }

    return [
        'event' => 'ext_nsfw_sexcene',
        'thread' => (string)abs(crc32(strtolower($sceneName . '|' . $stageName . '|' . implode('|', $actors)))),
        'scene' => $stageName !== '' ? $stageName : $sceneName,
        'actors' => $actors,
        'distance' => $distance,
    ];
}

function aiagentNsfwNpcSceneCadenceDecisionForPreprocess($meta)
{
    $now = time();
    $thread = (string)($meta['thread'] ?? 'unknown');
    $actors = is_array($meta['actors'] ?? null) ? $meta['actors'] : [];
    $distance = isset($meta['distance']) && is_numeric($meta['distance']) ? (float)$meta['distance'] : null;

    $globalCooldown = max(1, (int)_getNsfwSetting('NPC_SCENE_GLOBAL_COOLDOWN_SECONDS', 25));
    $threadCooldown = max(1, (int)_getNsfwSetting('NPC_SCENE_THREAD_COOLDOWN_SECONDS', 60));
    $actorCooldown = max(1, (int)_getNsfwSetting('NPC_SCENE_ACTOR_COOLDOWN_SECONDS', 75));
    $candidateWindow = max(45, $globalCooldown * 2, $threadCooldown);
    $distanceMargin = max(0, (float)_getNsfwSetting('NPC_SCENE_DISTANCE_PRIORITY_MARGIN', 96));

    $statePath = sys_get_temp_dir() . "/nsfw_npc_scene_scheduler.json";
    $candidatePath = sys_get_temp_dir() . "/nsfw_npc_scene_candidates.json";
    $lockPath = sys_get_temp_dir() . "/nsfw_npc_scene_scheduler.lock";
    $lock = @fopen($lockPath, 'c');
    if ($lock) {
        @flock($lock, LOCK_EX);
    }

    $state = json_decode((string)@file_get_contents($statePath), true);
    if (!is_array($state)) {
        $state = [];
    }
    $state['last_global_time'] = (int)($state['last_global_time'] ?? 0);
    $state['last_thread_times'] = is_array($state['last_thread_times'] ?? null) ? $state['last_thread_times'] : [];
    $state['last_actor_times'] = is_array($state['last_actor_times'] ?? null) ? $state['last_actor_times'] : [];
    $state['last_speaker_by_thread'] = is_array($state['last_speaker_by_thread'] ?? null) ? $state['last_speaker_by_thread'] : [];
    $state['reply_by_thread'] = is_array($state['reply_by_thread'] ?? null) ? $state['reply_by_thread'] : [];

    foreach ($state['last_thread_times'] as $storedThread => $lastTime) {
        if (($now - (int)$lastTime) > max(300, $threadCooldown * 4)) {
            unset($state['last_thread_times'][$storedThread]);
        }
    }
    foreach ($state['last_actor_times'] as $actorKey => $lastTime) {
        if (($now - (int)$lastTime) > max(300, $actorCooldown * 4)) {
            unset($state['last_actor_times'][$actorKey]);
        }
    }
    foreach ($state['reply_by_thread'] as $replyThread => $replyState) {
        if (($now - (int)($replyState['time'] ?? 0)) > max(120, $threadCooldown * 2)) {
            unset($state['reply_by_thread'][$replyThread]);
        }
    }

    $candidates = json_decode((string)@file_get_contents($candidatePath), true);
    if (!is_array($candidates)) {
        $candidates = [];
    }
    foreach ($candidates as $candidateThread => $candidate) {
        if (($now - (int)($candidate['time'] ?? 0)) > $candidateWindow) {
            unset($candidates[$candidateThread]);
        }
    }
    $candidates[$thread] = [
        'time' => $now,
        'distance' => $distance,
        'actors' => $actors,
        'scene' => (string)($meta['scene'] ?? ''),
    ];

    $actorReady = function ($candidateActor) use ($state, $actorCooldown, $now) {
        $actorKey = aiagentNsfwNpcSceneActorKeyForPreprocess($candidateActor);
        if ($actorKey === '') {
            return true;
        }
        $lastActorTime = (int)($state['last_actor_times'][$actorKey] ?? 0);
        return !($lastActorTime > 0 && ($now - $lastActorTime) < $actorCooldown);
    };
    $threadReady = function ($candidateThread, $candidateActors) use ($state, $threadCooldown, $actorReady, $now) {
        $replyState = is_array($state['reply_by_thread'][$candidateThread] ?? null) ? $state['reply_by_thread'][$candidateThread] : null;
        if ($replyState) {
            $replyActor = aiagentNsfwNpcSceneActorByKeyForPreprocess($candidateActors, $replyState['actor_key'] ?? '');
            return $replyActor !== '' && $actorReady($replyActor);
        }

        $lastThreadTime = (int)($state['last_thread_times'][$candidateThread] ?? 0);
        if ($lastThreadTime > 0 && ($now - $lastThreadTime) < $threadCooldown) {
            return false;
        }
        $candidateSpeaker = aiagentNsfwNpcScenePickSpeakerForPreprocess(
            $candidateActors,
            (string)($state['last_speaker_by_thread'][$candidateThread] ?? '')
        );
        return $candidateSpeaker !== '' && $actorReady($candidateSpeaker);
    };

    $decision = ['allow' => true, 'reason' => 'allowed'];
    if ($state['last_global_time'] > 0 && ($now - $state['last_global_time']) < $globalCooldown) {
        $decision = ['allow' => false, 'reason' => 'global cooldown ' . $globalCooldown . 's'];
    } else if (!$threadReady($thread, $actors)) {
        $decision = ['allow' => false, 'reason' => 'thread/actor cooldown'];
    } else if ($distance !== null) {
        $bestThread = null;
        $bestDistance = null;
        foreach ($candidates as $candidateThread => $candidate) {
            $candidateDistance = $candidate['distance'] ?? null;
            if ($candidateDistance === null || !is_numeric($candidateDistance)) {
                continue;
            }
            $candidateActors = is_array($candidate['actors'] ?? null) ? $candidate['actors'] : [];
            if (!$threadReady($candidateThread, $candidateActors)) {
                continue;
            }
            $candidateDistance = (float)$candidateDistance;
            if ($bestDistance === null || $candidateDistance < $bestDistance) {
                $bestDistance = $candidateDistance;
                $bestThread = (string)$candidateThread;
            }
        }
        if ($bestThread !== null && $bestThread !== $thread && $distance > ($bestDistance + $distanceMargin)) {
            $decision = [
                'allow' => false,
                'reason' => 'distance priority thread ' . $bestThread . ' at ' . round($bestDistance) . ' over ' . round($distance),
            ];
        }
    }

    if (!empty($decision['allow'])) {
        $replyState = is_array($state['reply_by_thread'][$thread] ?? null) ? $state['reply_by_thread'][$thread] : null;
        $selectedSpeaker = '';
        $selectedPartner = '';
        $completedPairTurn = false;

        if ($replyState) {
            $selectedSpeaker = aiagentNsfwNpcSceneActorByKeyForPreprocess($actors, $replyState['actor_key'] ?? '');
            $selectedPartner = aiagentNsfwNpcSceneActorByKeyForPreprocess($actors, $replyState['reply_to_key'] ?? '');
            $completedPairTurn = true;
            unset($state['reply_by_thread'][$thread]);
        } else {
            $selectedSpeaker = aiagentNsfwNpcScenePickSpeakerForPreprocess(
                $actors,
                (string)($state['last_speaker_by_thread'][$thread] ?? '')
            );
            $selectedPartner = aiagentNsfwNpcScenePartnerForSpeakerForPreprocess($selectedSpeaker, $actors);
            if ($selectedPartner !== '') {
                $state['reply_by_thread'][$thread] = [
                    'actor_key' => aiagentNsfwNpcSceneActorKeyForPreprocess($selectedPartner),
                    'reply_to_key' => aiagentNsfwNpcSceneActorKeyForPreprocess($selectedSpeaker),
                    'time' => $now,
                ];
            } else {
                $completedPairTurn = true;
            }
        }

        $state['last_global_time'] = $now;
        if ($completedPairTurn) {
            $state['last_thread_times'][$thread] = $now;
        }
        $selectedSpeakerKey = aiagentNsfwNpcSceneActorKeyForPreprocess($selectedSpeaker);
        if ($selectedSpeakerKey !== '') {
            $state['last_actor_times'][$selectedSpeakerKey] = $now;
            $state['last_speaker_by_thread'][$thread] = $selectedSpeakerKey;
            $decision['speaker'] = $selectedSpeaker;
            $decision['partner'] = $selectedPartner;
        }
    }

    @file_put_contents($statePath, json_encode($state));
    @file_put_contents($candidatePath, json_encode($candidates));
    if ($lock) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }

    $decision['thread'] = $thread;
    $decision['distance'] = $distance;
    return $decision;
}


if (isset($GLOBALS["gameRequest"])) {
    $currentEvent = $GLOBALS["gameRequest"][0] ?? '';
    $currentActor = $GLOBALS["HERIKA_NAME"] ?? 'unknown';
    if (aiagentNsfwIsBlankPlayerInputForPreprocess($currentEvent, $GLOBALS["gameRequest"][3] ?? '')) {
        $GLOBALS["gameRequest"][0] = "nsfw_blocked_blank_input";
        $currentEvent = "nsfw_blocked_blank_input";
        error_log("[AIAGENTNSFW] Blocked blank player input after STT returned no speech text");
    }
    if (in_array($currentEvent, ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s', 'narrator_inputtext'], true)) {
        aiagentNsfwMarkPlayerInterruptForPreprocess($currentEvent);
    }
    $legacySceneSpeakEvents = ['chatnf_sl', 'chatnf_sl_nr', 'chatnf_sl_moan', 'chatnf_sl_climax', 'chatnf_sl_naked'];
    $legacySceneSpeakPolicy = strtolower((string)_getNsfwSetting('LEGACY_SCENE_SPEAK_POLICY', 'authoritative'));
    if (!in_array($legacySceneSpeakPolicy, ['authoritative', 'block_all', 'allow'], true)) {
        $legacySceneSpeakPolicy = 'authoritative';
    }
    $eventAuditEnabled = _getNsfwSetting('NSFW_EVENT_AUDIT_LOG', true);
    if ($eventAuditEnabled && (strpos($currentEvent, 'ext_nsfw') === 0 || strpos($currentEvent, 'chatnf_sl') === 0 || $currentEvent === 'info_sexscene')) {
        $auditPayload = preg_replace('/\s+/', ' ', (string)($GLOBALS["gameRequest"][3] ?? ''));
        if (strlen($auditPayload) > 500) {
            $auditPayload = substr($auditPayload, 0, 500) . '...';
        }
        error_log("[AIAGENTNSFW] Event received: {$currentEvent}; policy={$legacySceneSpeakPolicy}; profile=" . ($_GET["profile"] ?? '') . "; actor={$currentActor}; payload={$auditPayload}");
    }

    // VR/OStim can move the player camera/spectator body away from the physical actors.
    // During an active SHARMAT scene, keep scene participants eligible for dialogue routing.
    if ($currentEvent === 'infonpc_close') {
        $originalClosePayload = (string)($GLOBALS["gameRequest"][3] ?? '');
        $sanitizedClosePayload = aiagentNsfwSanitizeSceneParticipantFarAway($originalClosePayload);
        if ($sanitizedClosePayload !== $originalClosePayload) {
            $GLOBALS["gameRequest"][3] = $sanitizedClosePayload;
            error_log("[AIAGENTNSFW] Stripped false far-away status for active scene participant: {$originalClosePayload} => {$sanitizedClosePayload}");
        }
    }

    // SCENE INPUT REDIRECT: during an active sex scene, player input addressed to The Narrator must go
    // to the scene NPC instead (the narrator never belongs in a scene). This runs BEFORE main.php's
    // narrator decision (main.php:309), so rewriting the event + profile reroutes the response to the NPC.
    if ($currentEvent === 'narrator_inputtext' && _getNsfwSetting('SCENE_INPUT_REDIRECT', true)) {
        $sceneActiveTime = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt") ?: 0);
        $sceneEndedTime  = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt") ?: 0);
        $redirectTimeout = _getNsfwSetting('SCENE_INPUT_REDIRECT_TIMEOUT', 300);
        if ($sceneActiveTime > 0 && (time() - $sceneActiveTime) < $redirectTimeout && $sceneActiveTime >= $sceneEndedTime) {
            try {
                // Most-recently-active scene participant (handles group scenes too - any NPC, never the narrator)
                $row = $GLOBALS["db"]->fetchOne(
                    "SELECT npc_name FROM nsfw_npc_data
                     WHERE extended_data->'aiagent_nsfw_intimacy_data'->>'is_active_participant' = 'true'
                     ORDER BY (extended_data->'aiagent_nsfw_intimacy_data'->>'gamets')::float DESC NULLS LAST
                     LIMIT 1"
                );
                $scenePartner = $row['npc_name'] ?? '';
                if ($scenePartner !== '' && !nsfwIsNarratorName($scenePartner)) {
                    $GLOBALS["gameRequest"][0] = "inputtext";
                    $currentEvent = "inputtext";
                    $_GET["profile"] = md5($scenePartner);
                    error_log("[AIAGENTNSFW] Scene active - redirected narrator input to scene partner: {$scenePartner}");
                }
            } catch (Exception $e) {
                error_log("[AIAGENTNSFW] Scene input redirect query failed: " . $e->getMessage());
            }
        } else {
            // CONTACT REDIRECT (fix 2026-07-01): all 4 logged narrator hijacks (e.g. 07-01 06:26) happened during
            // intimate physics contact with NO scene marker, so the scene branch above never fired (0 hits ever).
            // A recently-touched NPC is inches away no matter what the DLL's broken mid-contact snapshot claims -
            // the player's words belong to them, not the Narrator.
            $contactPartner = '';
            foreach (aiagentNsfwRecentContactActorsForPreprocess() as $contactName => $contactTs) { $contactPartner = (string)$contactName; break; }
            if ($contactPartner !== '' && !nsfwIsNarratorName($contactPartner)) {
                $GLOBALS["gameRequest"][0] = "inputtext";
                $currentEvent = "inputtext";
                $_GET["profile"] = md5($contactPartner);
                error_log("[AIAGENTNSFW] Recent intimate contact - redirected narrator input to touched NPC: {$contactPartner}");
            }
        }
    }

    // Devious Devices: silently store the actor's current worn-device list (payload "ActorName^csv")
    if ($currentEvent === "ext_nsfw_devices") {
        $ddParts = explode("^", $GLOBALS["gameRequest"][3] ?? '');
        $ddActor = trim($ddParts[0] ?? '');
        if ($ddActor !== '') {
            require_once __DIR__ . "/nsfw_data.php";
            $ddData = NsfwNpcData::get($ddActor);
            $ddData["aiagent_nsfw_devices"] = trim($ddParts[1] ?? '');
            NsfwNpcData::save($ddActor, $ddData);
            error_log("[AIAGENTNSFW] Stored devices for {$ddActor}: " . $ddData["aiagent_nsfw_devices"]);
        }
    }

    // WHISKEY DICK: the player consumed a drink/potion (payload "PLAYER_DRINK^<item name>"). Record it (alcohol only)
    // and roll whiskey dick on the drink itself - the player's own drinking was never tracked before, which is why it
    // never fired. Silent fast event: no LLM turn.
    if ($currentEvent === "ext_nsfw_player_drink") {
        $pdRaw = (string)($GLOBALS["gameRequest"][3] ?? '');
        $pdItem = trim(substr($pdRaw, strpos($pdRaw, "^") !== false ? strpos($pdRaw, "^") + 1 : 0));
        if ($pdItem !== '' && function_exists('aiagentNsfwRecordPlayerDrink')) {
            aiagentNsfwRecordPlayerDrink($pdItem);
        }
    }

    // VAMPIRE FLAG: Papyrus reports an NPC's vampire status via race-keyword (payload "VAMPIRE^<npc name>^<1|0>").
    // Skyrim collapses the race to base ("Nord"), so the server can't detect vampires itself - this is the only
    // reliable signal. Persist it onto the NPC profile so the DrinkBloodSex gate can read it. Silent: no LLM turn.
    if ($currentEvent === "ext_nsfw_vampire") {
        $vRaw = (string)($GLOBALS["gameRequest"][3] ?? '');
        $vParts = explode("^", $vRaw);   // [0]=VAMPIRE, [1]=npc name, [2]=1|0
        $vName = isset($vParts[1]) ? trim($vParts[1]) : '';
        $vFlag = isset($vParts[2]) ? (trim($vParts[2]) === '1') : false;
        if ($vName !== '' && function_exists('aiagentNsfwSetVampireFlag')) {
            aiagentNsfwSetVampireFlag($vName, $vFlag);
        }
    }

    // BACKLOG FIX — Scene end early detection.
    // preprocessing.php runs BEFORE the semaphore (main.php:181 vs semaphore at main.php:221).
    // When chatnf_sl_end arrives, chatnf_sl events from the scene are already PAST preprocessing
    // and waiting for the semaphore. By setting pillow_talk_pending=true in the DB here (before
    // the semaphore), the existing prerequest.php:139-188 pillow_talk system fires for those
    // stale events when they acquire the semaphore — converting moaning to post-scene dialogue.
    if ($currentEvent === 'chatnf_sl_end') {
        // NPC-END GUARD (fix 2026-07-01): chatnf_sl_end fires for NPC-to-NPC scene ends too (SexLab always;
        // OStim thread/subthread ends) - the old "ALWAYS a player-scene end" premise was false. Only a scoring
        // roster containing the PLAYER may stamp the player/global ended markers or trip the kill switch,
        // otherwise an NPC-NPC scene ending mid-player-scene cancels the player's scene state.
        $sceneEndedTime = time();
        $sceneEndScoring = (string)($GLOBALS["gameRequest"][3] ?? '');
        $sceneEndPlayerNm = (string)($GLOBALS["PLAYER_NAME"] ?? '');
        $sceneEndHasPlayer = ($sceneEndPlayerNm === '') || ($sceneEndScoring === '') || (stripos($sceneEndScoring, $sceneEndPlayerNm) !== false);
        if ($sceneEndHasPlayer) {
            @file_put_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt", $sceneEndedTime);
            @file_put_contents(sys_get_temp_dir() . "/nsfw_player_scene_ended.txt", $sceneEndedTime);
            @file_put_contents(sys_get_temp_dir() . "/nsfw_scene_ended_meta.json", json_encode([
                'time' => $sceneEndedTime,
                'gamets' => (float)($GLOBALS["gameRequest"][2] ?? 0),
            ]));
            @unlink(sys_get_temp_dir() . "/nsfw_scene_active.txt");
            @unlink(sys_get_temp_dir() . "/nsfw_player_scene_active.txt");
            @unlink(sys_get_temp_dir() . "/nsfw_scene_last_hash.txt");
            foreach (glob(sys_get_temp_dir() . "/nsfw_standing_intro_*.txt") ?: [] as $standingIntroFile) {
                @unlink($standingIntroFile);
            }
        } else {
            error_log("[AIAGENTNSFW] chatnf_sl_end without player in scoring (NPC-to-NPC end) - player scene markers untouched");
        }

        // Prime pillow talk for all NPCs with an active scene. Generic prompt is used for
        // any stale chatnf_sl events still in flight. handleSceneEnd() (when chatnf_sl_end
        // finally processes) will overwrite with the NPC-specific pillow talk prompt.
        $genericPillowTalk = "The intimate scene has just ended. React naturally to the quiet afterglow — warmly and briefly. Do NOT moan or continue sexual expressions.";
        try {
            $GLOBALS["db"]->execQuery("
                UPDATE nsfw_npc_data
                SET extended_data = jsonb_set(
                    jsonb_set(
                        extended_data,
                        '{aiagent_nsfw_intimacy_data,pillow_talk_pending}', 'true'::jsonb, false
                    ),
                    '{aiagent_nsfw_intimacy_data,pillow_talk_prompt}',
                    " . $GLOBALS["db"]->escapeLiteral(json_encode($genericPillowTalk)) . "::jsonb, false
                )
                WHERE extended_data IS NOT NULL
                  AND extended_data ? 'aiagent_nsfw_intimacy_data'
	                  AND (
	                      (extended_data->'aiagent_nsfw_intimacy_data'->>'level')::int > 0
	                      OR (extended_data->'aiagent_nsfw_intimacy_data'->>'had_sex_in_scene') = 'true'
	                  )
	                  AND COALESCE((extended_data->'aiagent_nsfw_intimacy_data'->>'intensity_tier')::int, 3) >= 3
            ");
        } catch (Exception $e) {
            error_log("[AIAGENTNSFW] Failed to set early pillow talk: " . $e->getMessage());
        }
    }

    if (in_array($currentEvent, $legacySceneSpeakEvents, true) && $legacySceneSpeakPolicy === 'block_all') {
        $blockedEvent = $currentEvent;
        $GLOBALS["gameRequest"][0] = "nsfw_blocked_policy";
        $currentEvent = "nsfw_blocked_policy";
        error_log("[AIAGENTNSFW] Blocked {$blockedEvent} by LEGACY_SCENE_SPEAK_POLICY=block_all");
    }

    // Player speech must be able to take the floor. NPC-to-NPC scene dialogue is
    // interruptible commentary; orgasm/tracking events are not blocked here.
    $playerInterruptibleNpcSceneEvents = ['ext_nsfw_npc_scene', 'ext_nsfw_npc_invite', 'chatnf_npc_sl'];
    if (in_array($currentEvent, $playerInterruptibleNpcSceneEvents, true)) {
        $interruptSuppressSeconds = max(1, (int)_getNsfwSetting('NPC_SCENE_PLAYER_INTERRUPT_SUPPRESS_SECONDS', 30));
        $requestTs = (float)($GLOBALS["gameRequest"][1] ?? 0);
        if (aiagentNsfwHasRecentPlayerInterruptForRequest($requestTs, $interruptSuppressSeconds)) {
            $blockedEvent = $currentEvent;
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
            $currentEvent = "nsfw_blocked_cooldown";
            error_log("[AIAGENTNSFW] Blocked {$blockedEvent} for player interrupt; suppress {$interruptSuppressSeconds}s");
        }
    }

    // Block stale sex-scene events that arrive after scene end. Use game time when available
    // so a genuinely new scene can start immediately, but old queued stage/comment requests die.
    $staleSceneEventTypes = [
        'ext_nsfw_sexcene',
        'info_sexscene',
        'chatnf_sl',
        'chatnf_sl_nr',
        'chatnf_sl_moan',
        'chatnf_sl_climax',
        'ext_nsfw_npc_scene',
        'ext_nsfw_npc_invite',
        'chatnf_npc_sl'
    ];
    if (in_array($currentEvent, $staleSceneEventTypes, true)) {
        $sceneEndedRaw = @file_get_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt");
        if ($sceneEndedRaw !== false) {
            $sceneEndedTime = (int)$sceneEndedRaw;
            $sceneEndedMeta = json_decode((string)@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_ended_meta.json"), true);
            $sceneEndedGamets = is_array($sceneEndedMeta) ? (float)($sceneEndedMeta['gamets'] ?? 0) : 0.0;
            $requestGamets = (float)($GLOBALS["gameRequest"][2] ?? 0);
            $sceneActiveTime = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt") ?: 0);
            $oldByGameTime = ($sceneEndedGamets > 0 && $requestGamets > 0 && $requestGamets <= $sceneEndedGamets);
            $oldByWallTime = (($sceneEndedGamets <= 0 || $requestGamets <= 0) && $sceneEndedTime > $sceneActiveTime);
            if ((time() - $sceneEndedTime) < 60 && ($oldByGameTime || $oldByWallTime)) {
                $blockedSceneEvent = $currentEvent;
                $GLOBALS["gameRequest"][0] = "nsfw_blocked_scene_ended";
                $currentEvent = "nsfw_blocked_scene_ended";
                error_log("[AIAGENTNSFW] Blocked stale {$blockedSceneEvent} scene event after scene end");
            }
        }
    }

    // NPC-to-NPC scene stage updates are normal, but each one can otherwise become an LLM call.
    // Use one global speech budget across all NPC-only scenes; orgasms are separate and bypass this.
    $npcSceneCadenceMeta = null;
    if ($currentEvent === 'ext_nsfw_npc_scene') {
        $npcSceneCadenceMeta = aiagentNsfwParseNpcSceneCadencePayloadForPreprocess($GLOBALS["gameRequest"][3] ?? '');
    } else if ($currentEvent === 'ext_nsfw_sexcene') {
        $npcSceneCadenceMeta = aiagentNsfwParsePlayerlessSexSceneCadencePayloadForPreprocess($GLOBALS["gameRequest"][3] ?? '');
    }
    if (is_array($npcSceneCadenceMeta)) {
        $npcSceneDecision = aiagentNsfwNpcSceneCadenceDecisionForPreprocess($npcSceneCadenceMeta);
        if (empty($npcSceneDecision['allow'])) {
            $blockedEvent = $currentEvent;
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
            $currentEvent = "nsfw_blocked_cooldown";
            error_log("[AIAGENTNSFW] Throttled NPC scene speech for {$blockedEvent} thread {$npcSceneDecision['thread']}: {$npcSceneDecision['reason']}");
        } else {
            $distanceLog = ($npcSceneDecision['distance'] !== null) ? (" distance=" . round((float)$npcSceneDecision['distance'])) : "";
            $speakerLog = '';
            if (!empty($npcSceneDecision['speaker'])) {
                $speakerName = (string)$npcSceneDecision['speaker'];
                $_GET["profile"] = md5($speakerName);
                $GLOBALS["AIAGENTNSFW_ROUTED_NPC_SCENE_PROFILE"] = $_GET["profile"];
                $GLOBALS["AIAGENTNSFW_NPC_SCENE_SPEAKER"] = $speakerName;
                if (!empty($npcSceneDecision['partner'])) {
                    $GLOBALS["AIAGENTNSFW_NPC_SCENE_REPLY_PARTNER"] = (string)$npcSceneDecision['partner'];
                }
                $speakerLog = " speaker={$speakerName}" . (!empty($npcSceneDecision['partner']) ? " partner={$npcSceneDecision['partner']}" : "");
            }
            error_log("[AIAGENTNSFW] NPC scene speech cadence allowed for {$currentEvent} thread {$npcSceneDecision['thread']}{$distanceLog}{$speakerLog}");
        }
    }

    // Mark scene as active when live scene events survive stale/backlog gates.
    $sceneActiveEvents = ['ext_nsfw_sexcene', 'ext_nsfw_consent_bark', 'info_sexscene', 'chatnf_sl', 'chatnf_sl_nr', 'ext_nsfw_npc_scene', 'ext_nsfw_npc_invite', 'chatnf_npc_sl'];
    if (in_array($currentEvent, $sceneActiveEvents, true)) {
        @file_put_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt", time());
    }
    // Profile generation can run during NPC-only scenes, but should stay out of the way while the player is in-scene.
    $playerSceneActiveEvents = ['ext_nsfw_sexcene', 'ext_nsfw_consent_bark', 'info_sexscene', 'chatnf_sl', 'chatnf_sl_nr', 'chatnf_sl_moan', 'chatnf_sl_climax'];
    if (in_array($currentEvent, $playerSceneActiveEvents, true)) {
        @file_put_contents(sys_get_temp_dir() . "/nsfw_player_scene_active.txt", time());
    }

    // Block ALL rechat/narration while any scene is active.
    // Uses nsfw_scene_active.txt file marker (written by scene events).
    // NOTE: HERIKA_NAME is NOT set to the correct NPC in preprocessing — it's the
    // default from conf.php. So we CANNOT do per-actor checks here. Instead we
    // block ALL rechat/narration during scenes. This prevents event backlog that
    // clogs the main semaphore and delays scene/orgasm processing.
    if (in_array($currentEvent, ['rechat', 'narration'])) {
        if (_getNsfwSetting('BLOCK_RECHAT_IN_SCENE', true)) {
            $sceneActiveTime = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt") ?: 0);
            $sceneEndedTime  = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt") ?: 0);
            $sceneWindow     = (int)_getNsfwSetting('BLOCK_RECHAT_TIMEOUT', 300);
            // Scene counts as active only while fresh AND not superseded by a scene-end (ended-latch),
            // so a finished scene immediately stops throttling instead of dead-zoning for the window.
            $sceneIsActive = $sceneActiveTime > 0 && (time() - $sceneActiveTime) < $sceneWindow && $sceneActiveTime >= $sceneEndedTime;
            if ($sceneIsActive) {
                // During an active PLAYER sex scene, FULLY block ambient rechat/narration. A re-chat mid-sex gets NO
                // scene cue applied, so the partner emits an out-of-place conversational/greeting line ("It's so
                // delightful to see you here...") right in the middle of the act.
                // Actual scene dialogue (chatnf_sl / ext_nsfw_sexcene) and player-initiated dialogue (inputtext) are
                // NOT rechat, so they still flow. Witnesses during NPC-only scenes keep the throttle below.
                $playerSceneActiveTime = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_player_scene_active.txt") ?: 0);
                $playerSceneEndedTime  = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_player_scene_ended.txt") ?: 0);
                $playerSceneIsActive   = $playerSceneActiveTime > 0 && (time() - $playerSceneActiveTime) < $sceneWindow && $playerSceneActiveTime >= $playerSceneEndedTime;
                // PLAYER scenes block by default (the partner greeting you mid-sex is jarring). NPC-to-NPC scenes keep
                // the throttle by default (the leaked rechat is usually a nearby WITNESS reacting - wanted ambiance -
                // not one of the two scene NPCs, whose lines come via chatnf_npc_sl). BLOCK_RECHAT_IN_NPC_SCENE flips
                // NPC scenes to full-block too if the out-of-cue lines bother you there as well.
                $blockThisScene = $playerSceneIsActive
                    ? _getNsfwSetting('BLOCK_RECHAT_IN_PLAYER_SCENE', true)
                    : _getNsfwSetting('BLOCK_RECHAT_IN_NPC_SCENE', false);
                // PLAYER SCENE CHATTER CADENCE (feature 2026-07-01n): a non-zero cadence turns the player-scene
                // hard block into a timed re-prompt - one rechat per interval rides the engaged-partner sex-cue
                // mirror (AIAGENTNSFW_RECHAT_SEX), so the line is scene-aware, never a greeting. 0 = hard block.
                $playerRechatCadence = (int)_getNsfwSetting('PLAYER_SCENE_RECHAT_CADENCE_SECONDS', 0);
                if ($playerSceneIsActive && $playerRechatCadence > 0) { $blockThisScene = false; }
                if ($blockThisScene) {
                    $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
                    error_log("[AIAGENTNSFW] Blocked ambient rechat/narration during active " . ($playerSceneIsActive ? "PLAYER" : "NPC-to-NPC") . " scene (prevents out-of-cue line mid-scene)");
                } else {
                    // THROTTLE instead of block: let one rechat/narration through per global speech cadence so
                    // scene partners AND nearby witnesses can comment without flooding the main semaphore.
                    $cadence = ($playerSceneIsActive && $playerRechatCadence > 0)
                        ? $playerRechatCadence // player-scene chatter uses its own UI cadence (feature 2026-07-01n)
                        : (int)_getNsfwSetting('NPC_SCENE_GLOBAL_COOLDOWN_SECONDS', 25);
                    if ($cadence < 1) { $cadence = 1; }
                    $rechatClock = sys_get_temp_dir() . "/nsfw_rechat_scene_last.txt";
                    $lastRechat  = (int)(@file_get_contents($rechatClock) ?: 0);
                    if ((time() - $lastRechat) < $cadence) {
                        $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown"; // within cadence -> drop the excess
                    } else {
                        @file_put_contents($rechatClock, time(), LOCK_EX);    // allow this one; reset cadence clock
                    }
                }
            }
        }
    }

    // CHIM's generic idle tick can emit "Time passes without anyone in the group talking" while
    // OStim/OAR affection is active, causing a second unrelated model line on the same scene beat.
    // Block only that synthetic idle tick; real infoaction events still pass through.
    if ($currentEvent === 'infoaction') {
        $idlePayload = strtolower((string)($GLOBALS["gameRequest"][3] ?? ''));
        if (strpos($idlePayload, 'time passes without anyone in the group talking') !== false) {
            $sceneActiveTime = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt") ?: 0);
            $sceneEndedTime  = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt") ?: 0);
            if ($sceneActiveTime > 0 && (time() - $sceneActiveTime) < 300 && $sceneActiveTime >= $sceneEndedTime) {
                $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
                $currentEvent = "nsfw_blocked_cooldown";
                error_log("[AIAGENTNSFW] Blocked synthetic idle infoaction during active scene");
            }
        }
    }

    // Physics cooldown (outside scenes only - scene blocking handled by Papyrus routing)
    $physicsEvents = ['ext_nsfw_physics_raw', 'ext_nsfw_physics'];
    if (in_array($currentEvent, $physicsEvents)) {
        $rawData = $GLOBALS["gameRequest"][3] ?? '';
        $parts = explode('^', $rawData);
        $physicsActor = trim((string)($parts[0] ?? ''));
        if ($currentEvent === 'ext_nsfw_physics_raw' && $physicsActor !== '') {
            $playerName = trim((string)($GLOBALS["PLAYER_NAME"] ?? ''));
            if (!nsfwIsNarratorName($physicsActor) && ($playerName === '' || strcasecmp($physicsActor, $playerName) !== 0)) {
                $_GET["profile"] = md5($physicsActor);
                $GLOBALS["AIAGENTNSFW_ROUTED_PHYSICS_PROFILE"] = $_GET["profile"];
                $currentActor = $physicsActor;
                error_log("[AIAGENTNSFW] Routed physics event to touched actor profile: {$physicsActor}");
            }
        }
        $physAction = strtolower(trim((string)($parts[2] ?? 'touch')));
        if ($physAction === '') {
            $physAction = 'touch';
        }
        $isTouch = ($physAction === 'touch');
        $isGrab = ($physAction === 'grab');
        $isSpank = ($physAction === 'spank');
        $isRelease = ($physAction === 'release');
        $physicsBodyPart = aiagentNsfwNormalizePhysicsBodyPartForPreprocess($parts[1] ?? 'body');
        if ($isRelease && $currentEvent === 'ext_nsfw_physics_raw' && $physicsActor !== '') {
            aiagentNsfwMarkPhysicsReleaseForPreprocess($physicsActor, $physicsBodyPart);
            require_once(__DIR__."/nsfw_physics.php");
            NsfwPhysics::processPhysicsEvent($rawData, 'release_context', 'release updates VR contact state only');
            error_log("[NSFW Physics] release ignored before prompt routing: release updates VR contact state only for {$physicsActor}");
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
            $currentEvent = "nsfw_blocked_cooldown";
        }
        if ($isTouch
            && $GLOBALS["gameRequest"][0] !== "nsfw_blocked_cooldown"
            && $currentEvent === 'ext_nsfw_physics_raw'
            && $physicsActor !== ''
            && aiagentNsfwHasRecentPhysicsReleaseForPreprocess($physicsActor, 2, $physicsBodyPart)
        ) {
            require_once(__DIR__."/nsfw_physics.php");
            NsfwPhysics::processPhysicsEvent($rawData, 'post_release_touch_suppress', "touch suppressed for 2s after same-body HIGGS release for {$physicsActor} {$physicsBodyPart}");
            error_log("[NSFW Physics] touch ignored before prompt routing: post-release touch suppress for {$physicsActor} {$physicsBodyPart}");
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
            $currentEvent = "nsfw_blocked_cooldown";
        }
        if ($isSpank) {
            $spankEnabled = (bool) _getNsfwSetting('PHYSICS_SPANK_ENABLED', true);
            $spankSpeed = isset($parts[7]) ? floatval($parts[7]) : 0.0;
            $spankMinSpeed = max(10, min(380, (int) _getNsfwSetting('PHYSICS_SPANK_MIN_SPEED', 30)));
            if (!$spankEnabled) {
                require_once(__DIR__."/nsfw_physics.php");
                NsfwPhysics::logRawPhysicsEvent($rawData, 'disabled', 'PHYSICS_SPANK_ENABLED is off');
                error_log("[NSFW Physics] Spank ignored before prompt routing: PHYSICS_SPANK_ENABLED is off");
                $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
                $currentEvent = "nsfw_blocked_cooldown";
            } elseif ($spankSpeed > 0 && $spankSpeed < $spankMinSpeed) {
                require_once(__DIR__."/nsfw_physics.php");
                NsfwPhysics::logRawPhysicsEvent($rawData, 'threshold', "speed {$spankSpeed} below threshold {$spankMinSpeed}");
                error_log("[NSFW Physics] Spank ignored before prompt routing: speed {$spankSpeed} below threshold {$spankMinSpeed}");
                $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
                $currentEvent = "nsfw_blocked_cooldown";
            }
        }
        // DEBOUNCE only - keep this SHORT so the NPC stays aware of changing contact.
        // Key by body part so a breast brush does not suppress a face touch.
        if ($GLOBALS["gameRequest"][0] !== "nsfw_blocked_cooldown") {
            $grabCooldown  = max(1, (int) _getNsfwSetting('PHYSICS_GRAB_COOLDOWN', 2));
            $spankCooldown = max(1, (int) _getNsfwSetting('PHYSICS_SPANK_COOLDOWN', 5));
            $touchCooldown = max(1, (int) _getNsfwSetting('PHYSICS_TOUCH_COOLDOWN', 2));
            // IN-SCENE TOUCH DEBOUNCE (user directive 2026-06-29): during an active OStim/SexLab scene, bodies are in
            // constant contact, so a 2s touch cadence spams the model. Debounce touch WAY down inside a scene (default
            // 120s) while leaving the out-of-scene cadence untouched. Grab/spank (intentional) keep their own cadence.
            if ($isTouch) {
                $touchSceneActiveTs = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt") ?: 0);
                $touchSceneEndedTs  = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt") ?: 0);
                $touchInScene = ($touchSceneActiveTs > 0 && (time() - $touchSceneActiveTs) < 600 && $touchSceneActiveTs >= $touchSceneEndedTs);
                if ($touchInScene) {
                    $touchCooldown = max($touchCooldown, (int) _getNsfwSetting('PHYSICS_TOUCH_SCENE_COOLDOWN', 120));
                    error_log("[NSFW Physics] IN-SCENE touch debounce active ({$touchCooldown}s, whole-body) - scene_active age " . (time() - $touchSceneActiveTs) . "s");
                } else {
                    error_log("[NSFW Physics] touch NOT in-scene (scene_active ts={$touchSceneActiveTs}, ended ts={$touchSceneEndedTs}) - per-part cooldown {$touchCooldown}s");
                }
            }
            $cooldownSeconds = $isSpank ? $spankCooldown : ($isGrab ? $grabCooldown : ($isTouch ? $touchCooldown : $grabCooldown));
            $cooldownKind = $isSpank ? 'spank' : ($isGrab ? 'grab' : ($isTouch ? 'touch' : ($isRelease ? 'release' : 'other')));
            $cooldownBodyPart = strtolower(trim((string)($parts[1] ?? 'body')));
            if ($cooldownBodyPart === '') {
                $cooldownBodyPart = 'body';
            }
            switch ($cooldownBodyPart) {
                case 'breast':
                case 'boob':
                case 'boobs':
                    $cooldownBodyPart = 'breasts';
                    break;
                case 'pec':
                case 'pecs':
                    $cooldownBodyPart = 'chest';
                    break;
                case 'ass':
                case 'rear':
                case 'bottom':
                    $cooldownBodyPart = 'butt';
                    break;
                case 'vagina':
                case 'vaginal':
                case 'pelvis':
                    $cooldownBodyPart = 'pussy';
                    break;
                case 'anus':
                    $cooldownBodyPart = 'anal';
                    break;
                case 'cock':
                case 'genitals':
                case 'crotch':
                    $cooldownBodyPart = 'penis';
                    break;
                case 'hair':
                case 'scalp':
                    $cooldownBodyPart = 'head';
                    break;
                case 'cheek':
                case 'cheeks':
                case 'jaw':
                case 'chin':
                case 'nose':
                case 'mouth':
                case 'lips':
                case 'forehead':
                    $cooldownBodyPart = 'face';
                    break;
                case 'throat':
                    $cooldownBodyPart = 'neck';
                    break;
            }
            // IN-SCENE: collapse touch to ONE shared per-actor cooldown so whole-body contact during sex doesn't spam
            // (per-part cooldowns let every part fire separately). EXCEPTION (user directive 2026-06-29): the ASS stays
            // responsive - ass contact is speed-based (spanks), so it keeps the normal short touch cadence and its own
            // key instead of the long in-scene debounce. Spank events ($isSpank) are already a separate path.
            if ($isTouch && !empty($touchInScene)) {
                if ($cooldownBodyPart === 'butt') {
                    $cooldownSeconds = max(1, (int) _getNsfwSetting('PHYSICS_TOUCH_COOLDOWN', 2));
                    error_log("[NSFW Physics] IN-SCENE touch on ASS kept responsive ({$cooldownSeconds}s, own key) - speed-based");
                } else {
                    $cooldownBodyPart = 'scene';
                }
            }
            $lowConfidencePhysicsParts = ['body', 'other', 'arm', 'shoulder', 'back', 'belly', 'leg', 'foot'];
            $cooldownFile = sys_get_temp_dir() . "/nsfw_physics_" . $cooldownKind . "_" . md5($currentActor) . "_" . md5($cooldownBodyPart) . ".txt";
            $lastTime = @file_get_contents($cooldownFile);
            if ($lastTime !== false && (time() - (int)$lastTime) < $cooldownSeconds) {
                // Suppress repeat model calls but keep the latest valid contact available
                // as context for the next normal turn. This is especially important for
                // CBPC touches, which can arrive right after HIGGS release.
                if ($currentEvent === 'ext_nsfw_physics_raw' && !empty($rawData)) {
                    require_once(__DIR__."/nsfw_physics.php");
                    NsfwPhysics::processPhysicsEvent($rawData, 'cooldown', "{$cooldownKind} cooldown {$cooldownSeconds}s still active for {$currentActor} {$cooldownBodyPart}");
                }
                error_log("[NSFW Physics] {$physAction} ignored before prompt routing: {$cooldownKind} cooldown {$cooldownSeconds}s still active for {$currentActor} {$cooldownBodyPart}");
                $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
            } else {
                @file_put_contents($cooldownFile, time());
            }

            // HIGGS/CBPC often jitters through generic nodes for the same real hand motion
            // (Body -> Shoulder -> Arm -> Back). Collapse those approximate zones per actor
            // without blocking later high-confidence sensitive-zone events.
            if (!$isSpank
                && $GLOBALS["gameRequest"][0] !== "nsfw_blocked_cooldown"
                && in_array($cooldownBodyPart, $lowConfidencePhysicsParts, true)
            ) {
                $lowConfidenceCooldown = max(
                    $cooldownSeconds,
                    (int)_getNsfwSetting('PHYSICS_LOW_CONFIDENCE_COOLDOWN', 8)
                );
                $lowConfidenceActor = $physicsActor !== '' ? $physicsActor : (string)$currentActor;
                if ($lowConfidenceActor === '') {
                    $lowConfidenceActor = 'unknown';
                }
                $lowConfidenceFile = sys_get_temp_dir() . "/nsfw_physics_low_confidence_" . md5(strtolower($lowConfidenceActor)) . ".txt";
                $lastLowConfidence = @file_get_contents($lowConfidenceFile);
                if ($lastLowConfidence !== false && (time() - (int)$lastLowConfidence) < $lowConfidenceCooldown) {
                    if ($currentEvent === 'ext_nsfw_physics_raw' && !empty($rawData)) {
                        require_once(__DIR__."/nsfw_physics.php");
                        NsfwPhysics::processPhysicsEvent($rawData, 'low_confidence_cooldown', "low-confidence physics cooldown {$lowConfidenceCooldown}s still active for {$lowConfidenceActor}");
                    }
                    error_log("[NSFW Physics] {$physAction} ignored before prompt routing: low-confidence physics cooldown {$lowConfidenceCooldown}s still active for {$lowConfidenceActor}");
                    $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
                } else {
                    @file_put_contents($lowConfidenceFile, time());
                }
            }

            // Inside an active OStim/SexLab scene, CBPC/HIGGS can rotate through multiple nodes/body parts
            // and create a model-call backlog. Keep the latest contact as context, but only let one prompt-
            // producing touch effect through per actor on this longer scene ticker. Intentional ass slaps
            // use their own PHYSICS_SPANK_COOLDOWN instead, so they stay responsive in scenes.
            $scenePhysicsActor = $physicsActor !== '' ? $physicsActor : (string)$currentActor;
            if (!$isSpank
                && $GLOBALS["gameRequest"][0] !== "nsfw_blocked_cooldown"
                && aiagentNsfwActorHasActiveSceneForPreprocess($scenePhysicsActor, (int)_getNsfwSetting('BLOCK_RECHAT_TIMEOUT', 300))
            ) {
                $sceneEffectCooldown = max(90, (int)_getNsfwSetting('PHYSICS_SCENE_EFFECT_COOLDOWN', 30));
                if ($scenePhysicsActor === '') {
                    $scenePhysicsActor = 'unknown';
                }
                $sceneCooldownFile = sys_get_temp_dir() . "/nsfw_physics_scene_effect_" . md5(strtolower($scenePhysicsActor)) . ".txt";
                $lastSceneEffect = @file_get_contents($sceneCooldownFile);
                if ($lastSceneEffect !== false && (time() - (int)$lastSceneEffect) < $sceneEffectCooldown) {
                    if ($currentEvent === 'ext_nsfw_physics_raw' && !empty($rawData)) {
                        require_once(__DIR__."/nsfw_physics.php");
                        NsfwPhysics::processPhysicsEvent($rawData, 'scene_cooldown', "scene physics cooldown {$sceneEffectCooldown}s still active for {$scenePhysicsActor}");
                    }
                    error_log("[NSFW Physics] {$physAction} ignored before prompt routing: scene physics cooldown {$sceneEffectCooldown}s still active for {$scenePhysicsActor}");
                    $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
                } else {
                    @file_put_contents($sceneCooldownFile, time());
                }
            }
        }
    }

    // Rewrite info_sexscene FIRST so dedup catches both event names
    if ($currentEvent === 'info_sexscene' || $GLOBALS["gameRequest"][0] === 'info_sexscene') {
        $GLOBALS["gameRequest"][0] = "ext_nsfw_sexcene";
        $currentEvent = "ext_nsfw_sexcene";
    }

    // OStim scene changes are normally routed through The Narrator/Character.
    // Route the same request to the primary NPC before profile loading so the model sees the
    // authoritative current scene prompt. chatnf_sl is a speak trigger and can arrive stale or
    // out of order, so it must not be the only owner of standing/intro or consent-gate prompts.
    if ($currentEvent === 'ext_nsfw_sexcene') {
        try {
            $sceneParts = explode("/", $GLOBALS["gameRequest"][3] ?? '');
            $playerName = $GLOBALS["PLAYER_NAME"] ?? '';
            $actorInfos = array_slice($sceneParts, 3);
            $otherActors = [];
            $actorRoles = [];
            $activePartnerTags = [
                'dom', 'sub', 'vaginal', 'anal', 'oral', 'handjob', 'blowjob',
                'cunnilingus', 'penetration', 'riding', 'missionary', 'doggystyle',
                'cowgirl', 'reversecowgirl', 'prone', 'standing', 'sitting', 'facingaway'
            ];

            foreach ($actorInfos as $actorInfo) {
                if ($actorInfo === '') continue;
                $actorParts = explode('^', $actorInfo, 2);
                $actorName = trim($actorParts[0] ?? '');
                if ($actorName === '' || nsfwIsNarratorName($actorName) || strcasecmp($actorName, $playerName) === 0) {
                    continue;
                }
                $roles = !empty($actorParts[1])
                    ? array_map('trim', explode(',', strtolower($actorParts[1])))
                    : [];
                $otherActors[] = $actorName;
                $actorRoles[$actorName] = $roles;
            }

            $primaryPartner = null;
            foreach ($otherActors as $candidate) {
                foreach ($actorRoles[$candidate] ?? [] as $tag) {
                    if (in_array($tag, $activePartnerTags, true)) {
                        $primaryPartner = $candidate;
                        break 2;
                    }
                }
            }
            if ($primaryPartner === null && !empty($otherActors)) {
                $primaryPartner = $otherActors[0];
            }

            if (!empty($primaryPartner) && empty($GLOBALS["AIAGENTNSFW_ROUTED_NPC_SCENE_PROFILE"])) {
                $_GET["profile"] = md5($primaryPartner);
                $GLOBALS["AIAGENTNSFW_ROUTED_SCENE_PROFILE"] = $_GET["profile"];
                error_log("[AIAGENTNSFW] OStim scene routed to NPC profile: {$primaryPartner}");
            }
        } catch (Exception $e) {
            error_log("[AIAGENTNSFW] OStim profile routing failed: " . $e->getMessage());
        }
    }

    // Once an authoritative ext_nsfw_sexcene request is routed to the NPC profile, block
    // the follow-up speak/moan events that OStim often emits for the same scene beat.
    // Otherwise the NPC answers the same standing/intro prompt twice.
    if ($legacySceneSpeakPolicy !== 'allow' && in_array($currentEvent, $legacySceneSpeakEvents, true)) {
        $duplicateEvent = $currentEvent;
        $sceneProfileHash = preg_replace('/[^a-f0-9]/i', '', (string)($_GET["profile"] ?? md5($currentActor)));
        if ($sceneProfileHash !== '') {
            $standingIntroFile = sys_get_temp_dir() . "/nsfw_standing_intro_" . $sceneProfileHash . ".txt";
            if (file_exists($standingIntroFile)) {
                $GLOBALS["gameRequest"][0] = "nsfw_blocked_duplicate";
                $currentEvent = "nsfw_blocked_duplicate";
                error_log("[AIAGENTNSFW] Blocked duplicate {$duplicateEvent} during standing/intro latch for profile {$sceneProfileHash}");
            }
        }
        if ($sceneProfileHash !== '' && $currentEvent !== "nsfw_blocked_duplicate") {
            $authoritativeFile = sys_get_temp_dir() . "/nsfw_authoritative_scene_" . $sceneProfileHash . ".txt";
            $authoritativeTime = @file_get_contents($authoritativeFile);
            $authoritativeTime = ($authoritativeTime !== false) ? (int)$authoritativeTime : 0;
            if ($authoritativeTime > 0 && (time() - $authoritativeTime) < 12) {
                $GLOBALS["gameRequest"][0] = "nsfw_blocked_duplicate";
                $currentEvent = "nsfw_blocked_duplicate";
                error_log("[AIAGENTNSFW] Blocked duplicate {$duplicateEvent} after authoritative scene response for profile {$sceneProfileHash}");
            }
        }
    }

    // OStim often advances through several different standing/intro animation IDs before
    // any real action happens. Hash dedup sees those as different scenes, so normalize them
    // here and allow only one standing-intro model turn until the scene escalates or ends.
    if ($currentEvent === 'ext_nsfw_sexcene') {
        $rawSceneData = (string)($GLOBALS["gameRequest"][3] ?? '');
        $sceneParts = explode("/", $rawSceneData);
        $tagPart = strtolower($sceneParts[1] ?? '');
        $actorInfos = array_slice($sceneParts, 3);
        $isIdleIntro = (strpos($tagPart, 'idle') !== false || strpos($tagPart, 'intro') !== false);
        $hasActorInfo = !empty($actorInfos);
        $allActorsStanding = $hasActorInfo;
        foreach ($actorInfos as $actorInfo) {
            $rolePart = strtolower(explode("^", $actorInfo, 2)[1] ?? '');
            if (strpos($rolePart, 'standing') === false) {
                $allActorsStanding = false;
                break;
            }
        }
        if ($isIdleIntro && $allActorsStanding) {
            $standingProfileHash = preg_replace('/[^a-f0-9]/i', '', (string)($GLOBALS["AIAGENTNSFW_ROUTED_SCENE_PROFILE"] ?? ($_GET["profile"] ?? md5($currentActor))));
            if ($standingProfileHash !== '') {
                $standingFile = sys_get_temp_dir() . "/nsfw_standing_intro_" . $standingProfileHash . ".txt";
                $standingPayloadHash = md5($rawSceneData);
                $standingEventTs = (string)($GLOBALS["gameRequest"][2] ?? '');
                $standingPrevRaw = file_exists($standingFile) ? (string)@file_get_contents($standingFile) : '';
                $standingPrev = @json_decode($standingPrevRaw, true);
                if (!is_array($standingPrev)) {
                    $standingPrev = [
                        'hash' => $standingPrevRaw,
                        'event_ts' => '',
                        'time' => file_exists($standingFile) ? (int)@filemtime($standingFile) : 0,
                    ];
                }
                $standingPrevTime = (int)($standingPrev['time'] ?? 0);
                $standingAge = $standingPrevTime > 0 ? time() - $standingPrevTime : PHP_INT_MAX;
                $standingSamePayload = (string)($standingPrev['hash'] ?? '') === $standingPayloadHash;
                $standingSameEvent = $standingEventTs !== '' && (string)($standingPrev['event_ts'] ?? '') === $standingEventTs;
                if ($standingSamePayload && ($standingSameEvent || $standingAge <= 2)) {
                    $GLOBALS["gameRequest"][0] = "nsfw_blocked_duplicate";
                    $currentEvent = "nsfw_blocked_duplicate";
                    error_log("[AIAGENTNSFW] Blocked duplicate standing/intro scene update for profile {$standingProfileHash}");
                } else {
                    @file_put_contents($standingFile, json_encode([
                        'hash' => $standingPayloadHash,
                        'event_ts' => $standingEventTs,
                        'time' => time(),
                    ]));
                }
            }
        } else {
            $standingProfileHash = preg_replace('/[^a-f0-9]/i', '', (string)($GLOBALS["AIAGENTNSFW_ROUTED_SCENE_PROFILE"] ?? ($_GET["profile"] ?? md5($currentActor))));
            if ($standingProfileHash !== '') {
                @unlink(sys_get_temp_dir() . "/nsfw_standing_intro_" . $standingProfileHash . ".txt");
            }
        }
    }

    // Scene dedup - OStim fires scene events repeatedly (~500ms)
    // Hash-based: process each unique scene data ONCE, block all repeats.
    // When OStim changes position/animation, the data changes → new hash → passes through.
    if ($currentEvent === 'ext_nsfw_sexcene') {
        $sceneHash = md5($GLOBALS["gameRequest"][3] ?? '');
        $dedupFile = sys_get_temp_dir() . "/nsfw_scene_last_hash.txt";
        $eventTs = (string)($GLOBALS["gameRequest"][2] ?? '');
        $lastRaw = @file_get_contents($dedupFile);
        $lastData = @json_decode((string)$lastRaw, true);
        if (is_array($lastData)) {
            $lastHash = (string)($lastData['hash'] ?? '');
            $lastEventTs = (string)($lastData['event_ts'] ?? '');
            $lastHashTime = (int)($lastData['time'] ?? 0);
        } else {
            $lastHash = (string)$lastRaw;
            $lastEventTs = '';
            $lastHashTime = is_file($dedupFile) ? (int)@filemtime($dedupFile) : 0;
        }
        $sameSceneEvent = $eventTs !== '' && $lastEventTs === $eventTs;
        $hashAge = $lastHashTime > 0 ? time() - $lastHashTime : PHP_INT_MAX;
        if ($lastHash === $sceneHash && ($sameSceneEvent || $hashAge <= 2)) {
            // Same scene data as last processed event — block completely
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_duplicate";
        } else {
            // New scene data (position change or new scene) — process it
            @file_put_contents($dedupFile, json_encode([
                'hash' => $sceneHash,
                'event_ts' => $eventTs,
                'time' => time(),
            ]));
            if (!empty($GLOBALS["AIAGENTNSFW_ROUTED_SCENE_PROFILE"])) {
                @file_put_contents(
                    sys_get_temp_dir() . "/nsfw_authoritative_scene_" . $GLOBALS["AIAGENTNSFW_ROUTED_SCENE_PROFILE"] . ".txt",
                    time()
                );
            }
            // Mark that a scene CHANGE occurred — bypass chatnf_sl cooldown briefly
            @file_put_contents(sys_get_temp_dir() . "/nsfw_scene_changed.txt", time());
        }
    }

    // Scene dialogue cooldown — but bypass for 5 seconds after a scene CHANGE
    // so the NPC can respond to the new position/animation immediately.
    // NOTE: $currentActor is DEFAULT HERIKA_NAME (not actual NPC) in preprocessing,
    // so we use $_GET["profile"] hash for per-NPC cooldown files instead.
    if (in_array($currentEvent, ['chatnf_sl', 'chatnf_sl_nr'])) {
        $sceneCooldown = _getNsfwSetting('COOLDOWN_SEX_SCENE', 15);
        $profileHash = $_GET["profile"] ?? md5($currentActor);
        $cooldownFile = sys_get_temp_dir() . "/nsfw_scene_dialogue_" . $profileHash . ".txt";
        $lastTime = @file_get_contents($cooldownFile);
        $lastTime = $lastTime !== false ? (int)$lastTime : 0;

        // Check if scene just changed — bypass cooldown so NPC responds to new position
        $sceneChangedFile = sys_get_temp_dir() . "/nsfw_scene_changed.txt";
        $sceneChangedTime = @file_get_contents($sceneChangedFile);
        $recentSceneChange = ($sceneChangedTime !== false && (time() - (int)$sceneChangedTime) < 5);

        if (!$recentSceneChange && (time() - $lastTime) < $sceneCooldown) {
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
        } else {
            @file_put_contents($cooldownFile, time());
        }
    }

    // Moan cooldown — moans don't hit the LLM but they DO acquire the main semaphore,
    // so dozens of them clog the queue and block real dialogue events behind them.
    // Shorter cooldown than dialogue since they're lightweight, but enough to prevent flooding.
    if ($currentEvent === 'chatnf_sl_moan') {
        $moanCooldown = _getNsfwSetting('COOLDOWN_MOAN', 8);
        $profileHash = $_GET["profile"] ?? md5($currentActor);
        $cooldownFile = sys_get_temp_dir() . "/nsfw_moan_" . $profileHash . ".txt";
        $lastTime = @file_get_contents($cooldownFile);
        $lastTime = $lastTime !== false ? (int)$lastTime : 0;

        if ((time() - $lastTime) < $moanCooldown) {
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
        } else {
            @file_put_contents($cooldownFile, time());
        }
    }

    // Orgasm/climax handling - cooldown only (speak style prompt injection happens in prompts.php)
    if (in_array($currentEvent, ['ext_nsfw_orgasm', 'ext_nsfw_npc_orgasm', 'chatnf_sl_climax'])) {
        $climaxCooldown = _getNsfwSetting('COOLDOWN_CLIMAX', 30);

        // Extract orgasmer name from event data since HERIKA_NAME isn't set yet
        // Format: "(Context location: X)PlayerName:OrgasmerName/SceneId/Index/Partner"
        // OR with profile param: The third parameter of requestMessageForActor sets $_GET["profile"]
        // which contains the MD5 hash of the NPC name - but we need the actual name for meaningful cooldown
        $rawOrgasmData = $GLOBALS["gameRequest"][3] ?? '';
        $orgasmActorName = '';

        // Parse the orgasmer name from the event data
        if (!empty($rawOrgasmData)) {
            $orgasmParts = explode("/", $rawOrgasmData);
            $firstPart = trim($orgasmParts[0] ?? '');
            if (strpos($firstPart, ':') !== false) {
                $colonParts = explode(':', $firstPart);
                $orgasmActorName = trim(end($colonParts));
            }
            // npc_orgasm payload is ^-delimited (orgasmer^partner^scene) - key the cooldown on the orgasmer only
            if (strpos($orgasmActorName, '^') !== false) {
                $orgasmActorName = trim(explode('^', $orgasmActorName)[0]);
            }
        }

        // If we couldn't parse it, use the profile hash for per-request cooldown
        if (empty($orgasmActorName)) {
            $orgasmActorName = $_GET["profile"] ?? 'fallback';
            error_log("[AIAGENT-NSFW] Orgasm cooldown: couldn't parse actor name, using profile: $orgasmActorName");
        }

        // PER-RECEIVING-NPC cooldown: in a multi-actor / orgy scene the SAME orgasm (e.g. the player's) is dispatched
        // to EACH NPC as a SEPARATE request, each with its own $_GET["profile"] (md5 of the receiving NPC). Keying the
        // cooldown on the orgasmer ALONE made NPC #1 stamp the file and NPC #2+ get BLOCKED within the 30s window -
        // silently dropping their reactions. Include the receiving NPC so every NPC gets an independent climax window.
        $receivingProfile = $_GET["profile"] ?? 'fallback';
        $cooldownFile = sys_get_temp_dir() . "/nsfw_climax_cooldown_" . md5($orgasmActorName . '|' . $receivingProfile) . ".txt";
        $lastTime = @file_get_contents($cooldownFile);
        $lastTime = $lastTime !== false ? (int)$lastTime : 0;
        $timeSinceLast = time() - $lastTime;

        error_log("[AIAGENT-NSFW] Orgasm cooldown check for orgasmer '$orgasmActorName' -> receiving NPC '$receivingProfile': {$timeSinceLast}s since last (cooldown: {$climaxCooldown}s)");

        if ($timeSinceLast < $climaxCooldown) {
            error_log("[AIAGENT-NSFW] BLOCKING orgasm for '$orgasmActorName' - cooldown not expired");
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
        } else {
            @file_put_contents($cooldownFile, time());
        }
    }

    // info_sexscene rewrite is now above the dedup check (moved up)

    // Physics event preprocessing - rewrite to chatnf_physics for dialogue flow
    if ($GLOBALS["gameRequest"][0] == "ext_nsfw_physics_raw") {
        require_once(__DIR__."/nsfw_physics.php");
        $rawData = $GLOBALS["gameRequest"][3] ?? '';
        if (!empty($rawData)) {
            $result = NsfwPhysics::processPhysicsEvent($rawData);
            if ($result && empty($result['suppressModelRoute'])) {
                $cleanMessage = function_exists('aiagentNsfwContactStripTags')
                    ? aiagentNsfwContactStripTags($result['message'])
                    : $result['message'];
                $eventLogMessage = $cleanMessage;
                if (function_exists('aiagentNsfwContactTag') && !empty($result['contactKey'])) {
                    $eventLogMessage = aiagentNsfwContactTag($cleanMessage, $result['contactKey']);
                }
                $GLOBALS["gameRequest"][0] = "chatnf_physics";
                $GLOBALS["gameRequest"][3] = $eventLogMessage;

                if (NsfwPhysics::shouldInjectVrPhysicsPrompt($result)) {
                    NsfwPhysics::injectVrPhysicsPrompt($result);
                }
            } elseif ($result && !empty($result['suppressModelRoute'])) {
                $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
                $currentEvent = "nsfw_blocked_cooldown";
            }
        }
    }

    // On game load (init), clear stale scene state and future intimacy data
    if ($GLOBALS["gameRequest"][0]=="init") {
        $saveTimestamp = $GLOBALS["gameRequest"][2] ?? 0;

        @unlink(sys_get_temp_dir() . "/nsfw_scene_active.txt");
        @unlink(sys_get_temp_dir() . "/nsfw_player_scene_active.txt");
        $initEndedTime = time();
        @file_put_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt", $initEndedTime);
        // Game load is a full reset - end the player scene too (the kill switch reads this marker).
        @file_put_contents(sys_get_temp_dir() . "/nsfw_player_scene_ended.txt", $initEndedTime);
        foreach (glob(sys_get_temp_dir() . "/nsfw_physics_*") ?: [] as $tmpFile) {
            if (is_file($tmpFile)) { @unlink($tmpFile); }
        }
        foreach (glob(sys_get_temp_dir() . "/nsfw_last_physics_contact_*") ?: [] as $tmpFile) {
            if (is_file($tmpFile)) { @unlink($tmpFile); }
        }

        // Clear active scene data - OStim scenes don't persist across save/load
        try {
            $GLOBALS["db"]->execQuery("
                UPDATE nsfw_npc_data
                SET extended_data = jsonb_set(
                    extended_data,
                    '{aiagent_nsfw_intimacy_data}',
                    COALESCE(extended_data->'aiagent_nsfw_intimacy_data', '{}'::jsonb)
                    || jsonb_build_object(
                        'level', 0,
                        'sex_started', false,
                        'orgasmed', false,
                        'scene_phase', NULL,
                        'tier_prompt_sent', NULL,
                        'cached_tier_prompt', '',
                        'accepted_sex', false,
                        'accepted_affection', false,
                        'had_sex_in_scene', false,
                        'refusal_expressed', false,
                        'forced_scene', false,
                        'request_scene_stop', false,
                        'stop_command_sent', false,
                        'last_scene_stop_time', NULL,
                        'scene_stop_retry_count', 0,
                        'last_forced_refusal_scene_key', NULL,
                        'last_refusal_speech_time', NULL,
                        'last_refusal_speech_key', NULL,
                        'scene_is_idle', NULL,
                        'scene_start_time', NULL,
                        'scene_actors', NULL,
                        'raw_scene_actor_slots', NULL,
                        'actor_roles', NULL,
                        'my_role_tags', NULL,
                        'current_primary_partner', NULL,
                        'current_scene_desc', NULL,
                        'current_scene_name', NULL,
                        'current_scene_tags', NULL,
                        'is_active_participant', false,
                        'is_npc_scene', false,
                        'npc_scene_partner', NULL,
                        'sex_partner', NULL,
                        'last_accept_sex_time', NULL,
                        'last_accept_sex_partner', NULL,
                        'pillow_talk_pending', false,
                        'pillow_talk_prompt', '',
                        'whiskey_dick_fired', false,
                        'whiskey_dick_player_stage', NULL
                    ),
                    true
                )
                WHERE extended_data IS NOT NULL
                  AND extended_data ? 'aiagent_nsfw_intimacy_data'
                  AND (
                      COALESCE(NULLIF(extended_data->'aiagent_nsfw_intimacy_data'->>'level', ''), '0')::int > 0
                      OR extended_data->'aiagent_nsfw_intimacy_data' ? 'scene_actors'
                      OR extended_data->'aiagent_nsfw_intimacy_data' ? 'is_active_participant'
                      OR extended_data->'aiagent_nsfw_intimacy_data' ? 'refusal_expressed'
                      OR extended_data->'aiagent_nsfw_intimacy_data' ? 'accepted_sex'
                      OR extended_data->'aiagent_nsfw_intimacy_data' ? 'request_scene_stop'
                      OR extended_data->'aiagent_nsfw_intimacy_data' ? 'stop_command_sent'
                      OR extended_data->'aiagent_nsfw_intimacy_data' ? 'last_refusal_speech_time'
                  )
            ");
        } catch (Exception $e) {}

        // Clear future intimacy data (from different timeline/save)
        try {
            $GLOBALS["db"]->execQuery("
                UPDATE nsfw_npc_data
                SET extended_data = extended_data - 'aiagent_nsfw_intimacy_data'
                WHERE extended_data IS NOT NULL
                  AND extended_data ? 'aiagent_nsfw_intimacy_data'
                  AND (
                      (extended_data->'aiagent_nsfw_intimacy_data'->>'gamets')::float > $saveTimestamp
                      OR (extended_data->'aiagent_nsfw_intimacy_data'->>'gamets') IS NULL
                  )
            ");
        } catch (Exception $e) {}
    }

    // Blocked events terminate immediately - no LLM processing
    if (in_array($GLOBALS["gameRequest"][0], ['nsfw_blocked_cooldown', 'nsfw_blocked_duplicate', 'nsfw_blocked_scene_ended', 'nsfw_blocked_policy', 'nsfw_blocked_blank_input'])) {
        exit();
    }
}

// Hook to inject FMR fertility prompts into personality context
// This runs after prompts.php is loaded and gives the AI behavioral guidance
$GLOBALS["HOOKS"]["PERSONALITY_BUILDER"]["fmr_fertility_prompt"]=function($currentPersonality, $currentNpcData) {
    // Check if fertility tracking is enabled in settings
    if (function_exists('isFertilityTrackingEnabled') && !isFertilityTrackingEnabled()) {
        return $currentPersonality;
    }

    // Get NPC name from current NPC data
    $npcName = $currentNpcData["npc_name"] ?? ($GLOBALS["HERIKA_NAME"] ?? null);
    if (!$npcName) {
        return $currentPersonality;
    }

    // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
    require_once __DIR__ . "/nsfw_data.php";
    $extended = NsfwNpcData::get($npcName);
    if (!$extended || empty($extended)) return $currentPersonality;

    // Check if prompts.php has been loaded (has the getFertilityPromptForNpc function)
    if (!function_exists('getFertilityPromptForNpc')) {
        // Prompts.php not loaded yet - this is preprocessing, will be loaded later
        // Store for later injection via the BIOGRAPHY_BUILDER hook instead
        return $currentPersonality;
    }

    $fertilityPrompt = getFertilityPromptForNpc($extended, $npcName);

    if ($fertilityPrompt) {
        // Wrap in XML tag and append to personality
        $currentPersonality .= "\n<fertility_state>\n" . $fertilityPrompt . "\n</fertility_state>";
        error_log("[AIAGENTNSFW FMR] Injected fertility prompt for {$npcName}");
    }

    return $currentPersonality;
};

// Hook into BIOGRAPHY_BUILDER - Fertility Mode Reloaded integration
$GLOBALS["HOOKS"]["BIOGRAPHY_BUILDER"]["fertility_handler"]=function($currentBio,$currentNpcData) {
     // Check if fertility tracking is enabled in settings
     if (function_exists('isFertilityTrackingEnabled') && !isFertilityTrackingEnabled()) {
         return $currentBio;
     }

     $npcName = $currentNpcData["npc_name"] ?? null;
     if (!$npcName) return $currentBio;

     // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
     require_once __DIR__ . "/nsfw_data.php";
     $extended = NsfwNpcData::get($npcName);
     if (!$extended || empty($extended)) return $currentBio;

     $fertilityInfo = [];

     // Pregnancy status with details
     if (!empty($extended["fertility_is_pregnant"])) {
        $progress = $extended["fertility_progress"] ?? 0;
        $father = $extended["fertility_father"] ?? '';

        if ($progress > 0 && $progress <= 33) {
            $stage = "in early pregnancy";
        } elseif ($progress <= 66) {
            $stage = "visibly pregnant";
        } elseif ($progress <= 90) {
            $stage = "heavily pregnant";
        } else {
            $stage = "about to give birth";
        }

        $fertilityInfo[] = "{$npcName} is {$stage}" . ($father ? " with {$father}'s child" : "");

        // Baby health concerns
        if (!empty($extended["fertility_baby_damaged"]) && ($extended["fertility_baby_health"] ?? 100) < 50) {
            $fertilityInfo[] = "She is worried about her unborn child's health";
        }
     }

     // Recovery phase (post-birth)
     if (!empty($extended["fertility_recovery_day"])) {
        $day = $extended["fertility_recovery_day"];
        if ($day <= 3) {
            $fertilityInfo[] = "{$npcName} recently gave birth and is still recovering";
        } elseif ($day <= 10) {
            $fertilityInfo[] = "{$npcName} gave birth recently";
        }
     }

     // Recent birth
     if (!empty($extended["fertility_recent_birth"])) {
        $fertilityInfo[] = "{$npcName} has a newborn child";
     }

     // Trauma events
     if (!empty($extended["fertility_miscarriage"])) {
        $cause = $extended["fertility_miscarriage_cause"] ?? '';
        $fertilityInfo[] = "{$npcName} recently suffered a miscarriage" . ($cause ? " due to {$cause}" : "");
     }

     if (!empty($extended["fertility_baby_lost"])) {
        $fertilityInfo[] = "{$npcName} recently lost her unborn child and is grieving";
     }

     // Build fertility context block
     if (!empty($fertilityInfo)) {
        $currentBio .= "\n<fertility>\n" . implode(". ", $fertilityInfo) . ".\n</fertility>";
     }

     return $currentBio;
}

?>
