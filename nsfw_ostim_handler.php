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

        // Track if this is The Narrator profile. Scene state updates (handleSceneUpdate)
        // MUST still run for Narrator — Papyrus routes scene events through The Narrator,
        // and handleSceneUpdate() updates intimacy data for the ACTUAL scene actors
        // (from OStim event data, not from HERIKA_NAME). But The Narrator must never
        // generate scene dialogue — FORCE_STOP handles that inside handleSceneUpdate().
        $herikaName = $GLOBALS["HERIKA_NAME"] ?? '';
        $isNarratorProfile = ($herikaName === "The Narrator" || $herikaName === "Character");
        $GLOBALS["AIAGENTNSFW_IS_NARRATOR_PROFILE"] = $isNarratorProfile;

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
        } else if ($gameRequest[0] == "ext_nsfw_npc_invite") {
            self::handleNpcInvite();
        } else if ($gameRequest[0] == "ext_nsfw_npc_orgasm") {
            self::handleNpcOrgasm();
        }
    }

    /**
     * Handle ext_nsfw_sexcene - Main scene update event
     * Parse info_sexscene data and manage scene phases
     */
    private static function handleSceneUpdate() {
        global $gameRequest;

        // Parse info_sexscene data
        // Format: SceneName/Tags/StageName/Actor1/Actor2/...
        $infoSexSceneParts = explode("/", $gameRequest[3]);
        $sexSceneName      = $infoSexSceneParts[0];
        $sexTags           = explode(",", strtolower($infoSexSceneParts[1]));
        $sexStageName      = strtr($infoSexSceneParts[2], ["_A1" => ""]);
        $actorInfos        = array_slice($infoSexSceneParts, 3);

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

        // Primary partner = first non-player actor in OStim's ordering (Actor0/Actor1)
        // When user changes positions, OStim reorders actors — this updates automatically
        $primaryPartner = !empty($otherActors) ? $otherActors[0] : null;

        // Store actor list globally for tier prompt injection
        $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] = $orderedActorList;

        // ============================================
        // IMMEDIATE LOG WRITE — before any complex processing
        // ============================================
        // This MUST happen first so scene events show in CHIM log
        // regardless of what crashes later in intimacy/tier/description code.
        // ============================================
        $partnerNamesEarly = array_filter($orderedActorList, function($a) { return $a !== $GLOBALS["PLAYER_NAME"]; });
        $partnerStrEarly = ($primaryPartner && count($partnerNamesEarly) > 1) ? "$primaryPartner (and " . implode(", ", array_filter($partnerNamesEarly, function($a) use ($primaryPartner) { return $a !== $primaryPartner; })) . ")" : implode(" and ", $partnerNamesEarly);
        try {
            $earlyLogData = $GLOBALS["gameRequest"];
            $earlyLogData[0] = "info";
            $earlyLogData[3] = "[SCENE] with $partnerStrEarly | Stage: $sexStageName | Tags: " . implode(",", $sexTags);
            logEvent($earlyLogData);
            error_log("[AIAGENTNSFW] SCENE LOGGED: $partnerStrEarly | $sexStageName | " . implode(",", $sexTags));
        } catch (Exception $e) {
            error_log("[AIAGENTNSFW] ERROR writing scene log: " . $e->getMessage());
        }

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
            // A scene is NEW if:
            // 1. No scene_phase set at all (never been in a scene)
            // 2. scene_phase is null (previous scene ended and was cleared)
            // 3. scene_start_time is old (previous scene was >5 min ago, stale data)
            $isNewScene = !isset($intimacyStatus["scene_phase"]) || $intimacyStatus["scene_phase"] === null;

            // Also check for stale scene data - if last scene was >5 minutes ago, treat as new
            if (!$isNewScene && isset($intimacyStatus["scene_start_time"])) {
                $timeSinceSceneStart = time() - $intimacyStatus["scene_start_time"];
                if ($timeSinceSceneStart > 300) {  // 5 minutes
                    error_log("[AIAGENTNSFW] Stale scene data for $actor (started " . $timeSinceSceneStart . "s ago) - treating as new scene");
                    $isNewScene = true;
                }
            }

            if ($isNewScene) {
                // NEW SCENE: Always start with tier prompt phase (accept/refuse)
                // Arousal gating only affects the threshold check AFTER accept (in prerequest.php)
                $intimacyStatus["level"] = 0;
                $intimacyStatus["scene_phase"] = "tier_prompt";
                $intimacyStatus["scene_is_idle"] = in_array("idle", $sexTags);
                $intimacyStatus["scene_start_time"] = time();
                $intimacyStatus["scene_actors"] = $orderedActorList;
                $intimacyStatus["current_primary_partner"] = $primaryPartner;
                error_log("[AIAGENTNSFW] New scene for $actor - starting tier_prompt phase (actors: " . implode(", ", $orderedActorList) . ", primary: $primaryPartner)");

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
                    // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
                    require_once __DIR__ . "/nsfw_data.php";
                    $extended_data = NsfwNpcData::get($actor);
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

                    // Slaves auto-accept (they can't refuse)
                    // Prostitutes auto-accept but need payment negotiation
                    // Regular NPCs stay at tier_prompt for affinity-based accept/refuse
                    if ($isSlave) {
                        $intimacyStatus["scene_phase"] = "accepted";
                        error_log("[AIAGENTNSFW] Auto-accepting for $actor (slave) on scene start");
                    } else if ($isProstitute) {
                        $intimacyStatus["scene_phase"] = "accepted";
                        $intimacyStatus["is_transaction"] = true;
                        $intimacyStatus["payment_confirmed"] = false;
                        $intimacyStatus["negotiation_phase"] = true;
                        error_log("[AIAGENTNSFW] Prostitute transaction started for $actor - awaiting CollectPayment");
                    }
                    // Regular NPCs stay at tier_prompt - prerequest.php handles accept/refuse

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
                // Continuing scene for $actor

                // ============================================
                // DETECT TRANSITION: Idle → Actual Sex
                // ============================================
                // When scene was idle but now has actual sex tags, mark sex_started
                // This triggers kink/sex prompt injection in prerequest.php
                // ============================================
                $wasIdle = !empty($intimacyStatus["scene_is_idle"]);
                $isNowIdle = in_array("idle", $sexTags);

                if ($wasIdle && !$isNowIdle && empty($intimacyStatus["sex_started"])) {
                    // TRANSITION: Idle → Actual Sex!
                    $intimacyStatus["scene_is_idle"] = false;
                    $intimacyStatus["sex_started"] = true;
                    error_log("[AIAGENTNSFW] SEX STARTED for $actor - transitioning from idle to actual sex");
                } else if (!$wasIdle && !$isNowIdle && empty($intimacyStatus["sex_started"])) {
                    // Scene started directly with sex (no idle phase)
                    $intimacyStatus["sex_started"] = true;
                    error_log("[AIAGENTNSFW] SEX STARTED for $actor - scene started with sex (no idle)");
                }

                // Update idle status for current stage
                $intimacyStatus["scene_is_idle"] = $isNowIdle;
            }

            updateIntimacyForActor($actor, $intimacyStatus);
        }

        // ============================================
        // PROPAGATE scene_actors TO ALL PARTICIPANTS
        // ============================================
        // Every NPC in the scene needs scene_actors set so they can be identified
        // as scene participants. This is purely informational - prerequest.php
        // handles the phase/level logic based on arousal gating setting.
        // ============================================
        foreach ($orderedActorList as $otherActor) {
            if ($otherActor === $actor || $otherActor === $GLOBALS["PLAYER_NAME"]) continue;

            $otherIntimacy = getIntimacyForActor($otherActor);

            // If this actor doesn't have scene_actors set, propagate it
            if (empty($otherIntimacy["scene_actors"]) || $otherIntimacy["scene_actors"] !== $orderedActorList) {
                $otherIntimacy["scene_actors"] = $orderedActorList;
                updateIntimacyForActor($otherActor, $otherIntimacy);
                // Propagated scene_actors to $otherActor
            }
        }

        // ============================================
        // CLEAR STALE EVENTS FOR SCENE PARTICIPANTS
        // ============================================
        // On scene position changes, remove pending prechat/rechat events for
        // participants. This forces NPCs to respond to the CURRENT scene state
        // instead of processing a stale dialogue backlog.
        // ============================================
        if (isset($GLOBALS["db"])) {
            foreach ($orderedActorList as $clearActor) {
                if ($clearActor === $GLOBALS["PLAYER_NAME"]) continue;
                $escapedActor = $GLOBALS["db"]->escape($clearActor);
                $GLOBALS["db"]->delete("eventlog",
                    "type in ('prechat','rechat') and people like '%|$escapedActor|%' and localts>" . (time() - 300)
                );
            }
            // Cleared stale events for scene participants
        }

        // Look up scene description

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

        // ============================================
        // STORE SCENE DESCRIPTION IN INTIMACY DATA
        // ============================================
        // So NPC events (chatnf_sl) know the current scene when they speak.
        // Without this, the NPC has no idea what position/animation is happening.
        // ============================================
        foreach ($orderedActorList as $storeActor) {
            if ($storeActor === $GLOBALS["PLAYER_NAME"]) continue;
            $storeIntimacy = getIntimacyForActor($storeActor);
            $storeIntimacy["current_scene_desc"] = $cleanedSceneDesc;
            $storeIntimacy["current_scene_tags"] = $sexTags;
            $storeIntimacy["current_scene_name"] = $sexSceneName;
            $storeIntimacy["current_primary_partner"] = $primaryPartner;
            $storeIntimacy["scene_actors"] = $orderedActorList;
            updateIntimacyForActor($storeActor, $storeIntimacy);
        }

        // Check if ANY actor in this scene is in tier_prompt phase (new scene)
        $anyActorInTierPrompt = false;
        foreach ($orderedActorList as $checkActor) {
            if ($checkActor === $GLOBALS["PLAYER_NAME"]) continue;
            $checkIntimacy = getIntimacyForActor($checkActor);
            if (($checkIntimacy["scene_phase"] ?? null) === "tier_prompt") {
                $anyActorInTierPrompt = true;
                break;
            }
        }

        // ============================================
        // CONTEXT INJECTION: tier_prompt vs engaged
        // ============================================
        // tier_prompt = Inject tier prompt from database - NPC decides accept/refuse
        // accepted/engaged = Full intimate scene context
        // ============================================
        if ($anyActorInTierPrompt) {
            // TIER_PROMPT PHASE: Inject the tier prompt directly as the chat cue
            // The NPC needs to see this prompt and respond to it immediately
            require_once __DIR__ . "/nsfw_relationship.php";

            // Find the first NPC in tier_prompt phase to get their tier prompt
            $tierPromptNpc = null;
            foreach ($orderedActorList as $checkActor) {
                if ($checkActor === $GLOBALS["PLAYER_NAME"]) continue;
                $checkIntimacy = getIntimacyForActor($checkActor);
                if (($checkIntimacy["scene_phase"] ?? null) === "tier_prompt") {
                    $tierPromptNpc = $checkActor;
                    break;
                }
            }

            if ($tierPromptNpc) {
                // Build the tier prompt for this NPC
                $tierPromptContext = NsfwRelationship::buildSceneContext($tierPromptNpc, $orderedActorList, false);

                if (!empty($tierPromptContext)) {
                    // Set the tier prompt as the gameRequest data - this is what the model sees
                    $GLOBALS["gameRequest"][3] = $tierPromptContext;
                    error_log("[AIAGENTNSFW] tier_prompt phase - injected tier prompt for $tierPromptNpc");
                } else {
                    // Fallback if no tier prompt found - still don't claim intimate scene
                    $GLOBALS["gameRequest"][3] = "";
                    error_log("[AIAGENTNSFW] tier_prompt phase - no tier prompt found, cleared scene context");
                }
            } else {
                $GLOBALS["gameRequest"][3] = "";
                error_log("[AIAGENTNSFW] tier_prompt phase - no NPC in tier_prompt found");
            }
        } else {
            // ACCEPTED/ENGAGED: Full intimate scene context
            // Explicitly name the primary partner so the model knows who the player is focused on
            $partnerNames = array_filter($orderedActorList, function($a) { return $a !== $GLOBALS["PLAYER_NAME"]; });
            $partnerStr = implode(" and ", $partnerNames);
            $primaryNote = ($primaryPartner && count($partnerNames) > 1) ? " (currently focused on $primaryPartner)" : "";
            $GLOBALS["gameRequest"][3] = "#INTIMATE SCENE with $partnerStr$primaryNote: $cleanedSceneDesc. Scene tags:" . implode(",", $sexTags);
        }

        // ============================================
        // CRITICAL: Update PROMPTS array with new gameRequest[3]
        // ============================================
        // prompts.php was loaded BEFORE this runs, so it has stale gameRequest[3].
        // We MUST update the PROMPTS["ext_nsfw_sexcene"]["player_request"] now
        // so that request.php picks up the correct tier prompt (not #INTIMATE SCENE)
        // ============================================
        if (isset($GLOBALS["PROMPTS"]["ext_nsfw_sexcene"])) {
            $GLOBALS["PROMPTS"]["ext_nsfw_sexcene"]["player_request"] = [$GLOBALS["gameRequest"][3]];
        }

        // FORCE_STOP controls whether LLM runs for this event.
        // With hash-based dedup, ONLY events with NEW scene data reach here.
        // So every event here is either a NEW scene or a SCENE CHANGE — never a repeat.
        //
        // Narrator + new scene (tier_prompt): FORCE_STOP=true — NPC handles via chatnf_sl
        // Narrator + scene CHANGE: FORCE_STOP=false — Narrator narrates the position change
        //   (OStim doesn't fire chatnf_sl on position changes, so nobody else will respond)
        // NPC + tier_prompt: FORCE_STOP=false — NPC responds to accept/refuse
        // NPC + continuing: FORCE_STOP=false — NPC responds to scene change
        if (!empty($GLOBALS["AIAGENTNSFW_IS_NARRATOR_PROFILE"])) {
            if ($anyActorInTierPrompt) {
                // New scene — NPC will handle via separate chatnf_sl event
                $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
            } else {
                // Scene CHANGE — let Narrator narrate it (only way it shows in log)
                $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = false;
            }
        } else {
            // NPC profile — always let them respond (dedup prevents repeats)
            $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = false;
        }

        error_log("[AIAGENTNSFW] Scene processed: $sexSceneName | Actors: " . implode(",", $orderedActorList) . " | Primary: " . ($primaryPartner ?? "none") . " | Desc: $cleanedSceneDesc | FORCE_STOP=" . ($GLOBALS["AIAGENTNSFW_FORCE_STOP"] ? "Y" : "N"));

        // Log the full event (with description) now that we have it computed
        logEvent($GLOBALS["gameRequest"]);
    }

    /**
     * Handle ext_nsfw_npc_scene - NPC-to-NPC scene (no player)
     *
     * Sets up BOTH NPCs with full intimacy tracking, sex prompts, and tier prompts
     * just like player scenes. This allows NPCs to react to each other properly
     * during OStim NPCs mod scenes.
     */
    private static function handleNpcScene() {
        global $gameRequest;

        error_log("[AIAGENT-NSFW] Processing NPC-to-NPC scene: {$gameRequest[3]}");

        $result = NsfwNpcScene::processNpcScene($gameRequest[3]);

        if (!$result) {
            error_log("[AIAGENT-NSFW] Failed to process NPC scene data");
            terminate();
            return;
        }

        $npc1Name = $result['npc1']['name'];
        $npc2Name = $result['npc2']['name'];
        $threadID = $result['threadID'];
        $sceneID = $result['sceneID'];

        error_log("[AIAGENT-NSFW] NPC-to-NPC Scene: {$npc1Name} + {$npc2Name} (Thread: {$threadID}, Scene: {$sceneID})");

        // Build actor list for NPC-to-NPC scene (no player)
        $orderedActorList = [$npc1Name, $npc2Name];
        $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] = $orderedActorList;
        $GLOBALS["AIAGENTNSFW_NPC_SCENE"] = true;  // Flag this as NPC-only scene
        $GLOBALS["AIAGENTNSFW_NPC_THREAD_ID"] = $threadID;

        // ============================================
        // SET UP BOTH NPCs WITH FULL INTIMACY TRACKING
        // ============================================
        // Mirror what handleSceneUpdate() does for player scenes
        // Each NPC gets: scene_actors, scene_phase, tier prompts, sex prompts
        // ============================================

        require_once __DIR__ . "/nsfw_data.php";
        require_once __DIR__ . "/nsfw_relationship.php";

        foreach ($orderedActorList as $actor) {
            $intimacyStatus = getIntimacyForActor($actor);

            // Check if this is a NEW scene or continuation
            $isNewScene = !isset($intimacyStatus["scene_phase"]) || $intimacyStatus["scene_phase"] === null;

            if ($isNewScene) {
                // NEW NPC SCENE: Always start with tier_prompt (accept/refuse based on affinity)
                // Arousal gating only affects the threshold check AFTER accept (in prerequest.php)
                $intimacyStatus["level"] = 0;
                $intimacyStatus["scene_phase"] = "tier_prompt";
                $intimacyStatus["scene_is_idle"] = false;  // NPC scenes typically aren't idle
                $intimacyStatus["scene_start_time"] = time();
                $intimacyStatus["scene_actors"] = $orderedActorList;
                $intimacyStatus["is_npc_scene"] = true;  // Mark as NPC-to-NPC scene
                $intimacyStatus["npc_scene_partner"] = ($actor === $npc1Name) ? $npc2Name : $npc1Name;
                $intimacyStatus["npc_scene_thread_id"] = $threadID;

                error_log("[AIAGENTNSFW] New NPC scene for $actor - starting tier_prompt phase (partner: {$intimacyStatus['npc_scene_partner']})");

                // ============================================
                // DETERMINE NPC TYPE (PROSTITUTE, SLAVE, ETC)
                // ============================================
                $npcManager = new NpcMaster();
                $npcData = $npcManager->getByName($actor);
                $extendedData = NsfwNpcData::get($actor);

                $isProstitute = false;
                $isSlave = false;

                if (!empty($extendedData)) {
                    $isProstitute = !empty($extendedData['is_prostitute']) || !empty($extendedData['profession_prostitute']);
                    $isSlave = !empty($extendedData['is_slave']);
                }

                // Store NPC type for prerequest.php
                $intimacyStatus["is_prostitute"] = $isProstitute;
                $intimacyStatus["is_slave"] = $isSlave;

                // ============================================
                // GET TIER PROMPT FOR THIS NPC
                // ============================================
                // Use relationship between the two NPCs
                $partnerName = $intimacyStatus["npc_scene_partner"];
                // Get NPC-to-NPC relationship (not player relationship)
                $relationship = RelationshipManager::getRelationship($actor, $partnerName);
                $affinity = $relationship['aff'] ?? 0;
                $tier = strtolower($relationship['tier'] ?? 'neutral');

                // Get appropriate tier prompt
                $tierPrompt = NsfwRelationship::getTierPromptByAffinity($affinity, $isProstitute, $partnerName, $actor);
                $intimacyStatus["cached_tier_prompt"] = $tierPrompt;
                $intimacyStatus["partner_affinity"] = $affinity;
                $intimacyStatus["partner_tier"] = $tier;

                error_log("[AIAGENTNSFW] NPC $actor tier prompt for partner $partnerName (affinity: $affinity, tier: $tier, prostitute: " . ($isProstitute ? 'YES' : 'NO') . ")");

                // ============================================
                // LOAD SEX PROMPT AND SPEAK STYLE
                // ============================================
                // These will be injected when the NPC speaks during the scene
                if (!empty($extendedData['sex_prompt']) || !empty($extendedData['nsfw_sex_prompt'])) {
                    $intimacyStatus["has_sex_prompt"] = true;
                    error_log("[AIAGENTNSFW] NPC $actor has configured sex prompt");
                }

                if (!empty($extendedData['sex_speech_style']) || !empty($extendedData['nsfw_speak_style'])) {
                    $intimacyStatus["has_speak_style"] = true;
                    error_log("[AIAGENTNSFW] NPC $actor has configured speak style");
                }

                // Save intimacy status for this NPC
                updateIntimacyForActor($actor, $intimacyStatus);
            } else {
                // CONTINUING SCENE: Update scene actors if needed
                if (empty($intimacyStatus["scene_actors"]) || $intimacyStatus["scene_actors"] !== $orderedActorList) {
                    $intimacyStatus["scene_actors"] = $orderedActorList;
                    $intimacyStatus["is_npc_scene"] = true;
                    updateIntimacyForActor($actor, $intimacyStatus);
                }
                error_log("[AIAGENTNSFW] Continuing NPC scene for $actor (phase: {$intimacyStatus['scene_phase']})");
            }
        }

        // ============================================
        // BUILD SCENE CONTEXT FOR LOGGING
        // ============================================
        $npc1Affinity = $result['npc1']['affinity'] ?? 0;
        $npc2Affinity = $result['npc2']['affinity'] ?? 0;
        $npc1Tier = RelationshipManager::getTierLabel($npc1Affinity);
        $npc2Tier = RelationshipManager::getTierLabel($npc2Affinity);

        $contextMsg = "NPC intimate scene started: {$npc1Name} ({$npc1Tier} toward {$npc2Name}) and {$npc2Name} ({$npc2Tier} toward {$npc1Name})";
        error_log("[AIAGENTNSFW] $contextMsg");

        $GLOBALS["gameRequest"][3] = $contextMsg;
        logEvent($GLOBALS["gameRequest"]);

        // Don't terminate - let the request flow continue so NPCs can speak
        // The prerequest.php will inject their prompts when they respond
    }

    /**
     * Handle ext_nsfw_npc_invite - NPC-to-NPC invite phase
     * Fires BEFORE the scene starts when dom actor approaches sub actor
     * This is the perfect time to inject tier prompts based on relationship
     *
     * Data format: DomActor^SubActor^ThirdActor (third is optional)
     */
    private static function handleNpcInvite() {
        global $gameRequest;

        error_log("[AIAGENT-NSFW] NPC Invite phase: {$gameRequest[3]}");

        // Parse invite data (using ^ delimiter)
        $parts = explode('^', $gameRequest[3]);

        if (count($parts) < 2) {
            error_log("[AIAGENT-NSFW] Invalid invite data: {$gameRequest[3]}");
            terminate();
            return;
        }

        $domActor = $parts[0];
        $subActor = $parts[1];
        $thirdActor = isset($parts[2]) ? $parts[2] : null;

        error_log("[AIAGENT-NSFW] NPC Invite: {$domActor} -> {$subActor}" . ($thirdActor ? " + {$thirdActor}" : ""));

        // Build actor list
        $actorList = [$domActor, $subActor];
        if ($thirdActor) {
            $actorList[] = $thirdActor;
        }

        require_once __DIR__ . "/nsfw_relationship.php";
        require_once __DIR__ . "/nsfw_data.php";

        // Set up intimacy tracking for each NPC before the scene starts
        // This injects tier prompts based on their relationship with each other
        foreach ($actorList as $actor) {
            $intimacyStatus = getIntimacyForActor($actor);

            // Set invite phase - NPCs are approaching each other
            $intimacyStatus["level"] = 0;
            $intimacyStatus["scene_phase"] = "invite";
            $intimacyStatus["scene_actors"] = $actorList;
            $intimacyStatus["invite_time"] = time();

            // Determine partner (the other actor)
            $partnerName = ($actor === $domActor) ? $subActor : $domActor;

            // Check NPC type (slave, prostitute)
            $isSlave = isNpcSlave($actor);
            $isProstitute = isProstitute($actor);

            $intimacyStatus["npc_is_slave"] = $isSlave;
            $intimacyStatus["npc_is_prostitute"] = $isProstitute;
            $intimacyStatus["npc_partner"] = $partnerName;

            error_log("[AIAGENT-NSFW] NPC Invite setup for {$actor}: partner={$partnerName}, slave=" . ($isSlave ? "YES" : "no") . ", prostitute=" . ($isProstitute ? "YES" : "no"));

            updateIntimacyForActor($actor, $intimacyStatus);
        }

        // Log the invite event for narrative purposes
        $contextMsg = "{$domActor} is approaching {$subActor} with romantic intent.";
        if ($thirdActor) {
            $contextMsg = "{$domActor} is approaching {$subActor} and {$thirdActor} with romantic intent.";
        }

        $GLOBALS["gameRequest"][3] = $contextMsg;
        logEvent($GLOBALS["gameRequest"]);

        error_log("[AIAGENT-NSFW] NPC Invite phase complete - tier prompts will inject when NPCs speak");
    }

    /**
     * Handle ext_nsfw_npc_orgasm - NPC orgasm in NPC-to-NPC scene
     * Data format: actorName^partnerName^sceneID
     */
    private static function handleNpcOrgasm() {
        global $gameRequest;

        error_log("[AIAGENT-NSFW] NPC Orgasm: {$gameRequest[3]}");

        // Parse orgasm data (using ^ delimiter)
        $parts = explode('^', $gameRequest[3]);

        if (count($parts) < 2) {
            error_log("[AIAGENT-NSFW] Invalid NPC orgasm data: {$gameRequest[3]}");
            terminate();
            return;
        }

        $orgasmedActor = $parts[0];
        $partnerName = $parts[1];
        $sceneID = isset($parts[2]) ? $parts[2] : '';

        error_log("[AIAGENT-NSFW] NPC Orgasm: {$orgasmedActor} climaxed with {$partnerName}");

        // Log the orgasm event with context
        $contextMsg = "{$orgasmedActor} is reaching climax with {$partnerName}.";
        $GLOBALS["gameRequest"][3] = $contextMsg;
        logEvent($GLOBALS["gameRequest"]);

        error_log("[AIAGENT-NSFW] NPC Orgasm logged for {$orgasmedActor}");
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

        // ============================================
        // PURGE SEX EVENT BACKLOG — scene is over, nothing queued should fire
        // ============================================
        if (isset($GLOBALS["db"]) && !empty($sceneActors)) {
            $sexTypes = "'chatnf_sl','chatnf_sl_moan','chatnf_sl_naked','chatnf_sl_climax',"
                      . "'ext_nsfw_sexcene','ext_nsfw_orgasm','ext_nsfw_action','ext_nsfw_scene',"
                      . "'prechat','rechat','narration'";
            foreach ($sceneActors as $clearActor) {
                if (strcasecmp($clearActor, $playerName) === 0) continue;
                $escapedActor = $GLOBALS["db"]->escape($clearActor);
                $GLOBALS["db"]->delete("eventlog",
                    "type in ($sexTypes) and people like '%|$escapedActor|%'"
                );
            }
            error_log("[AIAGENT_NSFW] Purged sex event backlog for all scene actors");
        }
        // Clear scene-active marker, dedup hash, and backlog-blocking flag so state is clean
        @unlink(sys_get_temp_dir() . "/nsfw_scene_active.txt");
        @unlink(sys_get_temp_dir() . "/nsfw_scene_last_hash.txt");
        @unlink(sys_get_temp_dir() . "/nsfw_scene_ended.txt");

        // Reset response counters for all scene actors (cooldown system)
        if (function_exists('apcu_delete')) {
            foreach ($sceneActors as $sceneActor) {
                apcu_delete("nsfw_responses_{$sceneActor}");
                apcu_delete("nsfw_cooldown_sex_{$sceneActor}");
                apcu_delete("nsfw_cooldown_climax_{$sceneActor}");
            }
            error_log("[AIAGENT_NSFW] Reset response counters for scene actors");
        }

        // Check if this was a prostitute transaction
        $wasTransaction = !empty($currentIntimacy["is_transaction"]);
        $paymentConfirmed = !empty($currentIntimacy["payment_confirmed"]);
        $serviceCompleted = !empty($currentIntimacy["service_completed"]);

        foreach ($scoringPart as $part) {
            $actorResult = explode("@", $part);
            $scoring[] = $actorResult[0] . " satisfaction score: " . $actorResult[1];
        }

        // Detect if this was an NPC-to-NPC scene (no player involved)
        $isNpcOnlyScene = true;
        foreach ($sceneActors as $checkActor) {
            if (strcasecmp($checkActor, $playerName) === 0 || strcasecmp($checkActor, "Player") === 0) {
                $isNpcOnlyScene = false;
                break;
            }
        }

        if ($isNpcOnlyScene) {
            error_log("[AIAGENT_NSFW] NPC-to-NPC scene ended - setting up pillow talk for both NPCs");
        }

        // Inject pillow talk prompt to NPCs in the scene
        // When arousal gating ON: only NPCs who orgasmed get pillow talk
        // When arousal gating OFF: all NPCs in scene get pillow talk (intimacy status is NULL)
        $arousalGatingOn = isSexDisposalEnabled();

        foreach ($sceneActors as $sceneActor) {
            // Skip player
            if (strcasecmp($sceneActor, $playerName) === 0 || strcasecmp($sceneActor, "Player") === 0) {
                continue;
            }

            $actorIntimacy = getIntimacyForActor($sceneActor);

            // When arousal gating is ON, check if NPC orgasmed
            // When arousal gating is OFF, skip this check - all NPCs get pillow talk
            if ($arousalGatingOn) {
                $actorOrgasmed = !empty($actorIntimacy["orgasmed"]);
                if (!$actorOrgasmed) {
                    error_log("[AIAGENT_NSFW] Skipping pillow talk for $sceneActor - no orgasm occurred (arousal gating ON)");
                    continue;
                }
            }

            $actorWasTransaction = !empty($actorIntimacy["is_transaction"]);
            $actorPaymentConfirmed = !empty($actorIntimacy["payment_confirmed"]);
            $wasNpcScene = !empty($actorIntimacy["is_npc_scene"]);
            $npcScenePartner = $actorIntimacy["npc_scene_partner"] ?? null;

            // Build pillow talk prompt for this actor
            $pillowTalkPrompt = "";

            if ($wasNpcScene && $npcScenePartner) {
                // ============================================
                // NPC-TO-NPC SCENE PILLOW TALK
                // ============================================
                // NPCs talk to each other, not to player
                $partnerAffinity = $actorIntimacy["partner_affinity"] ?? 0;
                $partnerTier = $actorIntimacy["partner_tier"] ?? 'neutral';

                require_once __DIR__ . '/nsfw_data.php';
                $extended = NsfwNpcData::get($sceneActor);

                // Check for custom speak style pillow talk first
                if (!empty($extended['sex_speech_style'])) {
                    $speakStyle = NsfwData::getSpeakStyle($extended['sex_speech_style']);
                    if (!empty($speakStyle['pillow_talk_prompt'])) {
                        // Replace #PARTNER# with actual partner name
                        $pillowTalkPrompt = str_replace('#PARTNER#', $npcScenePartner, $speakStyle['pillow_talk_prompt']);
                    }
                }

                // Fallback to tier-based pillow talk - reuse existing tier system
                // This checks for marriage/affair scenarios: if sceneActor is married
                // and partner != spouse, it's an affair; if partner == spouse, it's marriage
                if (empty($pillowTalkPrompt)) {
                    // Check if NPC is prostitute for tier prompt selection
                    $isProstitute = !empty($extended['is_prostitute']) || !empty($extended['profession_prostitute']);

                    // Build pillow talk using the tier system - handles marriage/affair/regular
                    $tierContext = NsfwRelationship::getTierPromptByAffinity(
                        $partnerAffinity,
                        $isProstitute,
                        $npcScenePartner,  // Partner name for #PARTNER# replacement
                        $sceneActor        // NPC name for marriage/affair detection
                    );

                    // Wrap it in post-scene context
                    $pillowTalkPrompt = "The intimate scene has ended. $tierContext Share a brief post-intimacy response to $npcScenePartner.";
                }

                error_log("[AIAGENT_NSFW] NPC-to-NPC pillow talk for $sceneActor toward $npcScenePartner (tier: $partnerTier, affinity: $partnerAffinity)");

            } else if (isNpcSlave($sceneActor)) {
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
                // Regular NPC pillow talk (with player) - check for speak style pillow_talk_prompt
                // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
                require_once __DIR__ . '/nsfw_data.php';
                $extended = NsfwNpcData::get($sceneActor);
                error_log("[AIAGENT_NSFW] PILLOW DEBUG: $sceneActor extended_data keys: " . implode(', ', array_keys($extended)));
                error_log("[AIAGENT_NSFW] PILLOW DEBUG: sex_speech_style = " . ($extended['sex_speech_style'] ?? 'NOT SET'));

                if (!empty($extended['sex_speech_style'])) {
                    // Use NsfwData::getSpeakStyle which reads from JSONB
                    $speakStyle = NsfwData::getSpeakStyle($extended['sex_speech_style']);
                    error_log("[AIAGENT_NSFW] PILLOW DEBUG: speakStyle keys: " . ($speakStyle ? implode(', ', array_keys($speakStyle)) : 'NULL'));
                    error_log("[AIAGENT_NSFW] PILLOW DEBUG: pillow_talk_prompt = " . ($speakStyle['pillow_talk_prompt'] ?? 'NOT SET'));

                    if (!empty($speakStyle['pillow_talk_prompt'])) {
                        $pillowTalkPrompt = $speakStyle['pillow_talk_prompt'];
                        error_log("[AIAGENT_NSFW] Using speak style pillow talk for $sceneActor (style: {$extended['sex_speech_style']})");
                    } else {
                        error_log("[AIAGENT_NSFW] PILLOW DEBUG: Style '{$extended['sex_speech_style']}' has NO pillow_talk_prompt!");
                    }
                } else {
                    error_log("[AIAGENT_NSFW] PILLOW DEBUG: $sceneActor has NO sex_speech_style set!");
                }

                if (empty($pillowTalkPrompt)) {
                    $pillowTalkPrompt = "The intimate moment has ended. Share a brief, genuine post-intimacy reaction in character.";
                    error_log("[AIAGENT_NSFW] Pillow talk for regular NPC $sceneActor (FALLBACK - no style pillow talk found)");
                } else {
                    error_log("[AIAGENT_NSFW] Pillow talk for regular NPC $sceneActor (USING SPEAK STYLE)");
                }
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
                "sex_started" => false,  // CRITICAL: Stop sex prompts from firing
                "is_naked" => 0,  // Reset clothing state - NPC is dressed after scene
                "scene_phase" => null,
                "tier_prompt_sent" => false,
                "scene_is_idle" => null,
                "scene_start_time" => null,
                "scene_actors" => null,
                "is_transaction" => false,
                "payment_confirmed" => false,
                "service_completed" => false,
                // Clear NPC-to-NPC scene flags
                "is_npc_scene" => false,
                "npc_scene_partner" => null,
                "npc_scene_thread_id" => null,
                "partner_affinity" => null,
                "partner_tier" => null,
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
            "sex_started" => false,  // CRITICAL: Stop sex prompts from firing
            "is_naked" => 0,  // Reset clothing state - NPC is dressed after scene
            "scene_phase" => null,
            "tier_prompt_sent" => false,
            "scene_is_idle" => null,
            "scene_start_time" => null,
            "scene_actors" => null,
            "is_transaction" => false,
            "payment_confirmed" => false,
            "service_completed" => false,
            // Clear NPC-to-NPC scene flags
            "is_npc_scene" => false,
            "npc_scene_partner" => null,
            "npc_scene_thread_id" => null,
            "partner_affinity" => null,
            "partner_tier" => null,
            "pillow_talk_pending" => $pillowTalkPending,
            "pillow_talk_prompt" => $pillowTalkPrompt
        ]);

        // ============================================
        // POST-SCENE PROMPT FOR CURRENT ACTOR
        // ============================================
        // This uses the pillow_talk_prompt that was ALREADY set by the loop above (726-848)
        // The loop handles: arousal gating, orgasm check, speak styles, slave/prostitute/regular NPC
        // We just read back what was stored - ONE code path for pillow talk logic
        // ============================================

        // Re-read current actor's intimacy to get the pillow talk we just stored
        $finalIntimacy = getIntimacyForActor($actor);
        $postScenePrompt = "";

        if (!empty($finalIntimacy["pillow_talk_prompt"])) {
            // Use the pillow talk from the loop (respects arousal gating + orgasm check)
            $postScenePrompt = $finalIntimacy["pillow_talk_prompt"];
            error_log("[AIAGENT_NSFW] Post-scene for $actor: using stored pillow talk");
        } else {
            // No pillow talk was set - either no orgasm (arousal gating ON) or NPC wasn't in loop
            $postScenePrompt = "The scene has ended.";
            error_log("[AIAGENT_NSFW] Post-scene for $actor: no pillow talk (orgasm check failed or not in scene)");
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
        $intimacyStatus["is_naked"] = 1;  // 0=clothed, 1=naked (Tyler's design)
        updateIntimacyForActor($actor, $intimacyStatus);
    }

    /**
     * Handle chatnf_sl_climax - Orgasm event
     */
    private static function handleClimax() {
        global $gameRequest;

        $actor = $GLOBALS["HERIKA_NAME"];
        $intimacyStatus = getIntimacyForActor($actor);

        // Mark this NPC as having orgasmed - used for pillow talk eligibility
        $intimacyStatus["orgasmed"] = true;
        updateIntimacyForActor($actor, $intimacyStatus);
        error_log("[AIAGENT-NSFW] Marked $actor as orgasmed (chatnf_sl_climax)");

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
            // Inject climax_prompt into speech style so it goes directly to NPC (bypasses Narrator path)
            require_once(__DIR__."/nsfw_data.php");
            $extended = NsfwNpcData::get($actor);
            if (!empty($extended["sex_speech_style"])) {
                $speakStyle = NsfwData::getSpeakStyle($extended["sex_speech_style"]);
                if (!empty($speakStyle['climax_prompt'])) {
                    $climaxPrompt = str_replace('#PARTNER#', $GLOBALS["PLAYER_NAME"] ?? 'your partner', $speakStyle['climax_prompt']);
                    $GLOBALS["HERIKA_SPEECHSTYLE"] = ($GLOBALS["HERIKA_SPEECHSTYLE"] ?? '') . "\n#Climax Behavior\n" . $climaxPrompt;
                    $GLOBALS["AIAGENTNSFW_CLIMAX_PROMPT"] = $speakStyle['climax_prompt'];
                    error_log("[AIAGENT-NSFW] Injected climax_prompt for $actor: " . substr($climaxPrompt, 0, 50) . "...");
                }
            }
            error_log("[AIAGENT-NSFW] Climax from llm_request should happen");
        }
    }

    /**
     * Handle chatnf_sl_moan - Moan event during scene
     */
    private static function handleMoan() {
        // Safety net: if the scene has ended (flag written by preprocessing when chatnf_sl_end
        // arrived), don't generate moan audio. prerequest.php's pillow_talk_pending system
        // handles the NPC's post-scene response for this request instead.
        $sceneEndedRaw = @file_get_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt");
        if ($sceneEndedRaw !== false) {
            $sceneEndedTime  = (int)$sceneEndedRaw;
            $sceneActiveTime = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt") ?: 0);
            if ($sceneEndedTime > $sceneActiveTime && (time() - $sceneEndedTime) < 60) {
                return; // Scene ended — prerequest.php pillow_talk_pending handles the response
            }
        }

        // Gate moans behind the UI toggle AND relationship affinity threshold
        $moansEnabled = !empty($GLOBALS["AIAGENTNSFW_ENABLE_RANDOM_MOANS"]);

        if ($moansEnabled) {
            $randomMoans = $GLOBALS["AIAGENTNSFW_RANDOM_MOANS_LIST"] ?? [" ... oh ... ", " ... ah ... ", " ... mmm ... "];
            $moan = $randomMoans[array_rand($randomMoans)];
            returnLines([$moan]);
        } else {
            error_log("[AIAGENTNSFW] Moan suppressed - toggle off or affinity too low");
            returnLines([""]);
        }

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

        // Data format: "(Context location: X)PlayerName:OrgasmerName/SceneId/Index/Partner"
        // OR for player orgasm: "(Context)PlayerName:PLAYER_ORGASM/SceneId/Index/PlayerName"
        // Need to extract the orgasmer name from after the colon
        $rawData = $gameRequest[3];

        // First split by "/" to get the main parts
        $parts = explode("/", $rawData);
        $firstPart = trim($parts[0] ?? ''); // "(Context)PlayerName:OrgasmerName" or "PLAYER_ORGASM"
        $sceneId = trim($parts[1] ?? '');
        $orgasmerIndex = intval($parts[2] ?? 0);
        $partnerName = trim($parts[3] ?? '');

        // Check for PLAYER_ORGASM prefix (sent when player orgasms)
        $isPlayerOrgasmPrefix = (strpos($rawData, 'PLAYER_ORGASM') !== false);

        // Extract orgasmer name - it's after the colon in the first part
        $orgasmerName = '';
        if (strpos($firstPart, ':') !== false) {
            $colonParts = explode(':', $firstPart);
            $orgasmerName = trim(end($colonParts)); // Get the last part after colon
        } else {
            $orgasmerName = $firstPart;
        }

        error_log("[AIAGENT-NSFW] Parsed orgasm - orgasmer: '$orgasmerName', scene: '$sceneId', partner: '$partnerName', playerOrgasmPrefix: " . ($isPlayerOrgasmPrefix ? 'YES' : 'NO'));

        $actor = $GLOBALS["HERIKA_NAME"];
        $intimacyStatus = getIntimacyForActor($actor);
        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";

        // Check if this is the PLAYER having an orgasm
        // Either by PLAYER_ORGASM prefix (new method) or by matching player name (old method)
        $isPlayerOrgasm = $isPlayerOrgasmPrefix || (strcasecmp($orgasmerName, $playerName) === 0 || strcasecmp($orgasmerName, "Player") === 0);

        error_log("[AIAGENT-NSFW] Orgasm type: " . ($isPlayerOrgasm ? "PLAYER orgasm (NPC $actor should REACT)" : "NPC $actor orgasm (express their climax)"));

        // COOLDOWN: If player orgasmed recently, suppress NPC's own orgasm event
        // This prevents the NPC from getting their own climax_prompt right after reacting to player's orgasm
        $playerOrgasmCooldown = 5; // seconds
        if (!$isPlayerOrgasm) {
            // Check if player orgasmed recently
            $lastPlayerOrgasm = $intimacyStatus['last_player_orgasm_time'] ?? 0;
            $timeSincePlayerOrgasm = time() - $lastPlayerOrgasm;
            if ($lastPlayerOrgasm > 0 && $timeSincePlayerOrgasm < $playerOrgasmCooldown) {
                error_log("[AIAGENT-NSFW] SUPPRESSING NPC orgasm for $actor - player orgasmed {$timeSincePlayerOrgasm}s ago (cooldown: {$playerOrgasmCooldown}s)");
                // Still log the event but don't generate a response
                $GLOBALS["gameRequest"][0] = "infoaction";
                $GLOBALS["gameRequest"][3] = "$actor reacts to $playerName's orgasm";
                logEvent($GLOBALS["gameRequest"]);
                terminate();
                return;
            }
        } else {
            // Player is orgasming - record the timestamp
            $intimacyStatus['last_player_orgasm_time'] = time();
            updateIntimacyForActor($actor, $intimacyStatus);
            error_log("[AIAGENT-NSFW] Recorded player orgasm time for cooldown tracking");
        }

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
                // Regular NPC - inject partner climax reaction prompt from their speak style if available
                // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
                $extended = NsfwNpcData::get($actor);
                $partnerReactionPrompt = '';

                error_log("[AIAGENT-NSFW] DEBUG: Looking up partner_climax for $actor, sex_speech_style=" . ($extended['sex_speech_style'] ?? 'NOT SET'));

                // Try to get partner_climax_prompt from NPC's speak style
                if (!empty($extended['sex_speech_style'])) {
                    $speakStyle = NsfwData::getSpeakStyle($extended['sex_speech_style']);
                    error_log("[AIAGENT-NSFW] DEBUG: Got speak style, partner_climax_prompt=" . ($speakStyle['partner_climax_prompt'] ?? 'NOT SET'));
                    if (!empty($speakStyle['partner_climax_prompt'])) {
                        $partnerReactionPrompt = $speakStyle['partner_climax_prompt'];
                        $partnerReactionPrompt = str_replace('#PARTNER#', $orgasmerName, $partnerReactionPrompt);
                        error_log("[AIAGENT-NSFW] Using speak style partner_climax_prompt for $actor: " . substr($partnerReactionPrompt, 0, 80));
                    }
                }

                // Only inject if speak style has partner_climax_prompt - no hardcoded fallback
                if (!empty($partnerReactionPrompt)) {
                    $GLOBALS["HERIKA_PERSONALITY"] .= "\n<partner_orgasm>\n{$partnerReactionPrompt}\n</partner_orgasm>";
                    error_log("[AIAGENT-NSFW] Injected partner climax reaction for $actor (partner: $orgasmerName is cumming)");
                    error_log("[AIAGENT-NSFW] Partner climax prompt content: " . substr($partnerReactionPrompt, 0, 100));
                } else {
                    error_log("[AIAGENT-NSFW] No partner_climax_prompt for $actor - skipping injection");
                }
            }
        }

        // Track if THIS NPC is actually orgasming (used later to decide if we inject full speech style)
        $npcIsActuallyOrgasming = false;

        // If NPC is orgasming, build the context message
        // IMPORTANT: Only inject orgasm prompt if THIS NPC ($actor) is the one orgasming
        // Otherwise inject a "react to partner's orgasm" prompt
        if (!$isPlayerOrgasm) {
            $isThisNpcOrgasming = (strcasecmp($actor, $orgasmerName) === 0);
            $npcIsActuallyOrgasming = $isThisNpcOrgasming;

            if ($isThisNpcOrgasming) {
                // THIS NPC is orgasming - mark them as orgasmed for pillow talk eligibility
                $intimacyStatus["orgasmed"] = true;
                updateIntimacyForActor($actor, $intimacyStatus);
                error_log("[AIAGENT-NSFW] Marked $actor as orgasmed (ext_nsfw_orgasm)");

                // THIS NPC is orgasming - inject their climax prompt
                // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
                $extended = NsfwNpcData::get($orgasmerName);
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

                // Fall back to global prompt from UI if no speak style climax_prompt
                if (empty($npcOrgasmPrompt)) {
                    $npcOrgasmPrompt = getGlobalPrompt('npc_orgasm_prompt');
                    if (!empty($npcOrgasmPrompt)) {
                        $npcOrgasmPrompt = str_replace('#NPC#', $orgasmerName, $npcOrgasmPrompt);
                        $npcOrgasmPrompt = str_replace('#NPC_NAME#', $orgasmerName, $npcOrgasmPrompt);
                    }
                    // No hardcoded fallback - if nothing in UI, $npcOrgasmPrompt stays empty
                }

                // Only prepend prompt if we have one
                if (!empty($npcOrgasmPrompt)) {
                    $GLOBALS["gameRequest"][3] = $npcOrgasmPrompt . " " . $gameRequest[3];
                    error_log("[AIAGENT-NSFW] NPC orgasm message for $actor: {$GLOBALS["gameRequest"][3]}");
                } else {
                    error_log("[AIAGENT-NSFW] No climax_prompt for $actor - using raw event data only");
                }
            } else {
                // This NPC is NOT the one orgasming - they should REACT to their partner's orgasm
                // Use their speak style's partner_climax_prompt if available
                // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
                $extendedReact = NsfwNpcData::get($actor);
                $partnerReactionPromptNpc = '';

                if (!empty($extendedReact['sex_speech_style'])) {
                    $speakStyleReact = NsfwData::getSpeakStyle($extendedReact['sex_speech_style']);
                    if (!empty($speakStyleReact['partner_climax_prompt'])) {
                        $partnerReactionPromptNpc = $speakStyleReact['partner_climax_prompt'];
                        $partnerReactionPromptNpc = str_replace('#PARTNER#', $orgasmerName, $partnerReactionPromptNpc);
                        error_log("[AIAGENT-NSFW] Using speak style partner_climax_prompt for $actor (reacting to NPC partner)");
                    }
                }

                // Only inject if speak style has partner_climax_prompt - no hardcoded fallback
                if (!empty($partnerReactionPromptNpc)) {
                    $GLOBALS["HERIKA_PERSONALITY"] .= "\n<partner_orgasm>\n{$partnerReactionPromptNpc}\n</partner_orgasm>";
                    error_log("[AIAGENT-NSFW] Injected partner climax reaction for $actor (partner NPC: $orgasmerName is orgasming, NOT $actor)");
                } else {
                    error_log("[AIAGENT-NSFW] No partner_climax_prompt for $actor - skipping partner reaction injection");
                }
            }
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

            // Only inject climax prompt if THIS NPC is actually orgasming
            // Speech style was already injected when scene started - don't re-inject on every orgasm
            if ($npcIsActuallyOrgasming) {
                // Get climax_prompt directly from NPC's speak style - don't call setSexSpeechStyle
                // which would re-inject the full style (already done in prerequest.php)
                $climaxCue = "";
                $extendedClimax = NsfwNpcData::get($actor);
                if (!empty($extendedClimax['sex_speech_style'])) {
                    $speakStyleClimax = NsfwData::getSpeakStyle($extendedClimax['sex_speech_style']);
                    if (!empty($speakStyleClimax['climax_prompt'])) {
                        $climaxCue = $speakStyleClimax['climax_prompt'];
                    }
                }

                if (!empty($climaxCue)) {
                    $climaxCue = str_replace('#NPC_NAME#', $actor, $climaxCue);
                    $GLOBALS["PROMPTS"]["ext_nsfw_orgasm"]["cue"] = ["<climax_instruction>\n{$climaxCue}\n</climax_instruction> " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")];
                    error_log("[AIAGENT-NSFW] Applied climax_prompt for $actor: " . substr($climaxCue, 0, 80));
                }
            } else {
                // CRITICAL: Override the default "cue" prompt to tell NPC to REACT to partner's orgasm
                // NOT to express their own climax!
                // Use NPC's speak style partner_climax_prompt if available
                $partnerOrgasmCue = "";
                // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
                $extendedCue = NsfwNpcData::get($actor);

                if (!empty($extendedCue['sex_speech_style'])) {
                    $speakStyleCue = NsfwData::getSpeakStyle($extendedCue['sex_speech_style']);
                    if (!empty($speakStyleCue['partner_climax_prompt'])) {
                        $partnerOrgasmCue = "(" . $speakStyleCue['partner_climax_prompt'] . ")";
                        $partnerOrgasmCue = str_replace('#PARTNER#', $orgasmerName, $partnerOrgasmCue);
                        error_log("[AIAGENT-NSFW] Using speak style partner_climax_prompt as cue for $actor: " . substr($partnerOrgasmCue, 0, 80));
                    }
                }

                // Only override cue if we have a speak style partner_climax_prompt
                // NO hardcoded fallback - if NPC has no profile, don't override their cue
                if (!empty($partnerOrgasmCue)) {
                    $GLOBALS["PROMPTS"]["ext_nsfw_orgasm"]["cue"] = [$partnerOrgasmCue . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")];
                    error_log("[AIAGENT-NSFW] Overrode cue with speak style partner_climax_prompt - $actor reacting to partner's orgasm");
                } else {
                    error_log("[AIAGENT-NSFW] No partner_climax_prompt for $actor - using existing cue from prerequest.php");
                }
            }
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
        // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
        $extended = NsfwNpcData::get($actorName);

        // Check if NPC has a custom speech style configured
        $hasProfile = isset($extended["sex_speech_style"]) && !empty($extended["sex_speech_style"]) && $extended["sex_speech_style"] !== 'auto';
        $styleContent = '';
        $speakStyle = null;

        if ($hasProfile) {
            // NPC HAS PROFILE - use their custom speak style
            $styleName = $extended["sex_speech_style"];
            error_log("[AIAGENTNSFW] NPC $actorName HAS PROFILE - using custom speech style: $styleName");

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
        } else {
            // NPC has NO PROFILE - use default from UI prompts page (NPC backups)
            $styleContent = getGlobalPrompt('default_speech_style');
            if (!empty($styleContent)) {
                error_log("[AIAGENTNSFW] NPC $actorName has NO PROFILE - using default_speech_style");
            }
        }

        // Inject the speech style (either custom or default)
        if (!empty($styleContent)) {
            $speakStyleTemplate = getGlobalPrompt('speak_style_template');
            if (empty($speakStyleTemplate)) {
                $speakStyleTemplate = "#Sex Expressions\n#SPEAK_STYLE#";
            }
            $speakStyleOutput = str_replace('#SPEAK_STYLE#', $styleContent, $speakStyleTemplate);
            $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n" . $speakStyleOutput;

            // Only inject phase prompts if NPC has a profile (speakStyle object exists)
            if ($speakStyle) {
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

            // Load kink templates from database (UI prompts)
            $prompts = NsfwData::getBlob(NsfwData::KEY_PROMPTS);

            // Inject normal kinks if threshold met
            if ($hasNormalKinks && $affinity >= $normalKinksThreshold) {
                $kinksList = implode(", ", $extended["nsfw_kinks"]);
                $normalKinksTemplate = $prompts['normal_kinks_template'] ?? '';
                if (empty($normalKinksTemplate)) {
                    error_log("[AIAGENTNSFW] No prompt for 'normal_kinks_template' - save in NSFW Config UI Prompts tab");
                } else {
                    $kinksOutput = str_replace('#KINKS#', $kinksList, $normalKinksTemplate);
                    $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n" . $kinksOutput;
                    error_log("[AIAGENTNSFW] Normal kinks unlocked for $actorName (affinity: $affinity >= $normalKinksThreshold)");
                }
            } else if ($hasNormalKinks) {
                error_log("[AIAGENTNSFW] Normal kinks gated for $actorName (affinity: $affinity < $normalKinksThreshold)");
            }

            // Inject secret kinks if threshold met
            if ($hasSecretKinks && $affinity >= $secretKinksThreshold) {
                $secretKinksList = implode(", ", $extended["nsfw_secret_kinks"]);
                $secretKinksTemplate = $prompts['secret_kinks_template'] ?? '';
                if (empty($secretKinksTemplate)) {
                    error_log("[AIAGENTNSFW] No prompt for 'secret_kinks_template' - save in NSFW Config UI Prompts tab");
                } else {
                    $secretKinksOutput = str_replace('#SECRET_KINKS#', $secretKinksList, $secretKinksTemplate);
                    $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n" . $secretKinksOutput;
                    error_log("[AIAGENTNSFW] Secret kinks unlocked for $actorName (affinity: $affinity >= $secretKinksThreshold)");
                }
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
        // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
        $extended = NsfwNpcData::get($actorName);

        // UI saves to sex_prompt in JSONB - if NPC has profile, use it
        // If no profile, fall back to default_sex_personality from prompts page
        $sexPrompt = $extended["sex_prompt"] ?? null;
        if (empty($sexPrompt)) {
            // NPC has no profile - use default from UI prompts page
            $sexPrompt = getGlobalPrompt('default_sex_personality');
            if (!empty($sexPrompt)) {
                error_log("[AIAGENTNSFW] NPC $actorName has NO PROFILE - using default_sex_personality");
            }
        } else {
            error_log("[AIAGENTNSFW] NPC $actorName HAS PROFILE - using custom sex_prompt");
        }

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
                error_log("[AIAGENTNSFW] Injected tier relationship context for $actorName: " . $sceneContext);
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

            // Use token limit from UI settings, not hardcoded
            $climaxTokenLimit = _getNsfwSetting('TOKEN_LIMIT_CLIMAX', 100);
            $GLOBALS["FORCE_MAX_TOKENS"] = $climaxTokenLimit;
            $buffer = $connectionHandler->fast_request($contextData, ["max_tokens" => $climaxTokenLimit], "aiagent_nsfw");

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

                if (function_exists('xtts_fastapi_settings')) {
                    xtts_fastapi_settings(["temperature" => 1, "speed" => 0.6, "enable_text_splitting" => false, "top_p" => 1, "top_k" => 100], true);
                }
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
