<?php

// This is called at the very beginning, before any context is created
// Add info_sexscene to external_fast_commands for non-locking processing

$GLOBALS["external_fast_commands"][]="ext_nsfw_sexcene";
$GLOBALS["external_fast_commands"][]="ext_nsfw_npc_scene";  // NPC-to-NPC scenes (OStim NPCs)
$GLOBALS["external_fast_commands"][]="fertility_notification";
$GLOBALS["external_fast_commands"][]="ext_vr_item_raw";  // VR item pickup/drop (HIGGS)
$GLOBALS["external_fast_commands"][]="ext_vr_item_pickup";  // Rewritten from ext_vr_item_raw
$GLOBALS["external_fast_commands"][]="ext_vr_item_drop";    // Rewritten from ext_vr_item_raw
$GLOBALS["external_fast_commands"][]="ext_nsfw_physics_raw";  // HIGGS grab / CBPC touch on body parts


require_once(__DIR__."/common.php");


if (isset($GLOBALS["gameRequest"])) {
    // Rewrite info_sexscene to ext_nsfw_sexcene for scene processing
    // Papyrus sends info_sexscene, but our handler expects ext_nsfw_sexcene
    if ($GLOBALS["gameRequest"][0] == "info_sexscene") {
        error_log("Rewriting info_sexscene data " . $GLOBALS["gameRequest"][3]);
        $GLOBALS["gameRequest"][0] = "ext_nsfw_sexcene";
    }

    // Main
    // Disposal data should be handled by CHIM engine.
    // On game load (init), clear "future" intimacy state to prevent context bleed
    if ($GLOBALS["gameRequest"][0]=="init") {
        // Get the save timestamp from the game request
        $saveTimestamp = $GLOBALS["gameRequest"][2] ?? 0;

        // Clear NSFW intimacy data only for NPCs where the intimacy gamets is in "the future"
        // This prevents NPCs from "remembering" intimacy from a different timeline
        try {
            $clearResult = $GLOBALS["db"]->execQuery("
                UPDATE core_npc_master
                SET extended_data = extended_data::jsonb - 'aiagent_nsfw_intimacy_data'
                WHERE extended_data IS NOT NULL
                  AND extended_data::jsonb ? 'aiagent_nsfw_intimacy_data'
                  AND (
                      (extended_data::jsonb->'aiagent_nsfw_intimacy_data'->>'gamets')::float > $saveTimestamp
                      OR (extended_data::jsonb->'aiagent_nsfw_intimacy_data'->>'gamets') IS NULL
                  )
            ");
            error_log("[AIAGENTNSFW] Game load (init): Cleared future intimacy state (gamets > $saveTimestamp)");
        } catch (Exception $e) {
            error_log("[AIAGENTNSFW] Error clearing future intimacy data on init: " . $e->getMessage());
        }
    }

    if ($GLOBALS["gameRequest"][0]=="infosave") {


    }
}

// Hook to inject FMR fertility prompts into personality context
// This runs after prompts.php is loaded and gives the AI behavioral guidance
$GLOBALS["HOOKS"]["PERSONALITY_BUILDER"]["fmr_fertility_prompt"]=function($currentPersonality, $currentNpcData) {
    // Check if fertility tracking is enabled in settings
    if (function_exists('isFertilityTrackingEnabled') && !isFertilityTrackingEnabled()) {
        return $currentPersonality;
    }

    // Only inject if we have NPC data with fertility state
    if (!$currentNpcData || !isset($currentNpcData["extended_data"])) {
        return $currentPersonality;
    }

    $extended = json_decode($currentNpcData["extended_data"], true);
    if (!$extended) return $currentPersonality;

    // Check if prompts.php has been loaded (has the getFertilityPromptForNpc function)
    if (!function_exists('getFertilityPromptForNpc')) {
        // Prompts.php not loaded yet - this is preprocessing, will be loaded later
        // Store for later injection via the BIOGRAPHY_BUILDER hook instead
        return $currentPersonality;
    }

    $npcName = $currentNpcData["npc_name"] ?? ($GLOBALS["HERIKA_NAME"] ?? "She");
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

     $extended = json_decode($currentNpcData["extended_data"], true);
     if (!$extended) return $currentBio;

     $npcName = $currentNpcData["npc_name"];
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