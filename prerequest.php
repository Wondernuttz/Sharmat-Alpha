<?php 

// This is called after NPC profile is loaded.

require_once(__DIR__."/common.php");


// Read animations/stages descriptions from file


// Main code
// Will update intimacyStatus every iteration here

$GLOBALS["EMOTEMOODS"].=",flirty";// Gonna track this mood to manage sex_disposal


// Check current intimacy level
$codeName = npcNameToCodename($GLOBALS["HERIKA_NAME"]);
$intimacyStatus=getIntimacyForActor($GLOBALS["HERIKA_NAME"]);

if (!isset($intimacyStatus["level"]))
    $intimacyStatus["level"]=0;

// Process AIAgentNSFW events
processInfoSexScene();

processInfoPhysics();

processInfoVRItems();

processInfoFertility();

// Reload
$intimacyStatus=getIntimacyForActor($GLOBALS["HERIKA_NAME"]);

// ============================================
// PILLOW TALK INJECTION (POST-SCENE)
// ============================================
// When scene ends, pillow_talk_pending is set for ALL NPCs
// Inject their personalized pillow talk prompt on their next request
// ============================================
if (!empty($intimacyStatus["pillow_talk_pending"]) && !empty($intimacyStatus["pillow_talk_prompt"])) {
    $pillowTalkPrompt = $intimacyStatus["pillow_talk_prompt"];
    $GLOBALS["HERIKA_PERSONALITY"] .= "\n<post_scene_instruction>\nIMPORTANT: The intimate scene has ENDED. You are NO LONGER having sex. STOP all orgasm expressions like 'Oh gods', 'CUMMING', 'YES!', moaning, etc. Return to normal conversation immediately. The physical act is completely over.\n{$pillowTalkPrompt}\n</post_scene_instruction>";
    error_log("[AIAGENTNSFW] Injected pillow talk for {$GLOBALS["HERIKA_NAME"]}");

    // Clear the pillow talk flag so it only fires once
    $intimacyStatus["pillow_talk_pending"] = false;
    $intimacyStatus["pillow_talk_prompt"] = "";
    updateIntimacyForActor($GLOBALS["HERIKA_NAME"], $intimacyStatus);
}

if ($codeName=="the_narrator") {
    //no further procsssing needed
    return;
}

// From here should apply to profiled actors

// Disposal modifiers per iteration
// Every iteration we lower sex_disposal by 1
if (isset($intimacyStatus["sex_disposal"])) {
    if ($intimacyStatus["sex_disposal"]>0) {
        
        $intimacyStatus["sex_disposal"]=$intimacyStatus["sex_disposal"]-1;
        error_log("Lowering sex_disposal {$intimacyStatus["sex_disposal"]}");
    } else if ($intimacyStatus["sex_disposal"]<1) {
        
        $intimacyStatus["sex_disposal"]=-1;
        error_log("Limting sex_disposal {$intimacyStatus["sex_disposal"]}");
    }
} else {
    $intimacyStatus["sex_disposal"]=0;
    $intimacyStatus["level"]=0;
    error_log("Resetting sex_disposal {$intimacyStatus["sex_disposal"]}");

}

$actorName=$GLOBALS["HERIKA_NAME"];
$npcManager=new NpcMaster();
$npcData=$npcManager->getByName($actorName);
$extended_data=$npcManager->getExtendedData($npcData);
$metadata=$npcManager->getMetadata($npcData);

