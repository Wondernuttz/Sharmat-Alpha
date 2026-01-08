<?php
/**
 * OStim Scene Handler
 *
 * Handles all OStim/SexLab scene processing:
 * - Scene start/update/end events
 * - Climax events and speech generation
 * - Sex speech styles and prompts
 * - GASP integration for orgasm sounds
 *
 * This is the high-churn file for LLM personality tweaking during scenes.
 *
 * USAGE:
 *   NsfwOstimHandler::processEvent()           - Main entry point (handles all scene events)
 *   NsfwOstimHandler::setSexSpeechStyle($name) - Inject speech style for actor
 *   NsfwOstimHandler::setSexPrompt($name)      - Inject sex personality prompt
 *   NsfwOstimHandler::generateClimaxSpeech()   - Generate orgasm speech via LLM
 */

class NsfwOstimHandler {

    /**
     * Main entry point - Process all OStim scene events
     * Handles: ext_nsfw_sexcene, chatnf_sl_end, chatnf_sl_naked,
     *          chatnf_sl_climax, chatnf_sl_moan, ext_nsfw_action,
     *          ext_nsfw_scene, ext_nsfw_orgasm, ext_nsfw_npc_scene
     */
    public static function processEvent() {
        global $gameRequest;

        if ($gameRequest[0] == "ext_nsfw_sexcene") {
            self::handleSceneUpdate();
        } else if ($gameRequest[0] == "ext_nsfw_npc_scene") {
            self::handleNpcScene();
        } else if ($gameRequest[0] == "chatnf_sl_end") {
            self::handleSceneEnd();
        } else if ($gameRequest[0] == "chatnf_sl_naked") {
            self::handleNaked();
        } else if ($gameRequest[0] == "chatnf_sl_climax") {
            self::handleClimax();
        } else if ($gameRequest[0] == "chatnf_sl_moan") {
            self::handleMoan();
        } else if ($gameRequest[0] == "ext_nsfw_action") {
            self::handleAction();
        } else if ($gameRequest[0] == "ext_nsfw_scene") {
            self::handleSceneEvent();
        } else if ($gameRequest[0] == "ext_nsfw_orgasm") {
            self::handleOrgasm();
        }
    }

    /**
     * Handle ext_nsfw_sexcene - Main scene update event
     * Parse info_sexscene data and manage scene phases
     */
    private static function handleSceneUpdate() {
        global $gameRequest;

        // Parse info_sexscene data
        // Format: Arrok Standing Foreplay/["Loving", "Standing", "LeadIn", ...]/Arrok_StandingForeplay_A1_S1/Actor1Æctor2
        // Multi-actor scenes may have multiple stage names separated by | (pipe)
        error_log("[NSFW-SCENE] Raw info_sexscene data: {$gameRequest[3]}");
        $infoSexSceneParts = explode("/", $gameRequest[3]);
        $sexSceneName      = $infoSexSceneParts[0];
        $sexTags           = explode(",", strtolower($infoSexSceneParts[1]));
        $sexStageName      = strtr($infoSexSceneParts[2], ["_A1" => ""]);
        $actorInfos        = array_slice($infoSexSceneParts, 3);

        // Debug: Log all parsed parts for multi-actor scene analysis
        error_log("[NSFW-SCENE] Parsed - SceneName: $sexSceneName | StageName: $sexStageName | Tags: " . implode(",", $sexTags));
        error_log("[NSFW-SCENE] Actor parts (" . count($actorInfos) . "): " . implode(" | ", $actorInfos));

        // Build ordered actor list with player at index 0, preserving relative order of other actors
        // OStim sends actors in animation-defined order, we just need player first for {actor0}
        $playerName = $GLOBALS["PLAYER_NAME"];
        $orderedActorList = [];
        $otherActors = [];

        foreach ($actorInfos as $actorinfo) {
            if (empty($actorinfo)) {
                continue;
            }
            if ($actorinfo === $playerName) {
                // Player goes first
                array_unshift($orderedActorList, $actorinfo);
            } else {
                // Non-player actors maintain their relative order
                $otherActors[] = $actorinfo;
            }
        }

        // Append non-player actors in their original relative order
        $orderedActorList = array_merge($orderedActorList, $otherActors);

        error_log("[AIAGENTNSFW] Erotic Scene. Actors" . json_encode($orderedActorList));

        // Store actor list globally for tier prompt injection
        $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] = $orderedActorList;

        // ============================================
        // Scene triggers tier prompt FIRST
        // ============================================
        // Level meanings:
        //   0 = Not in scene OR tier prompt phase (accept/refuse)
        //   1 = Accepted, pre-intimate (foreplay, arousal building)
        //   2 = Scene engaged (full intimate, styles injected)
        //
        // scene_phase tracks where we are in the flow:
        //   "tier_prompt" = Waiting for model to accept/refuse
        //   "accepted"    = Model accepted, checking arousal
        //   "engaged"     = Full scene, styles active
        // ============================================

