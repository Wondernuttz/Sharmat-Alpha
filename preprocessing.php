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
$GLOBALS["external_fast_commands"][]="ext_vr_item_raw";  // VR item pickup/drop (HIGGS)
$GLOBALS["external_fast_commands"][]="ext_vr_item_pickup";  // Rewritten from ext_vr_item_raw
$GLOBALS["external_fast_commands"][]="ext_vr_item_drop";    // Rewritten from ext_vr_item_raw

// BLOCKED events - these should NOT hit the LLM at all
$GLOBALS["external_fast_commands"][]="nsfw_blocked_cooldown";
$GLOBALS["external_fast_commands"][]="nsfw_blocked_duplicate";
$GLOBALS["external_fast_commands"][]="nsfw_blocked_scene_ended";


require_once(__DIR__."/common.php");


if (isset($GLOBALS["gameRequest"])) {
    $currentEvent = $GLOBALS["gameRequest"][0] ?? '';
    $currentActor = $GLOBALS["HERIKA_NAME"] ?? 'unknown';

    // BACKLOG FIX — Scene end early detection.
    // preprocessing.php runs BEFORE the semaphore (main.php:181 vs semaphore at main.php:221).
    // When chatnf_sl_end arrives, chatnf_sl events from the scene are already PAST preprocessing
    // and waiting for the semaphore. By setting pillow_talk_pending=true in the DB here (before
    // the semaphore), the existing prerequest.php:139-188 pillow_talk system fires for those
    // stale events when they acquire the semaphore — converting moaning to post-scene dialogue.
    if ($currentEvent === 'chatnf_sl_end') {
        @file_put_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt", time());

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
                      OR (extended_data->'aiagent_nsfw_intimacy_data'->>'scene_phase') IS NOT NULL
                  )
            ");
        } catch (Exception $e) {
            error_log("[AIAGENTNSFW] Failed to set early pillow talk: " . $e->getMessage());
        }
    }

    // Mark scene as active when we get scene events
    if (in_array($currentEvent, ['ext_nsfw_sexcene', 'info_sexscene', 'chatnf_sl', 'chatnf_sl_nr'])) {
        @file_put_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt", time());
    }

    // Block stale sex-scene events that arrive AFTER the scene has ended (future HTTP requests
    // that haven't started processing yet). Compares nsfw_scene_ended.txt vs nsfw_scene_active.txt:
    //   scene_ended newer → scene over, no new scene started → BLOCK immediately
    //   scene_active newer (ext_nsfw_sexcene fired for new scene) → allow through
    // 60s TTL is a fallback; handleSceneEnd() clears the file explicitly on proper scene end.
    $staleSceneEventTypes = ['chatnf_sl', 'chatnf_sl_moan', 'chatnf_sl_climax'];
    if (in_array($currentEvent, $staleSceneEventTypes)) {
        $sceneEndedFile = sys_get_temp_dir() . "/nsfw_scene_ended.txt";
        $sceneEndedRaw  = @file_get_contents($sceneEndedFile);
        if ($sceneEndedRaw !== false) {
            $sceneEndedTime  = (int)$sceneEndedRaw;
            $sceneActiveTime = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt") ?: 0);
            if ($sceneEndedTime > $sceneActiveTime && (time() - $sceneEndedTime) < 60) {
                $GLOBALS["gameRequest"][0] = "nsfw_blocked_scene_ended";
            }
        }
    }

    // Block ALL rechat/narration while any scene is active.
    // Uses nsfw_scene_active.txt file marker (written by scene events).
    // NOTE: HERIKA_NAME is NOT set to the correct NPC in preprocessing — it's the
    // default from conf.php. So we CANNOT do per-actor checks here. Instead we
    // block ALL rechat/narration during scenes. This prevents event backlog that
    // clogs the main semaphore and delays scene/orgasm processing.
    if (in_array($currentEvent, ['rechat', 'narration'])) {
        $blockRechat = _getNsfwSetting('BLOCK_RECHAT_IN_SCENE', true);
        if ($blockRechat) {
            $blockTimeout = _getNsfwSetting('BLOCK_RECHAT_TIMEOUT', 300);
            $sceneActiveFile = sys_get_temp_dir() . "/nsfw_scene_active.txt";
            $sceneActiveTime = @file_get_contents($sceneActiveFile);
            if ($sceneActiveTime !== false && (time() - (int)$sceneActiveTime) < $blockTimeout) {
                $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
            }
        }
    }

    // Physics cooldown (outside scenes only - scene blocking handled by Papyrus routing)
    $physicsEvents = ['ext_nsfw_physics_raw', 'ext_nsfw_physics'];
    if (in_array($currentEvent, $physicsEvents)) {
        $rawData = $GLOBALS["gameRequest"][3] ?? '';
        $parts = explode('^', $rawData);
        $isGrab = (($parts[2] ?? 'touch') === 'grab');
        $cooldownSeconds = $isGrab ? 10 : 180;
        $cooldownFile = sys_get_temp_dir() . "/nsfw_physics_" . ($isGrab ? 'grab_' : 'touch_') . md5($currentActor) . ".txt";
        $lastTime = @file_get_contents($cooldownFile);
        if ($lastTime !== false && (time() - (int)$lastTime) < $cooldownSeconds) {
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
        } else {
            @file_put_contents($cooldownFile, time());
        }
    }

    // Rewrite info_sexscene FIRST so dedup catches both event names
    if ($currentEvent === 'info_sexscene' || $GLOBALS["gameRequest"][0] === 'info_sexscene') {
        $GLOBALS["gameRequest"][0] = "ext_nsfw_sexcene";
        $currentEvent = "ext_nsfw_sexcene";
    }

    // Scene dedup - OStim fires scene events repeatedly (~500ms)
    // Hash-based: process each unique scene data ONCE, block all repeats.
    // When OStim changes position/animation, the data changes → new hash → passes through.
    if ($currentEvent === 'ext_nsfw_sexcene') {
        $sceneHash = md5($GLOBALS["gameRequest"][3] ?? '');
        $dedupFile = sys_get_temp_dir() . "/nsfw_scene_last_hash.txt";
        $lastHash = @file_get_contents($dedupFile);
        if ($lastHash === $sceneHash) {
            // Same scene data as last processed event — block completely
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_duplicate";
        } else {
            // New scene data (position change or new scene) — process it
            @file_put_contents($dedupFile, $sceneHash);
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
        }

        // If we couldn't parse it, use the profile hash for per-request cooldown
        if (empty($orgasmActorName)) {
            $orgasmActorName = $_GET["profile"] ?? 'fallback';
            error_log("[AIAGENT-NSFW] Orgasm cooldown: couldn't parse actor name, using profile: $orgasmActorName");
        }

        $cooldownFile = sys_get_temp_dir() . "/nsfw_climax_cooldown_" . md5($orgasmActorName) . ".txt";
        $lastTime = @file_get_contents($cooldownFile);
        $lastTime = $lastTime !== false ? (int)$lastTime : 0;
        $timeSinceLast = time() - $lastTime;

        error_log("[AIAGENT-NSFW] Orgasm cooldown check for '$orgasmActorName': {$timeSinceLast}s since last (cooldown: {$climaxCooldown}s)");

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
            if ($result) {
                $GLOBALS["gameRequest"][0] = "chatnf_physics";
                $GLOBALS["gameRequest"][3] = $result['message'];
            }
        }
    }

    // On game load (init), clear stale scene state and future intimacy data
    if ($GLOBALS["gameRequest"][0]=="init") {
        $saveTimestamp = $GLOBALS["gameRequest"][2] ?? 0;

        // Clear active scene data - OStim scenes don't persist across save/load
        try {
            $GLOBALS["db"]->execQuery("
                UPDATE nsfw_npc_data
                SET extended_data = jsonb_set(
                    jsonb_set(
                        jsonb_set(
                            jsonb_set(
                                jsonb_set(
                                    jsonb_set(
                                        extended_data,
                                        '{aiagent_nsfw_intimacy_data,level}', '0'::jsonb, false
                                    ),
                                    '{aiagent_nsfw_intimacy_data,scene_actors}', 'null'::jsonb, false
                                ),
                                '{aiagent_nsfw_intimacy_data,scene_phase}', 'null'::jsonb, false
                            ),
                            '{aiagent_nsfw_intimacy_data,scene_start_time}', 'null'::jsonb, false
                        ),
                        '{aiagent_nsfw_intimacy_data,is_npc_scene}', 'false'::jsonb, false
                    ),
                    '{aiagent_nsfw_intimacy_data,npc_scene_partner}', 'null'::jsonb, false
                )
                WHERE extended_data IS NOT NULL
                  AND extended_data ? 'aiagent_nsfw_intimacy_data'
                  AND (
                      (extended_data->'aiagent_nsfw_intimacy_data'->>'level')::int > 0
                      OR extended_data->'aiagent_nsfw_intimacy_data' ? 'scene_actors'
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
    if (in_array($GLOBALS["gameRequest"][0], ['nsfw_blocked_cooldown', 'nsfw_blocked_duplicate', 'nsfw_blocked_scene_ended'])) {
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