// ============================================
// AUTO-GENERATE NSFW PROFILES (QUEUED)
// ============================================
// Queue NPC for NSFW profile generation instead of fire-and-forget.
// This prevents rate limiting when entering areas with many NPCs.
// The queue is processed 1-2 at a time in context.php.
//
// Gathers FULL game context so the LLM knows if NPC is:
// - A slave (and their affinity towards owner)
// - A prostitute (from mods or occupation)
// - Married, in a relationship, etc.
// ============================================
try {
    require_once __DIR__ . '/nsfw_profile_queue.php';

    // Check if auto-generate is enabled
    $nsfwSettingsRow = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_settings'");
    $nsfwSettings = [];
    if ($nsfwSettingsRow && !empty($nsfwSettingsRow['value'])) {
        $nsfwSettings = json_decode($nsfwSettingsRow['value'], true) ?: [];
    }

    $autoGenerateEnabled = !empty($nsfwSettings['AUTO_GENERATE_NSFW_PROFILES']);

    if ($autoGenerateEnabled && $npcData) {
        // Check if NPC already has NSFW profile (has 'source' field)
        $hasNsfwProfile = isset($extended_data['source']) && !empty($extended_data['source']);

        // Also check if we already queued/attempted generation recently (within last hour)
        $generationAttempted = isset($extended_data['nsfw_generation_attempted'])
            && (time() - $extended_data['nsfw_generation_attempted']) < 3600;

        // Check if already in queue
        $alreadyQueued = _nsfwIsInQueue($actorName);

        if (!$hasNsfwProfile && !$generationAttempted && !$alreadyQueued) {
            // Simple child/animal check inline
            $nameLower = strtolower(trim($actorName));
            $blockedNames = ['babette', 'erith', 'blaise', 'lucia', 'mila valentia', 'samuel', 'sofie', 'aventus aretino', 'braith', 'bear', 'wolf', 'dragon', 'spider', 'skeever', 'mudcrab', 'horse', 'dog', 'chicken', 'cow'];
            $isBlocked = in_array($nameLower, $blockedNames) || preg_match('/(dragon|spider|wolf|bear|troll|skeleton|ghost|draugr)$/i', $nameLower);

            if (!$isBlocked) {
                // Build FULL game context for the LLM
                $gameContext = [
                    'player_name' => $GLOBALS['PLAYER_NAME'] ?? null,
                    'current_location' => $GLOBALS['CURRENT_LOCATION'] ?? null,
                ];

                // Check if NPC is a SLAVE (from relationship system or extended data)
                $gameContext['is_slave'] = false;
                if (function_exists('isNpcSlave') && isNpcSlave($actorName)) {
                    $gameContext['is_slave'] = true;
                    $gameContext['slave_owner'] = $GLOBALS['PLAYER_NAME'] ?? 'the player';

                    // Get slave affinity from relationship system
                    if (class_exists('RelationshipManager')) {
                        try {
                            $slaveRel = RelationshipManager::getPlayerRelationship($actorName);
                            $gameContext['slave_affinity'] = $slaveRel['aff'] ?? 0;
                        } catch (Exception $e) {
                            $gameContext['slave_affinity'] = 0;
                        }
                    }
                }

                // Check if NPC is a PROSTITUTE (from mods or extended data)
                $gameContext['is_prostitute'] = false;
                $gameContext['is_courtesan'] = false;

                if (!empty($extended_data['is_prostitute']) || !empty($extended_data['profession_prostitute'])) {
                    $gameContext['is_prostitute'] = true;
                }

                // Check courtesan mods
                if (is_array($metadata["mods"] ?? null)) {
                    $prostituteMods = ["The Naked DragonSSE.esp", "prostitutes.esp"];
                    foreach ($prostituteMods as $mod) {
                        if (in_array($mod, $metadata["mods"])) {
                            $gameContext['is_courtesan'] = true;
                            $gameContext['is_prostitute'] = true;
                            break;
                        }
                    }
                    // Store mod source for context
                    $gameContext['mod_source'] = implode(', ', $metadata["mods"]);
                }

                // Get relationship with player if available
                if (class_exists('RelationshipManager')) {
                    try {
                        $playerRel = RelationshipManager::getPlayerRelationship($actorName);
                        if ($playerRel) {
                            $affinity = $playerRel['aff'] ?? 0;
                            $trust = $playerRel['trust'] ?? 50;
                            if ($affinity > 75 && $trust > 75) {
                                $gameContext['relationship_with_player'] = 'Deeply in love/devoted';
                            } else if ($affinity > 50) {
                                $gameContext['relationship_with_player'] = 'Very fond/attracted';
                            } else if ($affinity > 25) {
                                $gameContext['relationship_with_player'] = 'Friendly/warm';
                            } else if ($affinity > -25) {
                                $gameContext['relationship_with_player'] = 'Neutral';
                            } else if ($affinity > -50) {
                                $gameContext['relationship_with_player'] = 'Dislikes';
                            } else {
                                $gameContext['relationship_with_player'] = 'Hates';
                            }
                        }
                    } catch (Exception $e) {
                        // Relationship system not available
                    }
                }

                // Queue for generation (processed 1-2 at a time in context.php)
                _nsfwQueueProfileGeneration($actorName, $gameContext);
                error_log("[AIAGENT_NSFW] Queued NSFW profile generation for: {$actorName} (slave: " . ($gameContext['is_slave'] ? 'yes' : 'no') . ", prostitute: " . ($gameContext['is_prostitute'] ? 'yes' : 'no') . ")");

                // Mark that we've queued to avoid repeated queue additions
                $extended_data['nsfw_generation_attempted'] = time();
                $npcManager->setExtendedData($npcData, $extended_data);
                $npcManager->save($npcData);
            }
        }
    }
} catch (Exception $e) {
    error_log("[AIAGENT_NSFW] Auto-generate queue failed: " . $e->getMessage());
}

// Detect
$modsToCheck=[
    "The Naked DragonSSE.esp",
    "prostitutes.esp"
];

$isCourtesan=false;
if (is_array($metadata["mods"])) {
    foreach ($modsToCheck as $mod) {
        $isCourtesan=$isCourtesan||in_array($mod,$metadata["mods"]);
    }
}


// Prostitutes always have sex disposal over 19
if ($isCourtesan) {    // Need npc table with tags here
    $intimacyStatus["sex_disposal"]=($intimacyStatus["sex_disposal"]<20)?20: $intimacyStatus["sex_disposal"];
    $intimacyStatus["adult_entertainment_services_autodetected"]=true;

} else {
    $intimacyStatus["adult_entertainment_services_autodetected"]=false;
}


// Arousal from NPC data or profile.

if (isset($GLOBALS["AIAGENT_NSFW_DEFAULT_AROUSAL"]) && $GLOBALS["AIAGENT_NSFW_DEFAULT_AROUSAL"]) {    // Need npc table with tags here
    $intimacyStatus["sex_disposal"]=($intimacyStatus["sex_disposal"]<$GLOBALS["AIAGENT_NSFW_DEFAULT_AROUSAL"])?$GLOBALS["AIAGENT_NSFW_DEFAULT_AROUSAL"]: $intimacyStatus["sex_disposal"];

}

// If current task is relax we increase sex disposal every itteration
$currentTask=DataGetCurrentTask();
if (strpos($currentTask,"relax")!==false) {
    $intimacyStatus["sex_disposal"]+=2;
    error_log("Increasing sex_disposal {$intimacyStatus["sex_disposal"]} because relax mode");
}

// Speech mood modifier