        foreach ($actorInfos as $actor) {
            $intimacyStatus = getIntimacyForActor($actor);

            // Check if this is a NEW scene or continuation
            $isNewScene = !isset($intimacyStatus["scene_phase"]) || $intimacyStatus["scene_phase"] === null;

            if ($isNewScene) {
                // NEW SCENE: Start with tier prompt phase
                $intimacyStatus["level"] = 0;
                $intimacyStatus["scene_phase"] = "tier_prompt";
                $intimacyStatus["scene_is_idle"] = in_array("idle", $sexTags);
                $intimacyStatus["scene_start_time"] = time();
                $intimacyStatus["scene_actors"] = $orderedActorList;
                error_log("[AIAGENTNSFW] New scene for $actor - starting tier_prompt phase (actors: " . implode(", ", $orderedActorList) . ")");

                // ============================================
                // STORE TIER PROMPT INFO FOR ALL ACTORS
                // ============================================
                // We compute and store is_slave/is_prostitute for EVERY actor
                // so prerequest.php can inject the correct tier prompt when
                // each actor speaks (not just the first speaker)
                // ============================================
                require_once __DIR__ . "/nsfw_relationship.php";

                // Skip player - only process NPCs
                if ($actor !== $GLOBALS["PLAYER_NAME"]) {
                    // Determine NPC type for this actor
                    $npcManager = new NpcMaster();
                    $npcData = $npcManager->getByName($actor);
                    $extended_data = $npcManager->getExtendedData($npcData);
                    $metadata = $npcManager->getMetadata($npcData);

                    $isSlave = isNpcSlave($actor);
                    $isCourtesan = false;
                    $modsToCheck = ["The Naked DragonSSE.esp", "prostitutes.esp"];
                    if (is_array($metadata["mods"])) {
                        foreach ($modsToCheck as $mod) {
                            $isCourtesan = $isCourtesan || in_array($mod, $metadata["mods"]);
                        }
                    }
                    $isProstitute = !empty($extended_data['is_prostitute']) ||
                                    !empty($extended_data['profession_prostitute']) ||
                                    $isCourtesan;

                    // Store NPC type info in intimacy status for prerequest.php to use
                    $intimacyStatus["npc_is_slave"] = $isSlave;
                    $intimacyStatus["npc_is_prostitute"] = $isProstitute;

                    // Store affinity for slaves
                    if ($isSlave) {
                        try {
                            $relationship = RelationshipManager::getPlayerRelationship($actor);
                            $intimacyStatus["slave_affinity"] = $relationship['aff'] ?? 0;
                            error_log("[AIAGENTNSFW] Stored slave info for $actor: affinity={$intimacyStatus["slave_affinity"]}");
                        } catch (Exception $e) {
                            $intimacyStatus["slave_affinity"] = 0;
                            error_log("[AIAGENTNSFW] Failed to get slave affinity for $actor: " . $e->getMessage());
                        }
                    }

                    error_log("[AIAGENTNSFW] Stored NPC type for $actor: slave=" . ($isSlave ? "YES" : "no") . ", prostitute=" . ($isProstitute ? "YES" : "no"));

                    // For slaves, auto-accept immediately (they can't refuse)
                    // For prostitutes, mark as transaction and let them request payment
                    if ($isSlave) {
                        $intimacyStatus["scene_phase"] = "accepted";
                        error_log("[AIAGENTNSFW] Auto-accepting for $actor (slave) on scene start");
                    } else if ($isProstitute) {
                        $intimacyStatus["scene_phase"] = "accepted";
                        $intimacyStatus["is_transaction"] = true;
                        $intimacyStatus["payment_requested"] = false;
                        $intimacyStatus["payment_confirmed"] = false;
                        $intimacyStatus["negotiation_phase"] = true;
                        error_log("[AIAGENTNSFW] Prostitute transaction started for $actor - awaiting payment request");
                    }

                    // If this is the current speaker, inject tier prompt immediately
                    if ($actor === $GLOBALS["HERIKA_NAME"]) {
                        if ($isProstitute) {
                            // PROSTITUTES: Inject negotiation context with PRICE LIST
                            // This includes their services menu, pricing, and payment instructions
                            $clientName = $GLOBALS["PLAYER_NAME"] ?? "client";
                            $affinity = 0;
                            try {
                                $relationship = RelationshipManager::getPlayerRelationship($actor);
                                $affinity = $relationship['aff'] ?? 0;
                            } catch (Exception $e) {
                                // Use default 0
                            }
                            $negotiationContext = buildProstituteNegotiationContext($actor, $clientName, $affinity);
                            if (!empty($negotiationContext)) {
                                $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $negotiationContext;
                                error_log("[AIAGENTNSFW] Injected NEGOTIATION context with price list for $actor");
                            }
                        } else {
                            // NON-PROSTITUTES: Use regular tier prompt (affinity-based)
                            $sceneContext = NsfwRelationship::buildSceneContext($actor, $orderedActorList, false);

                            if (!empty($sceneContext)) {
                                $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $sceneContext;
                                error_log("[AIAGENTNSFW] IMMEDIATE tier prompt injection for $actor on scene start");
                            }
                        }

                        if (!$isSlave && !$isProstitute) {
                            $intimacyStatus["tier_prompt_sent"] = true;
                        }
                    }
                }
            } else {
                // CONTINUING SCENE: Progress through phases
                error_log("[AIAGENTNSFW] Continuing scene for $actor - phase: {$intimacyStatus["scene_phase"]}");
            }

            updateIntimacyForActor($actor, $intimacyStatus);
        }

        error_log("Searching for description $sexStageName");

        // Fill descriptions - DUAL LOOKUP: SQL first (user custom), then OStim JSON (automatic)
        $cleanedSceneDesc = getSceneDescription($sexStageName, $orderedActorList);

        // Legacy fallback if scene_lookup.php fails
        if (empty($cleanedSceneDesc)) {
            $sceneDescription = findRowByFirstColumn(__DIR__ . "/scene_descriptions.csv", $sexStageName);
            if (!$sceneDescription) {
                $sceneDescription = "{actor0},{actor1},{actor2},{actor3},{actor4} are having an intimate moment";
            }
            $sceneDescriptionParsed = preg_replace_callback('/\{actor(\d+)\}/', function ($matches) use ($orderedActorList) {
                $index = (int) $matches[1];
                return $orderedActorList[$index] ?? $matches[0];
            }, $sceneDescription);
            $cleanedSceneDesc = preg_replace('/\{actor\d+\}/', '', $sceneDescriptionParsed);
        }

