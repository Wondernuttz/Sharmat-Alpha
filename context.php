<?php

// This is called when NPC context is built, before funrect handling

// ============================================
// PROCESS NSFW PROFILE GENERATION QUEUE
// ============================================
// Process 1-2 queued NSFW profiles per request cycle.
// This prevents rate limiting when many NPCs need profiles.
// Queue is populated by prerequest.php with full game context.
// ============================================
try {
    require_once __DIR__ . '/nsfw_profile_queue.php';

    // Only process queue if not in a time-critical path (e.g., not during sex scene)
    $isSexScene = in_array($GLOBALS["gameRequest"][0] ?? '', ['chatnf_sl', 'chatnf_sl_end', 'chatnf_sl_climax', 'ext_nsfw_action']);

    if (!$isSexScene) {
        $queueResult = _nsfwProcessQueue(2); // Process up to 2 per request
        if ($queueResult['processed'] > 0) {
            error_log("[AIAGENTNSFW] Queue: Processed {$queueResult['processed']} profiles");
        }
        if ($queueResult['abandoned'] > 0) {
            error_log("[AIAGENTNSFW] Queue: Abandoned {$queueResult['abandoned']} after max retries");
        }
    }
} catch (Exception $e) {
    // Queue processing is non-critical - don't break context building
    error_log("[AIAGENTNSFW] Queue processing error: " . $e->getMessage());
}

if (isset($GLOBALS["AIAGENTNSFW_FORCE_STOP"]) &&  $GLOBALS["AIAGENTNSFW_FORCE_STOP"]) {

    if ($gameRequest[0]=="ext_nsfw_action") {    // This was changed by processInfoSexScene

        $actor=$GLOBALS["HERIKA_NAME"];
        $intimacyStatus=getIntimacyForActor($actor);
        if (!isset($intimacyStatus["orgasm_generated"]) || $intimacyStatus["orgasm_generated"]==false) {
            // Check if slave - inject slave-specific climax prompt
            if (isNpcSlave($actor)) {
                try {
                    $relationship = RelationshipManager::getPlayerRelationship($actor);
                    $slaveAffinity = $relationship['aff'] ?? 0;
                    $ownerName = $GLOBALS['PLAYER_NAME'] ?? 'Owner';
                    $slaveClimaxPrompt = NsfwRelationship::getSlaveClimaxPrompt($slaveAffinity, $ownerName);
                    if (!empty($slaveClimaxPrompt)) {
                        $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . NsfwRelationship::wrapXml('slave_climax', $slaveClimaxPrompt);
                        error_log("[AIAGENTNSFW] Injected SLAVE climax prompt for $actor (affinity: $slaveAffinity)");
                    }
                } catch (Exception $e) {
                    error_log("[AIAGENTNSFW] Failed to get slave climax prompt: " . $e->getMessage());
                }
            }
            generateClimaxSpeech();

        } else {
            error_log("Orgams sound already generated");

        }

        terminate();

    
    } else {
        error_log(print_r($gameRequest,true));


    }

    // Don't do LLM request if some conditions unmet.
    Logger::info("Stopping processing  {$GLOBALS["gameRequest"][0]}");

    terminate();


}


// Minor change son context for special cases.