$moodModif=getSexDisposalFromMood($GLOBALS["HERIKA_NAME"],$GLOBALS["gameRequest"][2]);
if ($moodModif>0.45) 
    $intimacyStatus["sex_disposal"]+=2;
else if ($moodModif<0) 
    $intimacyStatus["sex_disposal"]-=2;


// ============================================
// PROSTITUTE PAYMENT AGREEMENT DETECTION
// ============================================
// When a prostitute has requested payment (awaiting_agreement=true),
// detect if player agreed. If so, take gold and confirm payment.
// ============================================
if (!empty($intimacyStatus["awaiting_agreement"]) && !empty($intimacyStatus["payment_amount"])) {
    // Get what the player said
    $playerMessage = $GLOBALS["gameRequest"][2] ?? "";
    $playerMessageLower = strtolower($playerMessage);

    // Check for agreement phrases
    $agreementPhrases = [
        'yes', 'yeah', 'yep', 'ok', 'okay', 'sure', 'fine', 'deal', 'agreed',
        'alright', 'accept', 'pay', 'here', 'take it', 'sounds good',
        'that works', 'fair', 'done', 'you got it', 'of course'
    ];

    $playerAgreed = false;
    foreach ($agreementPhrases as $phrase) {
        if (strpos($playerMessageLower, $phrase) !== false) {
            $playerAgreed = true;
            break;
        }
    }

    // Check for refusal phrases (overrides agreement)
    $refusalPhrases = ['no', 'nope', 'never', 'refuse', 'too much', 'expensive', 'forget it', 'nevermind'];
    foreach ($refusalPhrases as $phrase) {
        if (strpos($playerMessageLower, $phrase) !== false) {
            $playerAgreed = false;
            break;
        }
    }

    if ($playerAgreed) {
        $paymentAmount = intval($intimacyStatus["payment_amount"]);
        $serviceDesc = $intimacyStatus["payment_service"] ?? "services";
        $npcName = $GLOBALS["HERIKA_NAME"];

        error_log("[AIAGENTNSFW] Player agreed to pay {$paymentAmount} gold for {$serviceDesc}");

        // Use PaymentHandler to process the payment via ScriptProxy
        // This directly removes gold from player and adds to NPC
        require_once(__DIR__ . '/payment_handler.php');
        $paymentHandler = new PaymentHandler();
        $result = $paymentHandler->processPayment($npcName, $paymentAmount, 'gold', $serviceDesc);

        if ($result['success']) {
            // Mark payment as confirmed
            $intimacyStatus["payment_confirmed"] = true;
            $intimacyStatus["awaiting_agreement"] = false;
            $intimacyStatus["negotiation_phase"] = false;
            $intimacyStatus["payment_completed_amount"] = $paymentAmount;

            // Add context for NPC response
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n<payment_received>{$GLOBALS['PLAYER_NAME']} just paid you {$paymentAmount} gold for {$serviceDesc}. You can now proceed with the agreed services.</payment_received>";

            error_log("[AIAGENTNSFW] Payment confirmed: {$paymentAmount} gold for {$serviceDesc}");
        } else {
            error_log("[AIAGENTNSFW] Payment processing failed: " . ($result['error'] ?? 'unknown error'));
        }
    }
}

// ============================================
// NEW PHASE-BASED SCENE FLOW
// ============================================
// Phase: "tier_prompt" → Tier prompt fires, model accepts/refuses
// Phase: "accepted"    → Arousal check, styles inject if ready
// Phase: "engaged"     → Full scene, all styles active
//
// Level: 0 = Not in scene or tier_prompt phase
// Level: 1 = Accepted, pre-intimate (foreplay)
// Level: 2 = Engaged, full scene
// ============================================

// Determine NPC type for path selection
// PREFER stored values from scene start (nsfw_ostim_handler.php) - this ensures
// each actor gets their correct tier prompt even if they're not the first speaker
$isSlave = isset($intimacyStatus["npc_is_slave"]) ? $intimacyStatus["npc_is_slave"] : isNpcSlave($actorName);
$isProstitute = isset($intimacyStatus["npc_is_prostitute"]) ? $intimacyStatus["npc_is_prostitute"] :
                (!empty($extended_data['is_prostitute']) ||
                !empty($extended_data['profession_prostitute']) ||
                $isCourtesan);
$isAffair = false; // Will be set by tier prompt logic

// Get affinity for slave/relationship prompts
// PREFER stored value from scene start for consistency
$slaveAffinity = 0;
$slaveOwnerName = $GLOBALS['PLAYER_NAME'] ?? 'Owner';
if ($isSlave) {
    if (isset($intimacyStatus["slave_affinity"])) {
        $slaveAffinity = $intimacyStatus["slave_affinity"];
        error_log("[AIAGENTNSFW] Using stored slave affinity for $actorName: $slaveAffinity");
    } else {
        try {
            $relationship = RelationshipManager::getPlayerRelationship($actorName);
            $slaveAffinity = $relationship['aff'] ?? 0;
            error_log("[AIAGENTNSFW] Slave affinity for $actorName: $slaveAffinity");
        } catch (Exception $e) {
            error_log("[AIAGENTNSFW] Failed to get slave affinity: " . $e->getMessage());
        }
    }
}

// Get scene phase
$scenePhase = $intimacyStatus["scene_phase"] ?? null;