        // Rewrite data
        $GLOBALS["gameRequest"][3] = "#INTIMATE SCENE: $cleanedSceneDesc. Scene tags:" . implode(",", $sexTags);
        $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
        logEvent($GLOBALS["gameRequest"]);
    }

    /**
     * Handle ext_nsfw_npc_scene - NPC-to-NPC scene (no player)
     */
    private static function handleNpcScene() {
        global $gameRequest;

        error_log("[AIAGENT-NSFW] Processing NPC-to-NPC scene: {$gameRequest[3]}");

        $result = NsfwNpcScene::processNpcScene($gameRequest[3]);

        if ($result) {
            $npc1 = $result['npc1']['name'];
            $npc2 = $result['npc2']['name'];
            $sceneID = $result['sceneID'];

            error_log("[AIAGENT-NSFW] NPC Scene: {$npc1} + {$npc2}, shouldStop: " . ($result['shouldStop'] ? 'YES' : 'NO'));

            if ($result['shouldStop']) {
                error_log("[AIAGENT-NSFW] NPC scene should stop: {$result['stopReason']}");
            }

            if ($result['npc1']['isChimEnabled'] && !empty($result['npc1']['tierPrompt'])) {
                error_log("[AIAGENT-NSFW] Queueing tier dialogue for {$npc1}");
            }

            if ($result['npc2']['isChimEnabled'] && !empty($result['npc2']['tierPrompt'])) {
                error_log("[AIAGENT-NSFW] Queueing tier dialogue for {$npc2}");
            }

            $GLOBALS["gameRequest"][3] = "NPC intimate scene: {$npc1} and {$npc2} ({$sceneID})";
            $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
            logEvent($GLOBALS["gameRequest"]);
        }

        terminate();
    }

    /**
     * Handle chatnf_sl_end - Scene ended
     * Injects pillow talk prompts to ALL NPCs who were in the scene
     */
    private static function handleSceneEnd() {
        global $gameRequest;

        error_log("[AIAGENT_NSFW] Scene End: {$gameRequest[3]}");

        $sceneResultParts = explode("/", $gameRequest[3]);
        $scoringPart = array_slice($sceneResultParts, 1);
        $scoring = [];

        $actor = $GLOBALS["HERIKA_NAME"];
        $currentIntimacy = getIntimacyForActor($actor);
        $playerName = $GLOBALS['PLAYER_NAME'] ?? 'Player';

        // Get all actors who were in the scene BEFORE we reset their intimacy
        $sceneActors = [];
        if (!empty($currentIntimacy["scene_actors"])) {
            $sceneActors = $currentIntimacy["scene_actors"];
        } else if (isset($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"])) {
            $sceneActors = $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"];
        }
        error_log("[AIAGENT_NSFW] Scene ended - actors in scene: " . implode(", ", $sceneActors));

        // Check if this was a prostitute transaction
        $wasTransaction = !empty($currentIntimacy["is_transaction"]);
        $paymentConfirmed = !empty($currentIntimacy["payment_confirmed"]);
        $serviceCompleted = !empty($currentIntimacy["service_completed"]);

        foreach ($scoringPart as $part) {
            $actorResult = explode("@", $part);
            $scoring[] = $actorResult[0] . " satisfaction score: " . $actorResult[1];
        }

        // Inject pillow talk prompt to ALL NPCs who were in the scene
        foreach ($sceneActors as $sceneActor) {
            // Skip player
            if (strcasecmp($sceneActor, $playerName) === 0 || strcasecmp($sceneActor, "Player") === 0) {
                continue;
            }

            $actorIntimacy = getIntimacyForActor($sceneActor);
            $actorWasTransaction = !empty($actorIntimacy["is_transaction"]);
            $actorPaymentConfirmed = !empty($actorIntimacy["payment_confirmed"]);

            // Build pillow talk prompt for this actor
            $pillowTalkPrompt = "";
            if (isNpcSlave($sceneActor)) {
                // Slave pillow talk
                try {
                    $relationship = RelationshipManager::getPlayerRelationship($sceneActor);
                    $slaveAffinity = $relationship['aff'] ?? 0;
                    $pillowTalkPrompt = NsfwRelationship::getSlavePillowTalkPrompt($slaveAffinity, $playerName);
                    error_log("[AIAGENT_NSFW] Pillow talk for SLAVE $sceneActor (affinity: $slaveAffinity)");
                } catch (Exception $e) {
                    $pillowTalkPrompt = "The intimate moment has ended. As a slave, await further instructions.";
                    error_log("[AIAGENT_NSFW] Slave pillow talk fallback for $sceneActor: " . $e->getMessage());
                }
            } else if ($actorWasTransaction) {
                // Prostitute post-service
                if ($actorPaymentConfirmed) {
                    $pillowTalkPrompt = "The service session has ended. You were paid. Give a brief professional farewell.";
                } else {
                    $pillowTalkPrompt = "The service session ended but payment was NOT confirmed. You may remind about payment.";
                }
                error_log("[AIAGENT_NSFW] Post-service talk for prostitute $sceneActor (paid: " . ($actorPaymentConfirmed ? 'yes' : 'no') . ")");
            } else {
                // Regular NPC pillow talk - check for speak style pillow_talk_prompt
                $npcManager = new NpcMaster();
                $npcData = $npcManager->getByName($sceneActor);
                $extended = $npcManager->getExtendedData($npcData);
                if (!empty($extended['sex_speech_style'])) {
                    // Use NsfwData::getSpeakStyle which reads from JSONB
                    $speakStyle = NsfwData::getSpeakStyle($extended['sex_speech_style']);
                    if (!empty($speakStyle['pillow_talk_prompt'])) {
                        $pillowTalkPrompt = $speakStyle['pillow_talk_prompt'];
                        error_log("[AIAGENT_NSFW] Using speak style pillow talk for $sceneActor (style: {$extended['sex_speech_style']})");
                    }
                }
                if (empty($pillowTalkPrompt)) {
                    $pillowTalkPrompt = "The intimate moment has ended. Share a brief, genuine post-intimacy reaction in character.";
                }
                error_log("[AIAGENT_NSFW] Pillow talk for regular NPC $sceneActor");
            }

            // Store pillow talk prompt for this actor so prerequest can inject it
            $actorIntimacy["pillow_talk_pending"] = true;
            $actorIntimacy["pillow_talk_prompt"] = $pillowTalkPrompt;
            updateIntimacyForActor($sceneActor, $actorIntimacy);
        }

        // Now reset scene state for actors from scoring
        foreach ($scoringPart as $part) {
            $actorResult = explode("@", $part);
            $actorName = $actorResult[0];

            // Get current intimacy to preserve pillow talk
            $actorIntimacy = getIntimacyForActor($actorName);
            $pillowTalkPending = $actorIntimacy["pillow_talk_pending"] ?? false;
            $pillowTalkPrompt = $actorIntimacy["pillow_talk_prompt"] ?? "";

            // Reset scene state but keep pillow talk
            updateIntimacyForActor($actorName, [
                "level" => 0,
                "sex_disposal" => 10,
                "orgasmed" => false,
                "scene_phase" => null,
                "tier_prompt_sent" => false,
                "scene_is_idle" => null,
                "scene_start_time" => null,
                "scene_actors" => null,
                "is_transaction" => false,
                "payment_requested" => false,
                "payment_confirmed" => false,
                "additional_payment_demanded" => false,
                "service_completed" => false,
                "pillow_talk_pending" => $pillowTalkPending,
                "pillow_talk_prompt" => $pillowTalkPrompt
            ]);
        }

        // Also reset for current actor (preserve pillow talk)
        $pillowTalkPending = $currentIntimacy["pillow_talk_pending"] ?? false;
        $pillowTalkPrompt = $currentIntimacy["pillow_talk_prompt"] ?? "";
        updateIntimacyForActor($actor, [
            "level" => 0,
            "sex_disposal" => 10,
            "orgasmed" => false,
            "scene_phase" => null,
            "tier_prompt_sent" => false,
            "scene_is_idle" => null,
            "scene_start_time" => null,
            "scene_actors" => null,
            "is_transaction" => false,
            "payment_requested" => false,
            "payment_confirmed" => false,
            "additional_payment_demanded" => false,
            "service_completed" => false,
            "pillow_talk_pending" => $pillowTalkPending,
            "pillow_talk_prompt" => $pillowTalkPrompt
        ]);

        // Build post-scene prompt for current actor
        $postScenePrompt = "";

        if ($wasTransaction) {
            // PROSTITUTE POST-SERVICE TALK
            if ($paymentConfirmed) {
                $postScenePrompt = "The service session has ended. You were paid for your services. ";
                if ($serviceCompleted) {
                    $postScenePrompt .= "You ended the session professionally. Give a brief post-service farewell - professional but courteous. ";
                } else {
                    $postScenePrompt .= "The scene ended. Thank the client if appropriate and wish them well. ";
                }
            } else {
                $postScenePrompt = "The service session has ended but payment was not confirmed! ";
                $postScenePrompt .= "You may want to remind the client about payment or express displeasure. ";
            }
            $postScenePrompt .= "Keep it brief and business-like.";
            error_log("[AIAGENT_NSFW] Post-service talk for prostitute (paid: " . ($paymentConfirmed ? 'yes' : 'no') . ")");
        } else {
            // Use stored pillow talk prompt if available
            if (!empty($currentIntimacy["pillow_talk_prompt"])) {
                $postScenePrompt = $currentIntimacy["pillow_talk_prompt"];
            } else {
                // REGULAR NPC PILLOW TALK
                $postScenePrompt = "The intimate moment has ended. Share a brief, genuine post-intimacy reaction. ";
                $postScenePrompt .= "This can be affectionate, satisfied, or whatever fits your personality and feelings toward your partner. ";
                $postScenePrompt .= "Keep it natural and in character.";
            }
            error_log("[AIAGENT_NSFW] Pillow talk for regular NPC");
        }

        $GLOBALS["PROMPTS"]["chatnf_sl_end"]["player_request"] = ["The Narrator: " . implode(",", $scoring) . "\n#Post-Scene Guidance: " . $postScenePrompt];
        $GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"] = false;
        $GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"] = "";
    }

    /**
     * Handle chatnf_sl_naked - Actor became naked
     */
    private static function handleNaked() {
        $actor = $GLOBALS["HERIKA_NAME"];
        $intimacyStatus = getIntimacyForActor($actor);
        $intimacyStatus["is_naked"] = 2;
        updateIntimacyForActor($actor, $intimacyStatus);
    }

    /**
     * Handle chatnf_sl_climax - Orgasm event
     */
    private static function handleClimax() {
        global $gameRequest;

        $actor = $GLOBALS["HERIKA_NAME"];
        $intimacyStatus = getIntimacyForActor($actor);

        if (isset($intimacyStatus["orgasm_generated"]) && $intimacyStatus["orgasm_generated"] && isset($intimacyStatus["orgasm_generated_text"])) {
            // We have used GASP. Let's use it.
            if ($GLOBALS["AIAGENT_NSFW"]["USE_GASP"]) {
                echo "{$actor}|ScriptQueue|" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text_original"])) . "////" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text"])) . "\r\n";
                error_log("[AIAGENT-NSFW] Climax from orgasm_generated_text_original");
            } else {
                echo "{$actor}|ScriptQueue|" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text"])) . "////" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text"])) . "\r\n";
                error_log("[AIAGENT-NSFW] Climax from orgasm_generated_text");
            }

            $intimacyStatus["orgasm_generated"] = false;
            $intimacyStatus["orgasm_generated_text"] = "";
            $intimacyStatus["orgasm_generated_text_original"] = "";

            updateIntimacyForActor($actor, $intimacyStatus);
            $GLOBALS["gameRequest"][0] = "infaction";
            $GLOBALS["gameRequest"][3] = "$actor had an orgasm";
            logEvent($GLOBALS["gameRequest"]);

            terminate();
        } else {
            // NPC will generate response via standard prompt
            error_log("[AIAGENT-NSFW] Climax from llm_request should happen");
        }
    }

    /**
     * Handle chatnf_sl_moan - Moan event during scene
     */
    private static function handleMoan() {
        $randomMoans = ["...Ahh ... Ohh..", "Yeah oh...yes", "... Mmmh ... ", "... Ahmmm ...", "..Ouch!... "];
        $moan = $randomMoans[array_rand($randomMoans)];
        returnLines([$moan]);

        $actor = $GLOBALS["HERIKA_NAME"];
        $intimacyStatus = getIntimacyForActor($actor);
        if (!isset($intimacyStatus["orgasm_generated"]) || $intimacyStatus["orgasm_generated"] == false) {
            self::generateClimaxSpeech();
        } else {
            error_log("Orgasm sound already generated");
        }

        terminate();
    }

    /**
     * Handle ext_nsfw_action - Scene action event
     */
    private static function handleAction() {
        $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
        logEvent($GLOBALS["gameRequest"]);
    }

    /**
     * Handle ext_nsfw_scene - Corrected scene event (fixed typo)
     */
    private static function handleSceneEvent() {
        global $gameRequest;

        error_log("[AIAGENT-NSFW] Processing ext_nsfw_scene: {$gameRequest[3]}");

        // Sanitize data
        $sceneData = sanitizeSceneData($gameRequest[3]);

        if ($sceneData['sceneId']) {
            $actorNames = array_column($sceneData['actors'], 'name');

            // Store actor list globally
            $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] = $actorNames;

            // Get description using dual lookup
            $cleanedSceneDesc = getSceneDescription($sceneData['sceneId'], $actorNames);

            // Update intimacy levels for actors
            foreach ($actorNames as $actorName) {
                $intimacyStatus = getIntimacyForActor($actorName);
                $intimacyStatus["level"] = 2; // Active sex
                updateIntimacyForActor($actorName, $intimacyStatus);
            }

            // Rewrite data
            $GLOBALS["gameRequest"][3] = "#INTIMATE SCENE: $cleanedSceneDesc";
            $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
            logEvent($GLOBALS["gameRequest"]);
        }
    }

    /**
     * Handle ext_nsfw_orgasm - Contextual orgasm event
     */
    private static function handleOrgasm() {
        global $gameRequest;

        error_log("[AIAGENT-NSFW] Processing ext_nsfw_orgasm: {$gameRequest[3]}");

        $parts = explode("/", $gameRequest[3]);
        $orgasmerName = trim($parts[0] ?? '');
        $sceneId = trim($parts[1] ?? '');
        $orgasmerIndex = intval($parts[2] ?? 0);

        $actor = $GLOBALS["HERIKA_NAME"];
        $intimacyStatus = getIntimacyForActor($actor);
        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";

        // Check if this is the PLAYER having an orgasm
        $isPlayerOrgasm = (strcasecmp($orgasmerName, $playerName) === 0 || strcasecmp($orgasmerName, "Player") === 0);
        if ($isPlayerOrgasm) {
            if (isNpcSlave($actor)) {
                // Inject owner climax reaction prompt for slave
                try {
                    $relationship = RelationshipManager::getPlayerRelationship($actor);
                    $slaveAffinity = $relationship['aff'] ?? 0;
                    $ownerClimaxPrompt = NsfwRelationship::getSlaveOwnerClimaxPrompt($slaveAffinity, $playerName);
                    if (!empty($ownerClimaxPrompt)) {
                        $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . NsfwRelationship::wrapXml('owner_climax_reaction', $ownerClimaxPrompt);
                        error_log("[AIAGENT-NSFW] Injected SLAVE owner climax reaction for $actor (owner: $playerName, affinity: $slaveAffinity)");
                    }
                } catch (Exception $e) {
                    error_log("[AIAGENT-NSFW] Failed to get slave owner climax prompt: " . $e->getMessage());
                }
            } else {
                // Regular NPC - inject partner climax reaction prompt
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n<partner_orgasm>\n$orgasmerName is cumming! React to your partner's orgasm.\n</partner_orgasm>";
                error_log("[AIAGENT-NSFW] Injected partner climax reaction for $actor (partner: $orgasmerName is cumming)");
            }
        }

        // If NPC is orgasming, build the context message
        if (!$isPlayerOrgasm) {
            // First check if this NPC has a speak style with a climax_prompt
            $npcManager = new NpcMaster();
            $npcData = $npcManager->getByName($orgasmerName);
            $extended = $npcManager->getExtendedData($npcData);
            $npcOrgasmPrompt = '';

            // Try to get climax_prompt from NPC's speak style
            if (!empty($extended['sex_speech_style'])) {
                $speakStyle = NsfwData::getSpeakStyle($extended['sex_speech_style']);
                if (!empty($speakStyle['climax_prompt'])) {
                    $npcOrgasmPrompt = $speakStyle['climax_prompt'];
                    $npcOrgasmPrompt = str_replace('#NPC_NAME#', $orgasmerName, $npcOrgasmPrompt);
                    error_log("[AIAGENT-NSFW] Using speak style climax_prompt for $orgasmerName: " . substr($npcOrgasmPrompt, 0, 50));
                }
            }

            // Fall back to global prompt if no speak style climax_prompt
            if (empty($npcOrgasmPrompt)) {
                $npcOrgasmPrompt = getGlobalPrompt('npc_orgasm_prompt');
                if (empty($npcOrgasmPrompt)) {
                    $npcOrgasmPrompt = "#NPC# is cumming!";
                }
                $npcOrgasmPrompt = str_replace('#NPC#', $orgasmerName, $npcOrgasmPrompt);
            }

            $GLOBALS["gameRequest"][3] = $npcOrgasmPrompt . " " . $gameRequest[3];
            error_log("[AIAGENT-NSFW] NPC orgasm message: {$GLOBALS["gameRequest"][3]}");
        }

        // Get contextual orgasm message if scene ID provided
        $orgasmContext = '';
        if (!empty($sceneId)) {
            $actorNames = [$orgasmerName];
            if (isset($parts[3])) {
                $actorNames[] = trim($parts[3]);
            }
            $orgasmContext = getOrgasmContext($sceneId, $orgasmerIndex, $actorNames);
        }

        if (isset($intimacyStatus["orgasm_generated"]) && $intimacyStatus["orgasm_generated"] && isset($intimacyStatus["orgasm_generated_text"])) {
            // GASP handling
            if ($GLOBALS["AIAGENT_NSFW"]["USE_GASP"]) {
                echo "{$actor}|ScriptQueue|" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text_original"])) . "////" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text"])) . "\r\n";
            } else {
                echo "{$actor}|ScriptQueue|" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text"])) . "////" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text"])) . "\r\n";
            }

            $intimacyStatus["orgasm_generated"] = false;
            $intimacyStatus["orgasm_generated_text"] = "";
            $intimacyStatus["orgasm_generated_text_original"] = "";
            updateIntimacyForActor($actor, $intimacyStatus);

            $contextMsg = !empty($orgasmContext) ? "$actor $orgasmContext" : "$actor had an orgasm";
            $GLOBALS["gameRequest"][0] = "infoaction";
            $GLOBALS["gameRequest"][3] = $contextMsg;
            logEvent($GLOBALS["gameRequest"]);
            terminate();
        } else {
            // Add context to player_request for LLM
            if (!empty($orgasmContext)) {
                $GLOBALS["PROMPTS"]["ext_nsfw_orgasm"]["player_request"] = ["The Narrator: $actor $orgasmContext"];
            }
            error_log("[AIAGENT-NSFW] Contextual orgasm: $orgasmContext");

            // Inject speech style for orgasm
            self::setSexSpeechStyle($actor);
            error_log("[AIAGENT-NSFW] Injected speech style for orgasm: $actor");
        }
    }

    // ============================================
    // SPEECH STYLE AND PROMPT INJECTION
    // ============================================

    /**
     * Inject sex speech style for actor
     * Handles: speak style content, profanity level, kinks (affinity-gated)
     */
    public static function setSexSpeechStyle($actorName) {
        $npcManager = new NpcMaster();
        $npcData = $npcManager->getByName($actorName);
        if (!$npcData) {
            $npcData = $npcManager->getByName(ucFirst(strtolower($actorName)));
        }
        if (isset($npcData["extended_data"])) {
            $extended = json_decode($npcData["extended_data"], true);
        } else {
            $extended = [];
        }

        // Load FULL speak style from JSONB storage (includes all phase prompts)
        if (isset($extended["sex_speech_style"]) && !empty($extended["sex_speech_style"])) {
            $styleName = $extended["sex_speech_style"];

            // Skip 'auto' - that means no override
            if ($styleName !== 'auto') {
                // Get full speak style object with all prompts
                $speakStyle = NsfwData::getSpeakStyle($styleName);
                $styleContent = $speakStyle['content'] ?? '';

                // Fallback: check if .txt file exists (legacy support)
                if (empty($styleContent)) {
                    $styleFile = __DIR__ . "/speakStyles/" . $styleName . ".txt";
                    if (file_exists($styleFile)) {
                        $styleContent = file_get_contents($styleFile);
                    }
                }

                if (!empty($styleContent)) {
                    $speakStyleTemplate = getGlobalPrompt('speak_style_template');
                    if (empty($speakStyleTemplate)) {
                        $speakStyleTemplate = "#Sex Expressions\n#SPEAK_STYLE#";
                    }
                    $speakStyleOutput = str_replace('#SPEAK_STYLE#', $styleContent, $speakStyleTemplate);
                    $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n" . $speakStyleOutput;

                    // Inject init_prompt if present (core sex scene behavior)
                    if (!empty($speakStyle['init_prompt'])) {
                        $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n#Sex Scene Behavior\n" . $speakStyle['init_prompt'];
                    }

                    // Store phase prompts in globals for climax/pillow talk handlers
                    if (!empty($speakStyle['climax_prompt'])) {
                        $GLOBALS["AIAGENTNSFW_CLIMAX_PROMPT"] = $speakStyle['climax_prompt'];
                    }
                    if (!empty($speakStyle['pillow_talk_prompt'])) {
                        $GLOBALS["AIAGENTNSFW_PILLOW_TALK_PROMPT"] = $speakStyle['pillow_talk_prompt'];
                    }
                    if (!empty($speakStyle['masturbation_prompt'])) {
                        $GLOBALS["AIAGENTNSFW_MASTURBATION_PROMPT"] = $speakStyle['masturbation_prompt'];
                    }
                }
            }
        }

        // Inject profanity level if set - READ FROM JSONB SETTINGS
        if (isset($extended["nsfw_profanity_level"]) && !empty($extended["nsfw_profanity_level"])) {
            $profanityLevel = $extended["nsfw_profanity_level"];
            $profanityDesc = '';
            $profanityLabel = '';

            // Normalize to numeric 1-4
            $numericLevel = $profanityLevel;
            if (!is_numeric($profanityLevel)) {
                // Map text to numeric for backwards compatibility
                $textToNumeric = [
                    'soft' => '1',
                    'medium' => '2',
                    'hard' => '3',
                    'naughty' => '4'
                ];
                $numericLevel = $textToNumeric[strtolower($profanityLevel)] ?? '2';
            }

            // Labels for display
            $profanityLabels = [
                '1' => 'Soft',
                '2' => 'Medium',
                '3' => 'Hard',
                '4' => 'Naughty'
            ];
            $profanityLabel = $profanityLabels[$numericLevel] ?? 'Medium';

            // Get profanity description from JSONB settings (config_manager prompts)
            // Load profanity from JSONB - NO hardcoded fallbacks
            // Prompts are configured in config_manager.php aiagent_nsfw_prompts
            require_once __DIR__ . "/nsfw_data.php";
            $prompts = NsfwData::getBlob(NsfwData::KEY_PROMPTS);
            $profanityKey = 'profanity_' . $numericLevel;

            if (isset($prompts[$profanityKey]) && !empty($prompts[$profanityKey])) {
                $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n#Profanity Level: " . $profanityLabel . "\n" . $prompts[$profanityKey];
            }
        }

        // Inject kinks if set - AFFINITY GATED
        $hasNormalKinks = isset($extended["nsfw_kinks"]) && is_array($extended["nsfw_kinks"]) && !empty($extended["nsfw_kinks"]);
        $hasSecretKinks = isset($extended["nsfw_secret_kinks"]) && is_array($extended["nsfw_secret_kinks"]) && !empty($extended["nsfw_secret_kinks"]);

        if ($hasNormalKinks || $hasSecretKinks) {
            // Get affinity with current partner(s)
            // Try GLOBAL first, then fall back to stored intimacy data
            $affinity = 0;
            $sceneActorsForKinks = null;
            if (isset($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"]) && is_array($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"])) {
                $sceneActorsForKinks = $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"];
            } else {
                $intimacyStatus = getIntimacyForActor($actorName);
                if (!empty($intimacyStatus["scene_actors"])) {
                    $sceneActorsForKinks = $intimacyStatus["scene_actors"];
                }
            }

            if (!empty($sceneActorsForKinks)) {
                // Get lowest affinity in group (most restrictive)
                $lowestAffinity = 100;
                foreach ($sceneActorsForKinks as $partner) {
                    if (strtolower($partner) !== strtolower($actorName)) {
                        $partnerAffinity = getNpcAffinity($actorName, $partner);
                        if ($partnerAffinity < $lowestAffinity) {
                            $lowestAffinity = $partnerAffinity;
                        }
                    }
                }
                $affinity = $lowestAffinity;
            } else {
                $affinity = getNpcAffinity($actorName);
            }

            // Thresholds from NPC's extended_data
            $normalKinksThreshold = $extended["nsfw_kinks_unlock_tier"] ?? 56;  // Default: Fond
            $secretKinksThreshold = $extended["nsfw_secret_kinks_unlock_tier"] ?? 76;  // Default: Devoted

            // Inject normal kinks if threshold met
            if ($hasNormalKinks && $affinity >= $normalKinksThreshold) {
                $kinksList = implode(", ", $extended["nsfw_kinks"]);
                $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n#Sexual Preferences/Kinks\nThis character is into: " . $kinksList;
                error_log("[AIAGENTNSFW] Normal kinks unlocked for $actorName (affinity: $affinity >= $normalKinksThreshold)");
            } else if ($hasNormalKinks) {
                error_log("[AIAGENTNSFW] Normal kinks gated for $actorName (affinity: $affinity < $normalKinksThreshold)");
            }

            // Inject secret kinks if threshold met
            if ($hasSecretKinks && $affinity >= $secretKinksThreshold) {
                $secretKinksList = implode(", ", $extended["nsfw_secret_kinks"]);
                $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n#Secret Desires\nTheir deepest, most private desires: " . $secretKinksList;
                error_log("[AIAGENTNSFW] Secret kinks unlocked for $actorName (affinity: $affinity >= $secretKinksThreshold)");
            } else if ($hasSecretKinks) {
                error_log("[AIAGENTNSFW] Secret kinks gated for $actorName (affinity: $affinity < $secretKinksThreshold)");
            }
        }
    }

    /**
     * Inject sex prompt (personality during scenes)
     * Also injects tier-based relationship context for multi-actor scenes
     */
    public static function setSexPrompt($actorName) {
        $npcManager = new NpcMaster();
        $npcData = $npcManager->getByName($actorName);
        if (!$npcData) {
            $npcData = $npcManager->getByName(ucFirst(strtolower($actorName)));
        }

        if (isset($npcData["extended_data"])) {
            $extended = json_decode($npcData["extended_data"], true);
        } else {
            $extended = [];
        }

        // UI saves to sex_prompt in JSONB
        $sexPrompt = $extended["sex_prompt"] ?? null;
        if (!empty($sexPrompt)) {
            $sexPersonalityTemplate = getGlobalPrompt('sex_personality_template');
            if (empty($sexPersonalityTemplate)) {
                $sexPersonalityTemplate = "#Personality (sex scenes)\n#SEX_PROMPT#";
            }
            $sexPersonalityOutput = str_replace('#SEX_PROMPT#', $sexPrompt, $sexPersonalityTemplate);
            error_log("[AIAGENTNSFW] Injected sex_prompt for $actorName");
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $sexPersonalityOutput;
        }

        // Inject tier-based relationship context for multi-actor scenes
        // Try GLOBAL first (set on scene start), then fall back to stored intimacy data
        $allActors = null;
        if (isset($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"]) && is_array($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"])) {
            $allActors = $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"];
        } else {
            // Fallback: get from stored intimacy status (persists across requests)
            $intimacyStatus = getIntimacyForActor($actorName);
            if (!empty($intimacyStatus["scene_actors"])) {
                $allActors = $intimacyStatus["scene_actors"];
                error_log("[AIAGENTNSFW] Using stored scene_actors for $actorName: " . implode(", ", $allActors));
            }
        }

        if (!empty($allActors) && is_array($allActors)) {

            $isProstitute = !empty($extended['is_prostitute']) ||
                            !empty($extended['profession_prostitute']) ||
                            !empty($extended['adult_entertainment_services_autodetected']);

            $sceneContext = NsfwRelationship::buildSceneContext($actorName, $allActors, $isProstitute);

            if (!empty($sceneContext)) {
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $sceneContext;
                error_log("[AIAGENTNSFW] Injected tier relationship context for $actorName: " . substr($sceneContext, 0, 200) . "...");
            }
        }
    }

    // ============================================
    // CLIMAX SPEECH GENERATION
    // ============================================

    /**
     * Generate climax speech using LLM
     * Creates short orgasm vocalizations
     */
    public static function generateClimaxSpeech() {
        $actor = $GLOBALS["HERIKA_NAME"];
        $intimacyStatus = getIntimacyForActor($actor);

        error_log("[GASP] $actor");

        if (!isset($intimacyStatus["orgasm_generated"]) || $intimacyStatus["orgasm_generated"] == false) {
            error_log("Generating gasped orgasm sound");

            $historyData = "";
            $lastPlace = "";
            $lastListener = "";
            $lastDateTime = "";

            // Determine how much context history to use
            $dynamicProfileContextHistory = 50;
            if (isset($GLOBALS["CONTEXT_HISTORY_DYNAMIC_PROFILE"]) && $GLOBALS["CONTEXT_HISTORY_DYNAMIC_PROFILE"] > 0) {
                $dynamicProfileContextHistory = $GLOBALS["CONTEXT_HISTORY_DYNAMIC_PROFILE"];
            } elseif (isset($GLOBALS["CONTEXT_HISTORY"]) && $GLOBALS["CONTEXT_HISTORY"] > 0) {
                $dynamicProfileContextHistory = $GLOBALS["CONTEXT_HISTORY"];
            }

            foreach (json_decode(DataSpeechJournal($GLOBALS["HERIKA_NAME"], $dynamicProfileContextHistory), true) as $element) {
                if ($element["listener"] == "The Narrator") {
                    continue;
                }
                if ($lastListener != $element["listener"]) {
                    $listener = " (talking to {$element["listener"]})";
                    $lastListener = $element["listener"];
                } else {
                    $listener = "";
                }

                if ($lastPlace != $element["location"]) {
                    $place = " (at {$element["location"]})";
                    $lastPlace = $element["location"];
                } else {
                    $place = "";
                }

                if ($lastDateTime != substr($element["sk_date"], 0, 15)) {
                    $date = substr($element["sk_date"], 0, 10);
                    $time = substr($element["sk_date"], 11);
                    $dateTime = "(on date {$date} at {$time})";
                    $lastDateTime = substr($element["sk_date"], 0, 15);
                } else {
                    $dateTime = "";
                }

                $historyData .= trim("{$element["speaker"]}:" . trim($element["speech"]) . " $listener $place $dateTime") . PHP_EOL;
            }

            $partyConf = DataGetCurrentPartyConf();
            $partyConfA = json_decode($partyConf, true);
            Logger::debug($partyConf);

            $updateProfilePrompt = "Write a VERY SHORT sentence (3/4 words) for {$GLOBALS["HERIKA_NAME"]}
            to say when she/he reaches Climax based on Dialogue history, and {$GLOBALS["PLAYER_NAME"]} requests,stuttering. Read  {$GLOBALS["HERIKA_NAME"]} character sheet to figure out her sexual preferences.";

            $head[] = ["role" => "system", "content" => "You are an NSFW writer.  "];
            $currentDynamicProfile = buildDynamicProfileDisplay();

            $prompt[] = ["role" => "user", "content" => "Current character profile you are generating content for:\n" . "Character name:\n" . $GLOBALS["HERIKA_NAME"] . "\nCharacter static biography:\n" . $GLOBALS["HERIKA_PERS"] . "\n" . $currentDynamicProfile];
            $prompt[] = ["role" => "user", "content" => "* Dialogue history:\n" . $historyData];
            $prompt[] = ["role" => "user", "content" => $updateProfilePrompt];
            $contextData = array_merge($head, $prompt);

            if (isset($GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"])) {
                $connector = new LLMConnector();
                $connectionHandler = $connector->getConnector($GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"]);
                error_log("[CORE SYSTEM] Using new profile system {$GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"]["driver"]}/{$GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"]["model"]}");
            } else {
                error_log("No connector defined");
                return;
            }

            $GLOBALS["FORCE_MAX_TOKENS"] = 50;
            $buffer = $connectionHandler->fast_request($contextData, ["max_tokens" => 50], "aiagent_nsfw");

            $original_speech = " ... Ohh .. " . (strtr(trim($buffer), ['"' => '', "{$GLOBALS["HERIKA_NAME"]}:" => ""]));

            $GLOBALS["PATCH_DONT_STORE_SPEECH_ON_DB"] = true;
            unset($GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"]);

            $GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"][] = function ($text) {
                $randomStrings = ["  ", "  "];
                $result = $text;
                $randomIndex = mt_rand(0, count($randomStrings) - 1);
                $words = explode(' ', $text);
                $wordIndex = mt_rand(0, count($words) - 1);
                $randomWord = $words[$wordIndex];
                $insertPosition = strpos($result, $randomWord);
                $result = substr_replace($result, $randomStrings[$randomIndex], $insertPosition, 0);
                error_log("Applying text modifier for XTTS (speed=>0.6) $text => $result " . __FILE__);

                xtts_fastapi_settings(["temperature" => 1, "speed" => 0.6, "enable_text_splitting" => false, "top_p" => 1, "top_k" => 100], true);
                return $result;
            };

            returnLines([$original_speech], false);
            $generatedFile = end($GLOBALS["TRACK"]["FILES_GENERATED"]);

            $intimacyStatus["orgasm_generated"] = true;
            $intimacyStatus["orgasm_generated_text"] = $original_speech;
            $intimacyStatus["orgasm_generated_text_original"] = trim(unmoodSentence($original_speech));

            updateIntimacyForActor($actor, $intimacyStatus);
        } else {
            error_log("Orgasm sound already generated");
        }
    }

    /**
     * GASP audio processing for orgasm sounds
     */
    public static function gasper($original_speech, $moan, $sourceaudio, $sourcevoiceaudio) {
        $moanfile = "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/cough.wav";

        $moanLibrary = [
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxD1.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxE1.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxE2.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxE4.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxE5.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxE6.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxF1.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxG1.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxA1.wav"],
            ["transcription" => true, "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxA2.wav"],
            ["transcription" => true, "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxA3.wav"],
        ];
        $selectedIndex = rand(0, sizeof($moanLibrary) - 1);

        if (isset($GLOBALS["AIAGENT_NSFW"]["USE_GASP"]) && $GLOBALS["AIAGENT_NSFW"]["USE_GASP"]) {
            $tempfile = "/tmp/" . uniqid() . ".wav";
            $command = "/usr/local/bin/gasp $sourceaudio {$moanLibrary[$selectedIndex]["file"]} \"$original_speech\" $tempfile";

            $output = shell_exec($command);
            error_log("[GASP] Command output: " . $output);
            error_log("[GASP] Source {$moanLibrary[$selectedIndex]["file"]}, Out file: " . $tempfile);

            $input = str_replace("..", " ", trim($output));
            $patterns = [
                '/AAaa/' => 'AAah',
                '/aaAA/' => 'aaAH',
                '/AAAA/' => 'AAAA',
                '/AA/' => 'AH',
                '/aaaa/' => 'Aaah',
                '/aa/' => 'Ah',
            ];
            $output = preg_replace(array_keys($patterns), array_values($patterns), $input);
            $output .= "  $original_speech";

            $finalPseudoPhonetic = trim(unmoodSentence($output));
        } else {
            $tempfile = "/tmp/" . uniqid() . ".wav";
            $command = "/usr/local/bin/gasp /opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/silence.wav {$moanLibrary[$selectedIndex]["file"]} \"$original_speech\" $tempfile";

            $output = shell_exec($command);
            error_log("[GASP] Command output: " . $output);
            error_log("[GASP] Out file: " . $tempfile);

            $input = str_replace("..", " ", trim($output));
            $patterns = [
                '/AAaa/' => 'AAah',
                '/aaAA/' => 'aaAH',
                '/AAAA/' => 'AAAA',
                '/AA/' => 'AH',
                '/aaaa/' => 'Aaah',
                '/aa/' => 'Ah',
            ];
            $output = preg_replace(array_keys($patterns), array_values($patterns), $input);
            $finalPseudoPhonetic = trim(unmoodSentence($output));
        }

        error_log("[GASP] finalPseudoPhonetic: $finalPseudoPhonetic");

        if (!file_exists($tempfile)) {
            error_log("[GASP] Source audio file not found: $tempfile");
        }
        if (!file_exists($sourcevoiceaudio)) {
            error_log("[GASP] Reference audio file not found: $sourcevoiceaudio");
        }

        $sourceAudioPath = realpath($tempfile);
        $referenceAudioPath = realpath($sourcevoiceaudio);

        if (!$sourceAudioPath || !$referenceAudioPath) {
            error_log("[GASP] File path resolution failed.");
        }

        if (!file_exists($sourceAudioPath) || !is_readable($sourceAudioPath)) {
            error_log("[GASP] Source audio file not accessible: " . $sourceAudioPath);
        }
        if (!file_exists($referenceAudioPath) || !is_readable($referenceAudioPath)) {
            error_log("[GASP] Reference audio file not accessible: " . $referenceAudioPath);
        }

        $tempResfile = $GLOBALS["ENGINE_PATH"] . "/soundcache/" . md5($finalPseudoPhonetic) . ".wav";
        $original_speech_cleaned = trim(unmoodSentence($original_speech));
        $tempResfile2 = $GLOBALS["ENGINE_PATH"] . "/soundcache/" . md5($original_speech_cleaned) . ".wav";

        copy($tempfile, $tempResfile);
        error_log("[GASP] $tempResfile saved successfully.");

        return $finalPseudoPhonetic;
    }
}
