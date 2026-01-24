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


require_once(__DIR__."/common.php");


if (isset($GLOBALS["gameRequest"])) {
    $currentEvent = $GLOBALS["gameRequest"][0] ?? '';
    $currentActor = $GLOBALS["HERIKA_NAME"] ?? 'unknown';

    // Mark scene as active when we get scene events
    if (in_array($currentEvent, ['ext_nsfw_sexcene', 'info_sexscene', 'chatnf_sl', 'chatnf_sl_nr'])) {
        @file_put_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt", time());
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

    // Scene dedup - OStim fires ostim_scenechanged twice
    if ($currentEvent === 'ext_nsfw_sexcene') {
        $sceneHash = md5($GLOBALS["gameRequest"][3] ?? '');
        $dedupFile = sys_get_temp_dir() . "/nsfw_scene_dedup_" . $sceneHash . ".txt";
        $lastTime = @file_get_contents($dedupFile);
        $lastTime = $lastTime !== false ? (float)$lastTime : 0;
        $now = microtime(true);
        if (($now - $lastTime) < 1.0) {
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_duplicate";
        } else {
            @file_put_contents($dedupFile, $now);
        }
    }

    // Scene dialogue cooldown
    if (in_array($currentEvent, ['chatnf_sl', 'chatnf_sl_nr'])) {
        require_once(__DIR__."/prompts.php");
        $sceneCooldown = _getNsfwSetting('COOLDOWN_SEX_SCENE', 15);
        $cooldownFile = sys_get_temp_dir() . "/nsfw_scene_dialogue_" . md5($currentActor) . ".txt";
        $lastTime = @file_get_contents($cooldownFile);
        $lastTime = $lastTime !== false ? (int)$lastTime : 0;
        if ((time() - $lastTime) < $sceneCooldown) {
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
        } else {
            @file_put_contents($cooldownFile, time());
        }
    }

    // Orgasm/climax handling - cooldown only (speak style prompt injection happens in prompts.php)
    if (in_array($currentEvent, ['ext_nsfw_orgasm', 'ext_nsfw_npc_orgasm', 'chatnf_sl_climax'])) {
        require_once(__DIR__."/prompts.php");  // Loads _getNsfwSetting()
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

    // Rewrite info_sexscene to ext_nsfw_sexcene
    if ($GLOBALS["gameRequest"][0] == "info_sexscene") {
        $GLOBALS["gameRequest"][0] = "ext_nsfw_sexcene";
    }

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
    if (in_array($GLOBALS["gameRequest"][0], ['nsfw_blocked_cooldown', 'nsfw_blocked_duplicate'])) {
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