// Handle phase transitions
// Note: Check tier_prompt_sent to ensure we only inject tier prompt ONCE
// On subsequent requests, we fall through to the "accepted" block
if ($scenePhase === "tier_prompt" && !isset($intimacyStatus["tier_prompt_sent"])) {
    // ============================================
    // PHASE 1: TIER PROMPT (Accept/Refuse)
    // ============================================
    // Inject ONLY the tier prompt - no styles, no scene cues
    // Model responds, and we check for acceptance on next request
    // ============================================
    error_log("[AIAGENTNSFW] Phase: tier_prompt for $actorName");

    // Inject tier prompt based on NPC type
    if ($isSlave) {
        // SLAVE: Use slave-specific tier prompt (always complies, affinity affects emotion)
        $slaveTierPrompt = NsfwRelationship::getSlaveTierPrompt($slaveAffinity, $slaveOwnerName);
        if (!empty($slaveTierPrompt)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . NsfwRelationship::wrapXml('slave_context', $slaveTierPrompt);
            error_log("[AIAGENTNSFW] Injected SLAVE tier prompt for $actorName (affinity: $slaveAffinity)");
        }
    } else if ($isProstitute) {
        // PROSTITUTE: Inject negotiation context with PRICE LIST
        // This includes services menu, pricing, and payment instructions
        $clientName = $GLOBALS["PLAYER_NAME"] ?? "client";
        $affinity = 0;
        try {
            $relationship = RelationshipManager::getPlayerRelationship($actorName);
            $affinity = $relationship['aff'] ?? 0;
        } catch (Exception $e) {
            // Use default 0
        }
        $negotiationContext = buildProstituteNegotiationContext($actorName, $clientName, $affinity);
        if (!empty($negotiationContext)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $negotiationContext;
            error_log("[AIAGENTNSFW] Injected NEGOTIATION context with price list for $actorName (prerequest)");
            $debugContent = preg_replace('/\s+/', ' ', $negotiationContext);
            error_log("[AIAGENTNSFW] Negotiation context: " . substr($debugContent, 0, 500));
        }
    } else {
        // Regular NPC: Get actor list from stored intimacy status (persists across requests for group scenes)
        // Fall back to global if available (same-request scenario)
        $allActors = $intimacyStatus["scene_actors"] ?? $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] ?? null;

        if (is_array($allActors) && count($allActors) > 0) {
            // Build tier context (this injects the accept/refuse emotional prompt)
            $sceneContext = NsfwRelationship::buildSceneContext($actorName, $allActors, false);

            if (!empty($sceneContext)) {
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $sceneContext;
                error_log("[AIAGENTNSFW] Injected tier prompt for $actorName (phase: tier_prompt, actors: " . implode(", ", $allActors) . ")");
                // Debug: Log the actual prompt content (truncated)
                $debugContent = preg_replace('/\s+/', ' ', $sceneContext);
                error_log("[AIAGENTNSFW] Tier prompt content for $actorName: " . substr($debugContent, 0, 500));
            }
        } else {
            error_log("[AIAGENTNSFW] WARNING: No scene actors found for tier prompt injection for $actorName");
        }
    }

    // For slaves and prostitutes, auto-accept (no refusal logic)
    // For others, model decides based on tier prompt
    if ($isSlave || $isProstitute) {
        // Auto-progress to accepted phase
        $intimacyStatus["scene_phase"] = "accepted";
        error_log("[AIAGENTNSFW] Auto-accepting for $actorName (slave/prostitute)");
    } else {
        // Regular NPC - mark that tier prompt was sent, wait for next request
        // The model's response will determine if they accepted
        // For now, we assume if scene continues (chatnf_sl), they accepted
        $intimacyStatus["tier_prompt_sent"] = true;
    }

} else if ($scenePhase === "accepted" || ($scenePhase === "tier_prompt" && isset($intimacyStatus["tier_prompt_sent"]))) {
    // ============================================
    // PHASE 2: ACCEPTED - Set Level 1, Inject Styles
    // ============================================
    // Model accepted. Set level 1 and inject personality/styles.
    // Then check arousal to determine if we progress to level 2.
    // ============================================

    // If we're still in tier_prompt but prompt was sent, the scene continuing means accept
    if ($scenePhase === "tier_prompt") {
        $intimacyStatus["scene_phase"] = "accepted";
        $scenePhase = "accepted";
        error_log("[AIAGENTNSFW] Scene continued - marking as accepted for $actorName");
    }

    // ACCEPT sets level = 1 (pre-intimate)
    $intimacyStatus["level"] = 1;
    error_log("[AIAGENTNSFW] Phase: accepted for $actorName, level set to 1");

    // ============================================
    // INJECT PERSONALITY/STYLE at Level 1
    // ============================================
    // Styles inject AFTER accept but BEFORE arousal check
    // ============================================
    error_log("[AIAGENTNSFW] Injecting styles at level 1 for $actorName");

    // Inject personality and speech style based on NPC type
    if ($isSlave) {
        // SLAVE: Use slave-specific personality and speech (with fiction frame)
        $slavePersonality = NsfwRelationship::getSlavePersonality($slaveAffinity, $slaveOwnerName);
        $slaveSpeech = NsfwRelationship::getSlaveSpeechStyle($slaveAffinity, $slaveOwnerName);

        if (!empty($slavePersonality)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . NsfwRelationship::wrapXml('slave_personality', $slavePersonality);
            error_log("[AIAGENTNSFW] Injected SLAVE personality for $actorName (affinity: $slaveAffinity)");
        }
        if (!empty($slaveSpeech)) {
            $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n" . NsfwRelationship::wrapXml('slave_speech', $slaveSpeech);
            error_log("[AIAGENTNSFW] Injected SLAVE speech style for $actorName");
        }
    } else if ($isProstitute) {
        // PROSTITUTE: Inject negotiation context with PRICE LIST if payment not confirmed
        if (empty($intimacyStatus["payment_confirmed"])) {
            $clientName = $GLOBALS["PLAYER_NAME"] ?? "client";
            $affinity = 0;
            try {
                $relationship = RelationshipManager::getPlayerRelationship($actorName);
                $affinity = $relationship['aff'] ?? 0;
            } catch (Exception $e) {
                // Use default 0
            }
            $negotiationContext = buildProstituteNegotiationContext($actorName, $clientName, $affinity);
            if (!empty($negotiationContext)) {
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $negotiationContext;
                error_log("[AIAGENTNSFW] Injected NEGOTIATION context with price list for $actorName (accepted phase)");
                $debugContent = preg_replace('/\s+/', ' ', $negotiationContext);
                error_log("[AIAGENTNSFW] Negotiation context: " . substr($debugContent, 0, 500));
            }
        } else {
            // Payment confirmed - use regular sex prompts
            error_log("[AIAGENTNSFW] Prostitute payment confirmed - using sex prompts for $actorName");
            setSexPrompt($GLOBALS["HERIKA_NAME"]);
            setSexSpeechStyle($GLOBALS["HERIKA_NAME"]);
        }
    } else {
        // Regular NPC or Affair - use configured speak style
        setSexPrompt($GLOBALS["HERIKA_NAME"]);
        setSexSpeechStyle($GLOBALS["HERIKA_NAME"]);
    }

    // ============================================
    // AROUSAL CHECK - determines level 1 vs 2
    // ============================================
    // IMPORTANT: Prostitutes MUST have payment confirmed before engaging,
    // regardless of arousal level. Check prostitute status FIRST.
    // ============================================
    $arousalThreshold = 10; // Configurable later
    $currentArousal = $intimacyStatus["sex_disposal"] ?? 0;

    if ($isProstitute) {
        // PROSTITUTE: Payment confirmation is REQUIRED before engaging
        // This takes priority over arousal checks
        if (!empty($intimacyStatus["payment_confirmed"])) {
            $intimacyStatus["scene_phase"] = "engaged";
            $intimacyStatus["level"] = 2;
            error_log("[AIAGENTNSFW] Prostitute payment confirmed - engaging scene for $actorName");
        } else {
            // Stay at level 0, awaiting payment - RequestPayment function is available at level 0
            $intimacyStatus["level"] = 0;
            error_log("[AIAGENTNSFW] Prostitute awaiting payment - staying at level 0 for $actorName (arousal: $currentArousal, but payment required first)");
        }
    } else if ($isSlave || $currentArousal >= $arousalThreshold) {
        // Non-prostitute: Ready for full scene - progress to engaged (level 2)
        $intimacyStatus["scene_phase"] = "engaged";
        $intimacyStatus["level"] = 2;
        error_log("[AIAGENTNSFW] Arousal check passed ($currentArousal >= $arousalThreshold) - engaging scene for $actorName");
    } else {
        // Not aroused enough - stay at level 1 (foreplay, build arousal)
        error_log("[AIAGENTNSFW] Arousal too low ($currentArousal < $arousalThreshold) - foreplay for $actorName");
    }
}