if ($GLOBALS["gameRequest"][0]=="chatnf_sl_end") {

    $GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"]=false;
    $GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"]="";
    // Remove player request (last entry line)...useless
    array_pop($GLOBALS["contextDataFull"]);

    // Inject post-scene guidance for configured NPCs
    $actor = $GLOBALS["HERIKA_NAME"];
    $currentIntimacy = getIntimacyForActor($actor);

    // Check if this was a prostitute transaction
    $wasTransaction = !empty($currentIntimacy["is_transaction"]);
    $paymentConfirmed = !empty($currentIntimacy["payment_confirmed"]);
    $serviceCompleted = !empty($currentIntimacy["service_completed"]);

    $postScenePrompt = "";

    // Check NPC type priority: Slave > Prostitute > Regular
    if (isNpcSlave($actor)) {
        // SLAVE POST-SCENE (PILLOW TALK)
        try {
            $relationship = RelationshipManager::getPlayerRelationship($actor);
            $slaveAffinity = $relationship['aff'] ?? 0;
            $ownerName = $GLOBALS['PLAYER_NAME'] ?? 'Owner';
            $slavePillowTalk = NsfwRelationship::getSlavePillowTalkPrompt($slaveAffinity, $ownerName);
            if (!empty($slavePillowTalk)) {
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . NsfwRelationship::wrapXml('slave_pillow_talk', $slavePillowTalk);
                error_log("[AIAGENTNSFW] Injected SLAVE pillow talk for $actor (affinity: $slaveAffinity)");
            }
        } catch (Exception $e) {
            error_log("[AIAGENTNSFW] Failed to get slave pillow talk: " . $e->getMessage());
            // Fallback to generic slave prompt
            $postScenePrompt = "The intimate moment has ended. As a slave, you await further instructions or dismissal.";
        }
    } else if ($wasTransaction) {
        // PROSTITUTE POST-SERVICE TALK
        if ($paymentConfirmed) {
            $postScenePrompt = "The service session has ended. You were paid. Give a brief professional farewell.";
        } else {
            $postScenePrompt = "The service session ended but payment was NOT confirmed. You may remind the client about payment.";
        }
        error_log("[AIAGENTNSFW] Post-service context for prostitute (paid: " . ($paymentConfirmed ? 'yes' : 'no') . ")");
    } else {
        // REGULAR NPC PILLOW TALK
        $postScenePrompt = "The intimate moment has ended. Share a brief, genuine post-intimacy reaction in character.";
        error_log("[AIAGENTNSFW] Pillow talk context for regular NPC");
    }

    // Inject into personality for configured NPCs (only if not slave - slave uses XML wrapper above)
    if (!empty($postScenePrompt) && !isNpcSlave($actor)) {
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n#Post-Scene: " . $postScenePrompt;
    }
}

if ($GLOBALS["gameRequest"][0]=="chatnf_sl") {
  
    // Remove player request (last entry line)...useless
    array_pop($GLOBALS["contextDataFull"]);

}


// Drunk status handling

$actorName=$GLOBALS["HERIKA_NAME"];
$npcManager=new NpcMaster();
$npcData=$npcManager->getByName($actorName);
$extended_data=$npcManager->getExtendedData($npcData);

if (in_array(getLastIssuedMood($GLOBALS["HERIKA_NAME"],$GLOBALS["gameRequest"][2]),["drunk","tipsy"])) {
    error_log("Forcing drunk mood: {$GLOBALS["HERIKA_NAME"]} {$GLOBALS["gameRequest"][2]}");
    $GLOBALS["FORCE_MOOD"]="drunk";
    $GLOBALS["EMOTEMOODS"]="drunk"; // Can be overwriten by LLM
    $GLOBALS["TTS_FFMPEG_FILTERS"]["tempo"]='atempo=0.65';//Force the ffmpeg filter
    $extended_data["aiagent_nsfw_last_time_drunk"]=$GLOBALS["gameRequest"][2];
} else {
    if (isset($extended_data["aiagent_nsfw_last_time_drunk"])) {
        unset($extended_data["aiagent_nsfw_last_time_drunk"]);
        $GLOBALS["FORCE_MOOD"]="sober";
        $GLOBALS["EMOTEMOODS"]="sober"; // Can be overwriten by LLM
    }
}

$npcData=$npcManager->setExtendedData($npcData,$extended_data);
$npcManager->updateByArray($npcData);

// Add note if player is naked
if (playerIsNaked()) {
    error_log("[AIAGENTNSFW] Player is naked");
    $GLOBALS["contextDataFull"][0]["content"].="\n#Note: {$GLOBALS["PLAYER_NAME"]} is nude, not wearing clothes\n";

}
?>