// Handle chatnf_sl event (scene is actively running)
if ($gameRequest[0] == "chatnf_sl") {
    // If we're in tier_prompt phase and scene is running, they accepted
    if ($scenePhase === "tier_prompt") {
        $intimacyStatus["scene_phase"] = "accepted";
        error_log("[AIAGENTNSFW] chatnf_sl received during tier_prompt - auto-accepting");
    }

    // Ensure we're at least level 1 if scene is running
    if ($intimacyStatus["level"] < 1) {
        $intimacyStatus["level"] = 1;
    }

    // If phase is engaged, ensure level 2
    if ($intimacyStatus["scene_phase"] === "engaged") {
        $intimacyStatus["level"] = 2;
    }
}

// ============================================
// SLAVE FIX: Slaves ALWAYS get prompts - no acceptance needed
// They just comply, period.
// ============================================
if ($isSlave && $intimacyStatus["level"] < 2) {
    $intimacyStatus["level"] = 2;
    $intimacyStatus["scene_phase"] = "engaged";
    error_log("[AIAGENTNSFW] Slave detected - forcing $actorName to level 2 (slaves always comply)");
}

// ============================================
// GROUP SCENE FIX: If scene_actors exists and has entries,
// the scene is active - force level to at least 1
// This ensures ALL participants get prompts once anyone accepts
// ============================================
$sceneActors = $intimacyStatus["scene_actors"] ?? [];
if (!empty($sceneActors) && count($sceneActors) > 1 && $intimacyStatus["level"] < 1) {
    $intimacyStatus["level"] = 1;
    if (empty($intimacyStatus["scene_phase"]) || $intimacyStatus["scene_phase"] === "tier_prompt") {
        $intimacyStatus["scene_phase"] = "accepted";
    }
    error_log("[AIAGENTNSFW] Group scene active - forcing $actorName to level 1 (scene has " . count($sceneActors) . " actors)");
}

// Force mood based on level
if ($intimacyStatus["level"] > 0) {
    $GLOBALS["FORCE_MOOD"] = "sexy";

    // ============================================
    // UNIVERSAL PROMPT INJECTION - ANY LEVEL > 0
    // ============================================
    // Sex prompts and affinity context should hit on EVERY request
    // during an active scene, regardless of level 1 or 2
    // ============================================
    // ============================================
    // ALWAYS inject scene context (intimate_scene block) for ALL NPC types
    // This gives the model participant info, affinity, emotional state
    // ============================================
    $sceneActorsForContext = $intimacyStatus["scene_actors"] ?? [];
    if (!empty($sceneActorsForContext)) {
        $sceneContext = NsfwRelationship::buildSceneContext($actorName, $sceneActorsForContext, $isProstitute);
        if (!empty($sceneContext)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $sceneContext;
            error_log("[AIAGENTNSFW] Injected <intimate_scene> context for $actorName");
        }
    }

    // ============================================
    // NPC TYPE SPECIFIC PROMPTS
    // ============================================
    if ($isSlave) {
        // SLAVE: Inject slave-specific personality and speech
        $slavePersonality = NsfwRelationship::getSlavePersonality($slaveAffinity, $slaveOwnerName);
        $slaveSpeech = NsfwRelationship::getSlaveSpeechStyle($slaveAffinity, $slaveOwnerName);
        if (!empty($slavePersonality)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . NsfwRelationship::wrapXml('slave_personality', $slavePersonality);
        }
        if (!empty($slaveSpeech)) {
            $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n" . NsfwRelationship::wrapXml('slave_speech', $slaveSpeech);
        }
        // Also inject their sex_prompt and kinks if they have them
        setSexPrompt($GLOBALS["HERIKA_NAME"]);
        setSexSpeechStyle($GLOBALS["HERIKA_NAME"]);
        error_log("[AIAGENTNSFW] Injected SLAVE prompts for $actorName (level: {$intimacyStatus['level']})");
    } else if ($isProstitute && !empty($intimacyStatus["payment_confirmed"])) {
        // PROSTITUTE with payment confirmed - use regular sex prompts
        setSexPrompt($GLOBALS["HERIKA_NAME"]);
        setSexSpeechStyle($GLOBALS["HERIKA_NAME"]);
        error_log("[AIAGENTNSFW] Injected sex prompts for PROSTITUTE $actorName (level: {$intimacyStatus['level']})");
    } else if (!$isSlave && !$isProstitute) {
        // REGULAR NPC - inject sex prompts with affinity/tier context
        setSexPrompt($GLOBALS["HERIKA_NAME"]);
        setSexSpeechStyle($GLOBALS["HERIKA_NAME"]);
        error_log("[AIAGENTNSFW] Injected sex prompts for $actorName (level: {$intimacyStatus['level']})");
    }
} else {
    unset($GLOBALS["FORCE_MOOD"]);
}

// ============================================
// PHASE 3: ENGAGED (Level 2) - Kinks & Service Tracking
// ============================================
// Level 2 specific behavior:
// - Kinks engage (except slaves/prostitutes/default NPCs)
// - Prostitutes: service tracking (payment, time)
// - Override chatnf_sl cues for configured NPCs
// ============================================

if ($intimacyStatus["level"] == 2 && $intimacyStatus["scene_phase"] === "engaged") {
    error_log("[AIAGENTNSFW] Phase: engaged (level 2) for $actorName");

    // SLAVE SCENE CUES at Level 2
    if ($isSlave) {
        $slaveSceneCues = NsfwRelationship::getSlaveSceneCues($slaveAffinity, $slaveOwnerName);
        if (!empty($slaveSceneCues)) {
            // Override chatnf_sl cues with slave-specific cues
            $GLOBALS["PROMPTS"]["chatnf_sl"]["cue"] = ["<response_instruction>\n{$slaveSceneCues}\n</response_instruction> {$GLOBALS["TEMPLATE_DIALOG"]}"];
            error_log("[AIAGENTNSFW] Injected SLAVE scene cues for $actorName (affinity: $slaveAffinity)");
        }
    }

    // KINKS ENGAGEMENT - only at level 2 for configured NPCs
    // (Sex prompts are now injected universally for any level > 0 above)
    if (!$isSlave && !$isProstitute) {
        $hasProfile = isset($extended_data['source']) && !empty($extended_data['source']);
        if ($hasProfile) {
            error_log("[AIAGENTNSFW] Kinks engaged for configured NPC: $actorName");
        }
    }

    // PROSTITUTE SERVICE TRACKING
    if ($isProstitute) {
        // Build prostitute service context
        $serviceContext = "";

        // Payment status
        if (!empty($intimacyStatus["payment_confirmed"])) {
            $serviceContext .= "Payment received and confirmed. ";
        } else if (!empty($intimacyStatus["payment_requested"])) {
            $serviceContext .= "Payment was requested but not yet confirmed. Check your gold to verify. ";
        } else if (!empty($intimacyStatus["is_transaction"])) {
            $serviceContext .= "This is a business transaction - ensure you get paid for your services. ";
        }

        // Additional payment demand
        if (!empty($intimacyStatus["additional_payment_demanded"])) {
            $serviceContext .= "You demanded additional payment for extra services. ";
        }

        // Service duration tracking
        if (!empty($intimacyStatus["scene_start_time"])) {
            $duration = time() - $intimacyStatus["scene_start_time"];
            $minutes = floor($duration / 60);
            if ($minutes > 0) {
                $serviceContext .= "Session has been going for about {$minutes} minutes. ";
            }
        }

        // Inject service context into personality
        if (!empty($serviceContext)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n#Service Status: {$serviceContext}";
            error_log("[AIAGENTNSFW] Injected service context: {$serviceContext}");
        }
    }

    // Override chatnf_sl cues with NPC's speak style if configured
    // This is for DEFAULT NPCs - configured NPCs already have their style in personality
    if (isset($extended_data["sex_speech_style"]) && !empty($extended_data["sex_speech_style"]) && $extended_data["sex_speech_style"] !== 'auto') {
        require_once __DIR__ . "/nsfw_data.php";

        $styleContent = NsfwData::getSpeakStyleContent($extended_data["sex_speech_style"]);

        // Fallback to file if not in JSONB
        if (empty($styleContent)) {
            $styleFile = __DIR__ . "/speakStyles/" . $extended_data["sex_speech_style"] . ".txt";
            if (file_exists($styleFile)) {
                $styleContent = file_get_contents($styleFile);
            }
        }

        if (!empty($styleContent)) {
            // Override the chatnf_sl cue with the NPC's speak style
            $GLOBALS["PROMPTS"]["chatnf_sl"]["cue"] = ["<response_instruction>\n{$styleContent}\n</response_instruction> {$GLOBALS["TEMPLATE_DIALOG"]}"];
            error_log("[AIAGENTNSFW] Overriding chatnf_sl cues with speak style '{$extended_data["sex_speech_style"]}' for {$GLOBALS["HERIKA_NAME"]}");
        }
    }

    // ============================================
    // CRITICAL: Also override RECHAT cues for engaged NPCs
    // ============================================
    // NPCs often talk via rechat (regular multi-NPC chat system) rather than
    // chatnf_sl (OStim auto-talk). Without this, they get generic "dialogue turn"
    // cues instead of sex scene cues - causing them to talk about random topics
    // like "ancient resonance" instead of the intimate scene.
    // ============================================
    if (isset($GLOBALS["PROMPTS"]["rechat"]["cue"])) {
        // Use the chatnf_sl cues from prompts.php - NO hardcoded fallbacks
        // If chatnf_sl cue is configured, use it for rechat too
        $sexSceneCue = $GLOBALS["PROMPTS"]["chatnf_sl"]["cue"][0] ?? null;

        if (!empty($sexSceneCue)) {
            $GLOBALS["PROMPTS"]["rechat"]["cue"] = [$sexSceneCue];
            error_log("[AIAGENTNSFW] Overriding RECHAT cues with chatnf_sl cues for engaged NPC: $actorName");
        }
    }
}

if (isset($extended_data["fertility_recent_birth"])) {
    error_log("[AIAGENT NSFW] Checking fertility_recent_birth");
    if (($gameRequest[2]-$extended_data["fertility_recent_birth"]) < (7 * 24 / 0.0000024)) {
        error_log("[AIAGENT NSFW] setBirthPrompt fertility_recent_birth");
        setBirthPrompt($GLOBALS["HERIKA_NAME"]);
    }
}


error_log("[AIAGENTNSFW ] updateIntimacyForActor({$GLOBALS["HERIKA_NAME"]})".json_encode($intimacyStatus));
updateIntimacyForActor($GLOBALS["HERIKA_NAME"],$intimacyStatus);        

// Add hook  to XTTS to insert some oh's and ah's into the speech.
// Also will change XTTS settings
// If level 2 -> Intimate scene, NPC should talk slower, and we add some random gasps.
// Respects XTTS_MODIFY_LEVEL1 and XTTS_MODIFY_LEVEL2 settings from config

// Load XTTS settings from database
$xttsSettingsRow = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_settings'");
$xttsLevel1Enabled = true;  // Default to enabled
$xttsLevel2Enabled = true;  // Default to enabled
if ($xttsSettingsRow && !empty($xttsSettingsRow['value'])) {
    $xttsSettings = json_decode($xttsSettingsRow['value'], true);
    if (is_array($xttsSettings)) {
        $xttsLevel1Enabled = isset($xttsSettings['XTTS_MODIFY_LEVEL1']) ? (bool)$xttsSettings['XTTS_MODIFY_LEVEL1'] : true;
        $xttsLevel2Enabled = isset($xttsSettings['XTTS_MODIFY_LEVEL2']) ? (bool)$xttsSettings['XTTS_MODIFY_LEVEL2'] : true;
    }
}

// Load random moans settings from Settings JSONB (same source as XTTS settings)
$enableRandomMoans = true;  // Default enabled
$moansAffinityThreshold = 6;  // Default: Acquaintance
$randomMoansList = [" ... oh ... ", " ... ah ... ", " ... mmm ... "];  // Default moans
$xttsSpeedLevel1 = 0.8;  // Default speed for level 1 (idle/foreplay)
$xttsSpeedLevel2 = 0.7;  // Default speed for level 2 (action)

if ($xttsSettingsRow && !empty($xttsSettingsRow['value'])) {
    $xttsSettings = json_decode($xttsSettingsRow['value'], true);
    if (is_array($xttsSettings)) {
        $enableRandomMoans = !isset($xttsSettings['ENABLE_RANDOM_MOANS']) || $xttsSettings['ENABLE_RANDOM_MOANS'] === true || $xttsSettings['ENABLE_RANDOM_MOANS'] === 'true';
        $moansAffinityThreshold = isset($xttsSettings['MOANS_AFFINITY_THRESHOLD']) ? (int)$xttsSettings['MOANS_AFFINITY_THRESHOLD'] : 6;
        $xttsSpeedLevel1 = isset($xttsSettings['XTTS_SPEED_LEVEL1']) ? (float)$xttsSettings['XTTS_SPEED_LEVEL1'] : 0.8;
        $xttsSpeedLevel2 = isset($xttsSettings['XTTS_SPEED_LEVEL2']) ? (float)$xttsSettings['XTTS_SPEED_LEVEL2'] : 0.7;
        if (!empty($xttsSettings['RANDOM_MOAN_SOUNDS'])) {
            $randomMoansList = array_filter(array_map('trim', explode("\n", $xttsSettings['RANDOM_MOAN_SOUNDS'])));
            if (empty($randomMoansList)) {
                $randomMoansList = [" ... oh ... ", " ... ah ... ", " ... mmm ... "];
            }
        }
    }
}

// Check NPC's affinity with scene partner(s) against threshold
$npcAffinityMeetsMoanThreshold = false;
if ($enableRandomMoans) {
    $actorName = $GLOBALS["HERIKA_NAME"] ?? '';
    if (!empty($actorName) && isset($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"]) && is_array($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"])) {
        // Check affinity with each partner - use lowest (most restrictive)
        $lowestAffinity = 100;
        foreach ($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] as $partner) {
            if (strtolower($partner) !== strtolower($actorName)) {
                $partnerAffinity = getNpcAffinity($actorName, $partner);
                if ($partnerAffinity < $lowestAffinity) {
                    $lowestAffinity = $partnerAffinity;
                }
            }
        }
        $npcAffinityMeetsMoanThreshold = ($lowestAffinity >= $moansAffinityThreshold);
        error_log("[AIAGENTNSFW] Moans affinity check: $actorName lowest affinity = $lowestAffinity, threshold = $moansAffinityThreshold, passes = " . ($npcAffinityMeetsMoanThreshold ? 'YES' : 'NO'));
    } else {
        // Fallback: check player affinity
        $npcAffinity = getNpcAffinity($actorName);
        $npcAffinityMeetsMoanThreshold = ($npcAffinity >= $moansAffinityThreshold);
        error_log("[AIAGENTNSFW] Moans affinity check (fallback): $actorName affinity = $npcAffinity, threshold = $moansAffinityThreshold, passes = " . ($npcAffinityMeetsMoanThreshold ? 'YES' : 'NO'));
    }
}

if ($intimacyStatus["level"]==2 && $xttsLevel2Enabled) {
    $moansActive = $enableRandomMoans && $npcAffinityMeetsMoanThreshold;
    error_log("Adding XTTS hook {$intimacyStatus["level"]} (XTTS_MODIFY_LEVEL2 enabled, speed: {$xttsSpeedLevel2}, random moans: " . ($moansActive ? 'ON' : 'OFF - affinity too low') . ")");

    // Store in GLOBALS so the closure can access them
    $GLOBALS["AIAGENTNSFW_ENABLE_RANDOM_MOANS"] = $moansActive;
    $GLOBALS["AIAGENTNSFW_RANDOM_MOANS_LIST"] = $randomMoansList;
    $GLOBALS["AIAGENTNSFW_XTTS_SPEED_LEVEL2"] = $xttsSpeedLevel2;

    $GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"][]=function($text) {
        $result = $text;

        // Only inject moans if enabled AND affinity threshold met
        if (!empty($GLOBALS["AIAGENTNSFW_ENABLE_RANDOM_MOANS"])) {
            $randomStrings = $GLOBALS["AIAGENTNSFW_RANDOM_MOANS_LIST"] ?? [" ... oh ... ", " ... ah ... ", " ... mmm ... "];

            // Generate a random index
            $randomIndex = mt_rand(0, count($randomStrings) - 1);

            // Split the sentence into an array of words
            $words = explode(' ', $text);

            // Select a random word index to insert the random string
            $wordIndex = mt_rand(0, count($words) - 1);

            // Insert the random string into the selected word
            $randomWord = $words[$wordIndex];
            $insertPosition = strpos($result, $randomWord);
            $result = substr_replace($result, $randomStrings[$randomIndex], $insertPosition, 0);
            Logger::info("Applying text modifier for XTTS $text => $result ".__FILE__);
        }

        $speed = $GLOBALS["AIAGENTNSFW_XTTS_SPEED_LEVEL2"] ?? 0.7;
        xtts_fastapi_settings(["temperature"=>1,"speed"=>$speed,"enable_text_splitting"=>false,"top_p"=> 1,"top_k"=>100],true);
        return $result;

    };
} else if ($intimacyStatus["level"]==2 && !$xttsLevel2Enabled) {
    error_log("[AIAGENTNSFW] XTTS hook level 2 DISABLED by settings");
}

// If level 1 -> Pre intimate scene, NPC should talk slower.
if ($intimacyStatus["level"]==1 && $xttsLevel1Enabled) {
    error_log("Adding XTTS hook {$intimacyStatus["level"]} (XTTS_MODIFY_LEVEL1 enabled, speed: {$xttsSpeedLevel1})");
    $GLOBALS["AIAGENTNSFW_XTTS_SPEED_LEVEL1"] = $xttsSpeedLevel1;

    $GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"][]=function($text) {
        $speed = $GLOBALS["AIAGENTNSFW_XTTS_SPEED_LEVEL1"] ?? 0.8;
        Logger::info("Applying speed modifier for XTTS (speed: {$speed}) $text => $text ".__FILE__);

        xtts_fastapi_settings(["temperature"=>1,"speed"=>$speed,"enable_text_splitting"=>false,"top_p"=> 1,"top_k"=>100],true);
        return $text;

    };
} else if ($intimacyStatus["level"]==1 && !$xttsLevel1Enabled) {
    error_log("[AIAGENTNSFW] XTTS hook level 1 DISABLED by settings");
}


?>