<?php

// This is called when NPC context is built, before funrect handling
require_once __DIR__ . "/scene_threads.php";
require_once __DIR__ . "/contact_state.php";

if (isset($GLOBALS["contextDataFull"]) && is_array($GLOBALS["contextDataFull"])) {
    aiagentNsfwSceneThreadCleanContextArray($GLOBALS["contextDataFull"], "content");
    aiagentNsfwContactCleanContextArray($GLOBALS["contextDataFull"], "content");
}

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
        // Ensure the background worker daemon is up; it drains the queue outside the semaphore
        _nsfwEnsureProfileWorkerRunning();
        $queueResult = _nsfwProcessQueue(2); // Process up to 2 per request
        if (($queueResult['processed'] ?? 0) > 0) {
            error_log("[AIAGENTNSFW] Queue: Processed {$queueResult['processed']} profiles");
        }
        if (($queueResult['abandoned'] ?? 0) > 0) {
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

    // Pillow talk fires on the PLAYER's orgasm (the scene's payoff) OR the NPC's own orgasm. A prostitute's
    // service concludes on the CLIENT finishing - she may not have climaxed herself - so do NOT require HER
    // orgasm. If only the player came, the aftercare is about HIS finish (her UI post-service prompt covers it).
    $actorOrgasmed = !empty($currentIntimacy["orgasmed"]);
    $playerOrgasmedThisScene = !empty($currentIntimacy["last_player_orgasm_time"])
        && (int)$currentIntimacy["last_player_orgasm_time"] >= (int)($currentIntimacy["scene_start_time"] ?? 0);
    if (!$actorOrgasmed && !$playerOrgasmedThisScene) {
        error_log("[AIAGENTNSFW] Skipping pillow talk for $actor - no orgasm (player or NPC) this scene");
    } else if (isNpcSlave($actor)) {
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
        // PROSTITUTE POST-SERVICE TALK (prostitution's own flavor of pillow talk).
        // Prefer her per-NPC "Post-Service / Pillow Talk" (after_prompt) over the global default.
        // A completed paid service counts as paid even though the client's orgasm already CONSUMED
        // payment_confirmed - so key the paid farewell off service_completed too.
        $npcAfter = function_exists('aiagentNsfwGetProstituteScenePrompt')
            ? aiagentNsfwGetProstituteScenePrompt($actor, 'after_prompt') : '';
        $wasPaidService = $paymentConfirmed || $serviceCompleted;
        if ($wasPaidService) {
            $postScenePrompt = ($npcAfter !== '') ? $npcAfter
                : (getGlobalPrompt('prostitute_post_service_paid') ?: "The service session has ended. You were paid. Give a brief professional farewell.");
        } else {
            $postScenePrompt = getGlobalPrompt('prostitute_post_service_unpaid') ?: "The service session ended but payment was NOT confirmed. You may remind the client about payment.";
        }
        error_log("[AIAGENTNSFW] Post-service pillow talk for prostitute $actor (paidService: " . ($wasPaidService ? 'yes' : 'no') . ", per-NPC after_prompt: " . ($npcAfter !== '' ? 'yes' : 'no') . ")");
    } else {
        // REGULAR NPC PILLOW TALK - pull from the NPC's OWN speak style 'pillow_talk_prompt' (UI-editable in
        // the Speak Styles editor), NOT a hardcoded line. Falls back to a generic default only if she has no
        // speak style configured or that style has no pillow-talk line.
        $regExtended = NsfwNpcData::get($actor);
        $regStyleName = $regExtended['sex_speech_style'] ?? '';
        $regPillow = '';
        if ($regStyleName !== '' && $regStyleName !== 'auto' && class_exists('NsfwData')) {
            $regStyleData = NsfwData::getSpeakStyle($regStyleName) ?: [];
            $regPillow = trim((string)($regStyleData['pillow_talk_prompt'] ?? ''));
            if ($regPillow !== '' && function_exists('aiagentNsfwResolveSpeakStylePlaceholders')) {
                $regPillow = aiagentNsfwResolveSpeakStylePlaceholders($regPillow, $actor, $currentIntimacy);
            }
        }
        $postScenePrompt = ($regPillow !== '') ? $regPillow : "The intimate moment has ended. Share a brief, genuine post-intimacy reaction in character.";
        error_log("[AIAGENTNSFW] Pillow talk for regular NPC $actor (speak-style pillow_talk_prompt: " . ($regPillow !== '' ? 'yes' : 'fallback') . ")");
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
// Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
require_once __DIR__ . "/nsfw_data.php";
$extended_data = NsfwNpcData::get($actorName);

$drunkStage = getDrunkStageForActor($actorName); // 0-10 from Drink/Consume/Toast alcohol actions in a game-time window
// An LLM-emitted drunk/tipsy mood acts as a floor (declared/manual drunk without tracked drinks)
$_lastMood = getLastIssuedMood($actorName, ($GLOBALS["gameRequest"][2] ?? time()));
if ($_lastMood === "drunk" && $drunkStage < 4) { $drunkStage = 4; }
elseif ($_lastMood === "tipsy" && $drunkStage < 2) { $drunkStage = 2; }

if ($drunkStage >= 1) {
    error_log("[AIAGENTNSFW] Drunk stage {$drunkStage} for {$actorName}");
    // The drunk-state PROMPT TEXT (stage prompt, intoxicated_sex, on-floor) is injected in context_pre.php,
    // into the <character> block BEFORE the system prompt is built, so she stays drunk every turn.
    // Here we only drive mood, the TTS slur, and the body animation.
    $_inSexScene = in_array($GLOBALS["gameRequest"][0] ?? '', ["chatnf_sl","chatnf_sl_moan","chatnf_sl_climax","chatnf_sl_end","ext_nsfw_action","ext_nsfw_sexcene","ext_nsfw_orgasm","ext_nsfw_npc_orgasm"]);
    // Also count an active OStim/SexLab scene on NORMAL chat turns (the list above only catches scene-event
    // turns), so the stumble/fall never fires mid-scene. Same marker the physics/rechat guards use.
    $_sceneActiveTs = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt") ?: 0);
    $_sceneEndedTs  = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt") ?: 0);
    // PER-NPC: the global scene marker must only suppress THIS NPC if SHE is the one in the active sex scene
    // (level>=1). Without this, a scene with one NPC wrongly froze every other drunk/drugged NPC nearby for 600s.
    if ($_sceneActiveTs > 0 && (time() - $_sceneActiveTs) < 600 && $_sceneActiveTs >= $_sceneEndedTs
        && function_exists('getIntimacyForActor') && (int)(getIntimacyForActor($actorName)["level"] ?? 0) >= 1) { $_inSexScene = true; }
    // Mood: tipsy at 3-5, drunk at 6+ (levels 1-2 stay essentially sober, no forced mood).
    // #4: don't overwrite the scene mood (sexy/afraid set by prerequest) mid-scene; the TTS slur still conveys it.
    if (!$_inSexScene) {
        if ($drunkStage >= 6)     { $GLOBALS["FORCE_MOOD"] = "drunk"; $GLOBALS["EMOTEMOODS"] = "drunk"; }
        elseif ($drunkStage >= 3) { $GLOBALS["FORCE_MOOD"] = "tipsy"; $GLOBALS["EMOTEMOODS"] = "tipsy"; }
    }
    // Escalating TTS slur: stage 2 gets a slight audible tell, then heavier steps for 3-10.
    $tempoByStage = [2=>'atempo=0.97',3=>'atempo=0.94',4=>'atempo=0.88',5=>'atempo=0.82',6=>'atempo=0.76',7=>'atempo=0.70',8=>'atempo=0.64',9=>'atempo=0.58',10=>'atempo=0.52'];
    if (isset($tempoByStage[$drunkStage])) { $GLOBALS["TTS_FFMPEG_FILTERS"]["tempo"] = $tempoByStage[$drunkStage]; }
    else { unset($GLOBALS["TTS_FFMPEG_FILTERS"]["tempo"]); }

    $extended_data["aiagent_nsfw_last_time_drunk"] = ($GLOBALS["gameRequest"][2] ?? time());
} else {
    if (isset($extended_data["aiagent_nsfw_last_time_drunk"])) {
        unset($extended_data["aiagent_nsfw_last_time_drunk"]);
        $GLOBALS["FORCE_MOOD"]="sober";
        $GLOBALS["EMOTEMOODS"]="sober"; // Can be overwriten by LLM
    }
}

// Continuous drunk locomotion = OAR "Drunk animations" mod, gated on a drunk-level actor value.
// Server starts Variable10 at stage 5, where the persistent sway/idle should begin. Stages 1-4
// are prompt/voice only. Stage value still leaves room for per-stage OAR conditions later.
// State-tracked so we only ForceActorValue on a change; reset to 0 when sober or mid-scene.
$_drunkAvChangedThisRequest = false;
if (_getNsfwSetting("DRUNK_ANIMATIONS", true)) {
    $avTarget  = ($drunkStage >= 5 && empty($_inSexScene)) ? $drunkStage : 0;
    $avCurrent = intval($extended_data["aiagent_nsfw_drunk_av"] ?? 0);
    if ($avTarget !== $avCurrent) {
        if (!class_exists('SkyrimCommandBuilder')) { require_once __DIR__ . "/../../lib/scriptproxy_papyrus.php"; }
        if (!class_exists('Npcmaster')) { require_once __DIR__ . "/../../lib/core/npc_master.class.php"; }
        $refidF = (new Npcmaster())->getByName($actorName)["refid"] ?? null;
        if (!empty($refidF)) {
            $cmdF = new SkyrimCommandBuilder();
            $cmdF->send($cmdF->Actor->ForceActorValue("0x{$refidF}", "Variable10", (float)$avTarget));
            if ($avTarget > 0) { $extended_data["aiagent_nsfw_drunk_av"] = $avTarget; }
            else { unset($extended_data["aiagent_nsfw_drunk_av"]); }
            $_drunkAvChangedThisRequest = true;
            error_log("[AIAGENTNSFW] Drunk OAR AV for {$actorName}: {$avCurrent} -> {$avTarget}");
        }
    }
}

// Body animation: PushActorAway ragdolls her for stumbles/falls (the havok-impulse fall never dropped her).
// At the blackout level she's dropped FLAT and held down with the Paralysis actor value (NKO's mechanism) -
// SetUnconscious only flagged her and left her standing. The OAR pack handles the continuous sway (5+).
// Skipped during a scene. Runs at level 6+ OR while she's still flagged passed out (so we can stand her up).
$wasUnconscious = !empty($extended_data["aiagent_nsfw_unconscious"]);
if (($drunkStage >= 6 || $wasUnconscious) && empty($_inSexScene) && _getNsfwSetting("DRUNK_ANIMATIONS", true)) {
    if (!class_exists('SkyrimCommandBuilder')) { require_once __DIR__ . "/../../lib/scriptproxy_papyrus.php"; }
    if (!class_exists('Npcmaster')) { require_once __DIR__ . "/../../lib/core/npc_master.class.php"; }
    $refidP = (new Npcmaster())->getByName($actorName)["refid"] ?? null;
    if (!empty($refidP)) {
        $refidHexP = "0x{$refidP}";
        $cmdP = new SkyrimCommandBuilder();
        if ($wasUnconscious) {
            // She's passed out on the floor (Paralysis). She STAYS down and talks from the ground every turn -
            // we only stand her back up once she's sobered below the blackout level (game-time wears it off).
            // Clear Paralysis + StopCombat + EvaluatePackage so she gets up cleanly and never wakes hostile.
            if ($drunkStage < 10) {
                aiagentNsfwQueueDrunkRouse($actorName, $refidHexP);
                unset($extended_data["aiagent_nsfw_unconscious"]);
                unset($extended_data["aiagent_nsfw_drunk_rouse_last_retry_localts"]);
                unset($extended_data["aiagent_nsfw_drunk_rouse_retry_count"]);
            }
        } elseif ($drunkStage >= 6) {
            if ($_drunkAvChangedThisRequest) {
                error_log("[AIAGENTNSFW] Skipping drunk physics for {$actorName} this turn; OAR drunk AV just changed");
            } else {
            // Every physical reaction (stumble, crumble-fall, level-10 pass-out) waits for this cooldown so it's
            // tied to actual alcohol drinking actions, including toast idles.
            $_fallCooldown = (float)_getNsfwSetting("DRUNK_FALL_AFTER_DRINK_SECONDS", 8);
            $_actorE2 = $GLOBALS["db"]->escape($actorName);
            $_hardDrugRegex2 = $GLOBALS["db"]->escape(function_exists('aiagentNsfwHardDrugRegex') ? aiagentNsfwHardDrugRegex() : 'skooma|skuma|scuma|schuma|sleeping[ -]?tree[ -]?sap|tree sap');
            $_lastDrink2 = $GLOBALS["db"]->fetchOne(
                "SELECT max(localts) AS lt FROM public.actions_issued
                 WHERE actorname = '{$_actorE2}'
                   AND action ~* '^(Consume|Drink|Toast)$'
                   AND fullcall !~* '{$_hardDrugRegex2}'"
            );
            $_recentlyDrank2 = !empty($_lastDrink2['lt']) && (time() - (int)$_lastDrink2['lt']) < $_fallCooldown;
            if ($drunkStage >= 10) {
                if (!$_recentlyDrank2) {
                    // Blackout: drop her FLAT with the Paralysis actor value (NKO's mechanism) - it ragdolls her
                    // limp and HOLDS her on the floor until cleared, unlike SetUnconscious alone which left her
                    // standing. The tiny self-push triggers the ragdoll now. SetUnconscious(true) rides ON TOP of
                    // the paralysis only to CLOSE her eyes (paralysis alone leaves them open) and keep her out of
                    // combat - paralysis stays the dominant down-force so she still ends up flat, not standing.
                    // She can still talk from the ground. SetNotShowOnStealthMeter + self-push + StopCombat = never
                    // a crime, never hostile.
                    $cmdP->send($cmdP->Actor->ModActorValue($refidHexP, "Paralysis", 1));
                    $cmdP->send($cmdP->ObjectReference->PushActorAway($refidHexP, $refidHexP, 1));
                    $cmdP->send($cmdP->Actor->SetNotShowOnStealthMeter($refidHexP, true));
                    $cmdP->send($cmdP->Actor->StopCombat($refidHexP));
                    $cmdP->send($cmdP->Actor->SetUnconscious($refidHexP, true)); // closes her eyes; paralysis keeps her flat
                    $extended_data["aiagent_nsfw_unconscious"] = 1;
                }
            } elseif (!$_recentlyDrank2) {
                // She physically reacts at most once per cooldown, so it reads as an OCCASIONAL drunken wobble -
                // NOT a reaction to every line you speak. Stage 6-9 body reactions are queued through SHARMAT
                // Papyrus so the final decision can use the actor's live animation speed. That prevents the
                // weird standing-still pop. Stage 10 blackout above can still collapse in place.
                // Stumbles climb with the stage, falls climb harder. All behind the drink cooldown so a drink/toast
                // animation is never cut short. Chances + force + cooldown are tunable settings (defaults below).
                $_physCooldown = (float)_getNsfwSetting("DRUNK_STUMBLE_COOLDOWN_SECONDS", 30);
                $_lastPhysTs   = (int)($extended_data["aiagent_nsfw_last_phys_ts"] ?? 0);
                if ((time() - $_lastPhysTs) >= $_physCooldown) {
                    $stumbleForce  = (float)_getNsfwSetting("DRUNK_STUMBLE_FORCE", 6.0); // tune in-game: up if she barely moves, down if she ragdolls
                    if ($drunkStage >= 9 && function_exists('aiagentNsfwQueueStage9DrunkPhysics')) {
                        $movingFallChance = (int)_getNsfwSetting("DRUNK_FALL_CHANCE_S9", 60);
                        $standingFallChance = (int)_getNsfwSetting("DRUNK_STANDING_FALL_CHANCE_S9", 20);
                        $stumbleChance = (int)_getNsfwSetting("DRUNK_STUMBLE_CHANCE_S9", 50);
                        if (aiagentNsfwQueueStage9DrunkPhysics($actorName, $stumbleForce, $movingFallChance, $standingFallChance, $stumbleChance)) {
                            $extended_data["aiagent_nsfw_last_phys_ts"] = time();
                        }
                    } else {
                        $fallDefaults = [6 => 5, 7 => 15, 8 => 35];
                        $fallChance = (int)_getNsfwSetting("DRUNK_FALL_CHANCE_S{$drunkStage}", $fallDefaults[$drunkStage] ?? 0);
                        $stumbleChance = (int)_getNsfwSetting("DRUNK_STUMBLE_CHANCE_S{$drunkStage}", ($drunkStage == 8) ? 35 : (($drunkStage == 7) ? 25 : 15));
                        $_reaction = '';
                        if ($fallChance > 0 && mt_rand(1, 100) <= $fallChance) {
                            $_reaction = 'fall';
                        } elseif (mt_rand(1, 100) <= $stumbleChance) {
                            $_reaction = 'stumble';
                        }
                        if ($_reaction !== '') {
                            $_requireMove = ($_reaction === 'fall') ? (_getNsfwSetting("DRUNK_REQUIRE_MOVEMENT_FOR_STUMBLE", true) !== false) : false;
                            if (function_exists('aiagentNsfwQueueDrunkPhysics') && aiagentNsfwQueueDrunkPhysics($actorName, $_reaction, $drunkStage, $stumbleForce, $_requireMove)) {
                                $extended_data["aiagent_nsfw_last_phys_ts"] = time();
                            }
                        }
                    }
                }
            }
            }
        }
    }
}

// ============================================
// DRUGS effects: skooma (3-level stimulant) + sleeping tree sap (1-hit). Drug-appropriate vs alcohol -
// skooma SPEEDS the voice up + drives a skooma-level OAR actor value (for the strut/dance; the male/female
// split lives in the OAR conditions) + a small movement SpeedMult; sleeping tree sap SLOWS the voice ~30%.
// Additive + isolated from the drunk block: its OWN OAR variable + state keys, skipped mid-scene. The crash/
// paralysis (skooma L3, sap) is added in a later pass.
// ============================================
if (function_exists('getDrugStageForActor') && _getNsfwSetting("DRUGS_ENABLED", true)) {
    $skoomaLevel = isset($GLOBALS["AIAGENTNSFW_SKOOMA_LEVEL"]) ? (int)$GLOBALS["AIAGENTNSFW_SKOOMA_LEVEL"] : getDrugStageForActor($actorName, 'skooma');
    $sapLevel    = isset($GLOBALS["AIAGENTNSFW_SAP_LEVEL"])    ? (int)$GLOBALS["AIAGENTNSFW_SAP_LEVEL"]    : getDrugStageForActor($actorName, 'sleeping_tree_sap');

    if (!isset($_inSexScene)) {
        $_inSexScene = in_array($GLOBALS["gameRequest"][0] ?? '', ["chatnf_sl","chatnf_sl_moan","chatnf_sl_climax","chatnf_sl_end","ext_nsfw_action","ext_nsfw_sexcene","ext_nsfw_orgasm","ext_nsfw_npc_orgasm"]);
        $_dActiveTs = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt") ?: 0);
        $_dEndedTs  = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt") ?: 0);
        // PER-NPC (see drunk block above): only suppress if THIS NPC is the active-scene participant (level>=1),
        // so another NPC's sex scene doesn't freeze this drugged NPC's strut/idles for 600s.
        if ($_dActiveTs > 0 && (time() - $_dActiveTs) < 600 && $_dActiveTs >= $_dEndedTs
            && function_exists('getIntimacyForActor') && (int)(getIntimacyForActor($actorName)["level"] ?? 0) >= 1) { $_inSexScene = true; }
    }

    // Voice: sap slows ~30% (wins if both); skooma speeds up per level. Only set when a drug is active so the
    // drunk slur (set above) survives when she is only drunk.
    if ($sapLevel >= 1) {
        $GLOBALS["TTS_FFMPEG_FILTERS"]["tempo"] = 'atempo=' . _getNsfwSetting("SAP_TTS_TEMPO", "0.70");
    } elseif ($skoomaLevel >= 1) {
        $_skTempo = [1 => _getNsfwSetting("SKOOMA_TTS_TEMPO_1", "1.10"), 2 => _getNsfwSetting("SKOOMA_TTS_TEMPO_2", "1.20"), 3 => _getNsfwSetting("SKOOMA_TTS_TEMPO_3", "1.00")];
        $GLOBALS["TTS_FFMPEG_FILTERS"]["tempo"] = 'atempo=' . ($_skTempo[$skoomaLevel] ?? "1.10");
    }

    // Skooma animations + movement (OAR actor value the dance/strut configs read; gender split lives in OAR).
    // Uses its OWN variable (default Variable09, configurable) so it never collides with the drunk Variable10.
    if (_getNsfwSetting("DRUG_ANIMATIONS", true)) {
        $drugVar      = _getNsfwSetting("DRUG_OAR_VARIABLE", "Variable09");
        $skAvTarget   = ($skoomaLevel >= 1 && empty($_inSexScene)) ? $skoomaLevel : 0;
        $skAvCurrent  = intval($extended_data["aiagent_nsfw_skooma_av"] ?? 0);
        $speedTarget  = ($skoomaLevel >= 1 && empty($_inSexScene)) ? (float)_getNsfwSetting("SKOOMA_SPEEDMULT", 115) : 100.0;
        $speedCurrent = (float)($extended_data["aiagent_nsfw_skooma_speed"] ?? 100.0);
        if ($skAvTarget !== $skAvCurrent || $speedTarget !== $speedCurrent) {
            if (!class_exists('SkyrimCommandBuilder')) { require_once __DIR__ . "/../../lib/scriptproxy_papyrus.php"; }
            if (!class_exists('Npcmaster')) { require_once __DIR__ . "/../../lib/core/npc_master.class.php"; }
            $refidD = (new Npcmaster())->getByName($actorName)["refid"] ?? null;
            if (!empty($refidD)) {
                $cmdD = new SkyrimCommandBuilder();
                if ($skAvTarget !== $skAvCurrent) {
                    $cmdD->send($cmdD->Actor->ForceActorValue("0x{$refidD}", $drugVar, (float)$skAvTarget));
                    if ($skAvTarget > 0) { $extended_data["aiagent_nsfw_skooma_av"] = $skAvTarget; } else { unset($extended_data["aiagent_nsfw_skooma_av"]); }
                }
                if ($speedTarget !== $speedCurrent) {
                    $cmdD->send($cmdD->Actor->ForceActorValue("0x{$refidD}", "SpeedMult", $speedTarget)); // jittery stimulant; may need an in-game re-eval to apply
                    if ($speedTarget != 100.0) { $extended_data["aiagent_nsfw_skooma_speed"] = $speedTarget; } else { unset($extended_data["aiagent_nsfw_skooma_speed"]); }
                }
            }
        }
    }

    // Collapse via Paralysis = SLEEPING TREE SAP ONLY (one-hit). Skooma L3 does NOT collapse - it is the crazed,
    // on-her-feet "wanting more" state (the crazed idle below + the consent bypass in prerequest). Reuse the PROVEN
    // drunk-blackout drop (Paralysis + self-push ragdolls her flat; SetUnconscious alone leaves her standing),
    // and she still slurs from the ground via the Sleeping Tree Sap prompt. Own flag so it never fights the
    // drunk passout; only drops when NOT already down (alcohol) and NOT mid-scene; rouses when she comes off it.
    if (_getNsfwSetting("DRUG_ANIMATIONS", true)) {
        $shouldBeDown   = ($sapLevel >= 1);
        $drugDown       = !empty($extended_data["aiagent_nsfw_drug_down"]) || !empty($extended_data["aiagent_nsfw_sap_paralysis_applied"]);
        $alreadyDownAlc = !empty($extended_data["aiagent_nsfw_unconscious"]); // drunk passout owns this flag
        // Gate the collapse behind the drink animation + her reaction, exactly like the drunk blackout: measure
        // from the sap CONSUME localts so on the drink turn she pulls the cup, drinks, and slurs a line FIRST,
        // then drops on a later turn. Without this she ragdolled the instant the sap level was detected - no cup,
        // no line, just face-plant. Reuses DRUNK_FALL_AFTER_DRINK_SECONDS as the default if no sap override is set.
        $_sapFallCd = (float)_getNsfwSetting("SAP_FALL_AFTER_DRINK_SECONDS", (float)_getNsfwSetting("DRUNK_FALL_AFTER_DRINK_SECONDS", 8));
        $_actorSap  = $GLOBALS["db"]->escape($actorName);
        $_sapRegex  = $GLOBALS["db"]->escape(function_exists('aiagentNsfwSapRegex') ? aiagentNsfwSapRegex() : 'sleeping[ -]?tree[ -]?sap|tree sap');
        $_drugDoseActionRegex = $GLOBALS["db"]->escape(function_exists('aiagentNsfwDrugDoseActionRegex') ? aiagentNsfwDrugDoseActionRegex() : '^Consume$');
        $_lastSap   = $GLOBALS["db"]->fetchOne(
                "SELECT max(localts) AS lt FROM public.actions_issued
                 WHERE actorname = '{$_actorSap}' AND action ~* '{$_drugDoseActionRegex}' AND fullcall ~* '{$_sapRegex}'"
            );
        $_sapJustDrank = !empty($_lastSap['lt']) && (time() - (int)$_lastSap['lt']) < $_sapFallCd;
        $needDrop  = $shouldBeDown && !$drugDown && !$alreadyDownAlc && empty($_inSexScene) && !$_sapJustDrank;
        $needRouse = $drugDown && !$shouldBeDown && $drunkStage < 10; // don't stand her while a drunk blackout still holds her down
        if ($needDrop || $needRouse) {
            if (!class_exists('SkyrimCommandBuilder')) { require_once __DIR__ . "/../../lib/scriptproxy_papyrus.php"; }
            if (!class_exists('Npcmaster')) { require_once __DIR__ . "/../../lib/core/npc_master.class.php"; }
            $refidC = (new Npcmaster())->getByName($actorName)["refid"] ?? null;
            if (!empty($refidC)) {
                $refidHexC = "0x{$refidC}";
                $cmdC = new SkyrimCommandBuilder();
                if ($needDrop) {
                    $sapRouseSeconds = (int)_getNsfwSetting("SAP_AUTO_ROUSE_SECONDS", 1080);
                    if ($sapRouseSeconds < 60) { $sapRouseSeconds = 60; }
                    aiagentNsfwQueueSapDropAndRouse($actorName, $refidHexC, time(), time() + $sapRouseSeconds);
                    $extended_data["aiagent_nsfw_drug_down"] = 1;
                    $extended_data["aiagent_nsfw_sap_paralysis_applied"] = 1;
                    $extended_data["aiagent_nsfw_sap_rouse_due_localts"] = time() + $sapRouseSeconds;
                    error_log("[AIAGENTNSFW] DRUG CRASH for {$actorName} (skooma {$skoomaLevel}/sap {$sapLevel})");
                } else {
                    aiagentNsfwQueueSapRouse($actorName, $refidHexC);
                    unset($extended_data["aiagent_nsfw_drug_down"]);
                    unset($extended_data["aiagent_nsfw_sap_paralysis_applied"]);
                    unset($extended_data["aiagent_nsfw_sap_rouse_due_localts"]);
                    error_log("[AIAGENTNSFW] DRUG CRASH rouse for {$actorName}");
                }
            }
        }
    }

    // Skooma idles via PlayIdle (configurable editor-id default; swap to a hex FormID if it doesn't fire in-game).
    // L1-2 = an OCCASIONAL dance flourish (per-turn chance, like the drunk stumble). L3 = the crazed "wanting more"
    // idle on a recurring cooldown, since she stays on her feet and desperate (NOT collapsed). Both skipped while
    // down (alcohol/sap) or mid-scene. The mood-emote system + the OAR variable also dress the state on top of this.
    if (_getNsfwSetting("DRUG_ANIMATIONS", true) && $skoomaLevel >= 1 && empty($_inSexScene)
        && empty($extended_data["aiagent_nsfw_unconscious"]) && empty($extended_data["aiagent_nsfw_drug_down"])) {
        if (!class_exists('SkyrimCommandBuilder')) { require_once __DIR__ . "/../../lib/scriptproxy_papyrus.php"; }
        if (!class_exists('Npcmaster')) { require_once __DIR__ . "/../../lib/core/npc_master.class.php"; }
        $refidIdle = (new Npcmaster())->getByName($actorName)["refid"] ?? null;
        if (!empty($refidIdle)) {
            $_lastSkoomaDoseLt = 0;
            $_actorSkoomaIdle = $GLOBALS["db"]->escape($actorName);
            $_skoomaIdleRegex = $GLOBALS["db"]->escape(function_exists('aiagentNsfwSkoomaRegex') ? aiagentNsfwSkoomaRegex() : 'skooma|skuma|scuma|schuma');
            $_drugDoseIdleActionRegex = $GLOBALS["db"]->escape(function_exists('aiagentNsfwDrugDoseActionRegex') ? aiagentNsfwDrugDoseActionRegex() : '^Consume$');
            $_lastSkoomaDoseRow = $GLOBALS["db"]->fetchOne(
                "SELECT max(localts) AS lt FROM public.actions_issued
                 WHERE actorname = '{$_actorSkoomaIdle}' AND action ~* '{$_drugDoseIdleActionRegex}' AND fullcall ~* '{$_skoomaIdleRegex}'"
            );
            if (!empty($_lastSkoomaDoseRow['lt'])) { $_lastSkoomaDoseLt = (int)$_lastSkoomaDoseRow['lt']; }
            $_firstIdleDelay = (float)_getNsfwSetting("SKOOMA_FIRST_IDLE_DELAY_SECONDS", (float)_getNsfwSetting("DRUNK_FALL_AFTER_DRINK_SECONDS", 8));
            $_doseAnimationStillBusy = ($_lastSkoomaDoseLt > 0 && (time() - $_lastSkoomaDoseLt) < $_firstIdleDelay);
            // Post-consume idle: fires ONCE per dose at ANY level (user wants it every time someone does skooma).
            // Default is a one-shot CHEER (IdleCivilWarCheer) - the old IdleRitualStart is a ceremony *start*
            // idle that holds a pose waiting for a stop event, so it STUCK the actor and blocked every idle after it.
            $_ritualSentThisTurn = false;
            if ($skoomaLevel >= 1 && $_lastSkoomaDoseLt > 0 && !$_doseAnimationStillBusy) {
                $_ritualDoseLt = (int)($extended_data["aiagent_nsfw_skooma_ritual_dose_lt"] ?? 0);
                $_ritualIdle = trim((string)_getNsfwSetting("SKOOMA_POST_CONSUME_IDLE", "IdleCivilWarCheer"));
                if ($_ritualIdle !== "" && $_ritualDoseLt < $_lastSkoomaDoseLt) {
                    if (aiagentNsfwSendIdle($actorName, $refidIdle, $_ritualIdle)) {
                        $extended_data["aiagent_nsfw_skooma_ritual_dose_lt"] = $_lastSkoomaDoseLt;
                        $extended_data["aiagent_nsfw_skooma_dance_ts"] = time();
                        $_ritualSentThisTurn = true;
                        error_log("[AIAGENTNSFW] Skooma post-consume cheer idle for {$actorName} (level {$skoomaLevel}, idle {$_ritualIdle})");
                    }
                }
            }

            $idleChance   = (int)_getNsfwSetting("SKOOMA_DANCE_CHANCE", 2);
            $idleCooldown = (float)_getNsfwSetting("SKOOMA_DANCE_COOLDOWN_SECONDS", 25);
            $lastIdleTs   = (int)($extended_data["aiagent_nsfw_skooma_dance_ts"] ?? 0);
            $timerDue     = ((time() - $lastIdleTs) >= $idleCooldown);
            $firstSkoomaIdle = ($lastIdleTs <= 0 || (($skAvCurrent ?? 0) <= 0 && $skoomaLevel >= 1));

            if (!$_doseAnimationStillBusy && !$_ritualSentThisTurn && ($firstSkoomaIdle || ($timerDue && $idleChance > 0 && mt_rand(1, 100) <= $idleChance))) {
                if ($skoomaLevel >= 3) {
                    $idlePool = aiagentNsfwIdlePool("SKOOMA_L3_IDLE_POOL", "IdleWipeBrow\nIdleSleepNod\nIdleWarmHands");
                } elseif ($skoomaLevel === 2) {
                    $idlePool = aiagentNsfwIdlePool("SKOOMA_L2_IDLE_POOL", "IdleCiceroDance1\nIdleCiceroDance2\nIdleCiceroDance3");
                } else {
                    $idlePool = aiagentNsfwIdlePool("SKOOMA_L1_IDLE_POOL", "IdleCO2Ceremony1Welcome\nIdleLaugh\nIdleCiceroAgitated\nIdleCivilWarCheer\nIdleGetAttention\nIdleApplaud4\nIdleApplaud5");
                }
                if (!empty($idlePool)) {
                    $idleName = $idlePool[array_rand($idlePool)];
                    if (aiagentNsfwSendIdle($actorName, $refidIdle, $idleName)) {
                        $extended_data["aiagent_nsfw_skooma_dance_ts"] = time();
                        error_log("[AIAGENTNSFW] Skooma L{$skoomaLevel} idle for {$actorName} (idle {$idleName})");
                    }
                }
            }
        }
    }
}

// ============================================
// SLAVE IDLE ANIMATIONS (tier-based - mirrors the skooma idle system)
// A slave performs an affinity-appropriate idle toward the player on a chance + cooldown.
// SLAVERY_TIER_IDLE_<TIER> = pool of action ALIASES for that affinity tier; SLAVERY_IDLE_ALIAS_MAP maps
// each alias -> idle editor-id [|home]; aiagentNsfwSendIdle resolves the name -> FormID -> PlayIdle.
// Skipped mid-scene or while down (unconscious/drug crash).
// ============================================
if (_getNsfwSetting("SLAVERY_IDLES_ENABLED", true) && function_exists('isNpcSlave') && isNpcSlave($actorName)
    && empty($extended_data["aiagent_nsfw_unconscious"]) && empty($extended_data["aiagent_nsfw_drug_down"])
    // LAYERING (fix 2026-07-01p): drugs/deep drunk own the body. No calm service poses while skooma-high
    // (the skooma idle system is animating her) or at stage 6+ drunk (stumble/ragdoll territory).
    && (int)(isset($GLOBALS["AIAGENTNSFW_SKOOMA_LEVEL"]) ? $GLOBALS["AIAGENTNSFW_SKOOMA_LEVEL"]
             : (function_exists('getDrugStageForActor') ? getDrugStageForActor($actorName, 'skooma') : 0)) < 1
    && (int)($drunkStage ?? 0) < 6) {
    $slaveSceneState = getIntimacyForActor($actorName);
    $slaveInScene = !empty($slaveSceneState["sex_started"]) || !empty($slaveSceneState["is_npc_scene"]) || (int)($slaveSceneState["level"] ?? 0) >= 1;
    if (!$slaveInScene) {
        $slaveIdleChance   = (int)_getNsfwSetting("SLAVERY_IDLE_CHANCE", 12);
        $slaveIdleCooldown = (float)_getNsfwSetting("SLAVERY_IDLE_COOLDOWN_SECONDS", 120);
        $slaveLastIdleTs   = (int)($extended_data["aiagent_nsfw_slave_idle_ts"] ?? 0);
        if ((time() - $slaveLastIdleTs) >= $slaveIdleCooldown && $slaveIdleChance > 0 && mt_rand(1, 100) <= $slaveIdleChance) {
            $slaveRel  = RelationshipManager::getPlayerRelationship($actorName);
            $slaveTier = strtoupper(getAffinityTierName((int)($slaveRel['aff'] ?? 0)));
            $tierAliases = aiagentNsfwIdlePool("SLAVERY_TIER_IDLE_" . $slaveTier, "");
            if (!empty($tierAliases)) {
                $chosenAlias = $tierAliases[array_rand($tierAliases)];
                // Resolve alias -> "IdleEditorId[|home]" from the alias map
                $slaveIdleSpec = '';
                foreach (preg_split('/\r\n|\r|\n/', (string)_getNsfwSetting("SLAVERY_IDLE_ALIAS_MAP", "")) as $mapLine) {
                    $mapLine = trim($mapLine);
                    if ($mapLine === '' || strpos($mapLine, '=') === false) { continue; }
                    list($mapAlias, $mapVal) = explode('=', $mapLine, 2);
                    if (trim($mapAlias) === $chosenAlias) { $slaveIdleSpec = trim($mapVal); break; }
                }
                if ($slaveIdleSpec !== '') {
                    $slaveIdleSpecParts = explode('|', $slaveIdleSpec);
                    $slaveIdleName = trim($slaveIdleSpecParts[0]);
                    $slaveIdleLoc  = isset($slaveIdleSpecParts[1]) ? strtolower(trim($slaveIdleSpecParts[1])) : '';
                    // 'home' service idles require the home-service toggle
                    $slaveLocOk = ($slaveIdleLoc !== 'home') || _getNsfwSetting("SLAVERY_HOME_SERVICE_IDLES", true);
                    if ($slaveIdleName !== '' && $slaveLocOk) {
                        if (!class_exists('Npcmaster')) { require_once __DIR__ . "/../../lib/core/npc_master.class.php"; }
                        $slaveRefid = (new Npcmaster())->getByName($actorName)["refid"] ?? null;
                        if (!empty($slaveRefid) && aiagentNsfwSendIdle($actorName, $slaveRefid, $slaveIdleName)) {
                            $extended_data["aiagent_nsfw_slave_idle_ts"] = time();
                            error_log("[AIAGENTNSFW] Slave idle for {$actorName} (tier {$slaveTier}, alias {$chosenAlias}, idle {$slaveIdleName})");
                            // POSE vs SERVICE (fix 2026-07-01q): non-'home' poses (pray/bow/attention/grave...)
                            // are moments, not jobs - auto-release. 'home' service idles (drink tray, sweeping)
                            // keep their intentional indefinite hold until the clear conditions fire.
                            if ($slaveIdleLoc !== 'home') {
                                $poseSecs = max(3, (int)_getNsfwSetting('SLAVERY_AMBIENT_POSE_SECONDS', 20));
                                aiagentNsfwSendIdle($actorName, $slaveRefid, "IdleForceDefaultState", time() + $poseSecs);
                            }
                        }
                    }
                }
            }
        }
    }
}

// ============================================
// IDLE CLEAR: release a held pose / the slave drink tray when the state that triggered it is no longer valid,
// so she doesn't stay frozen (the "permanent drink plate" / stuck skooma pose). IdleForceDefaultState routes
// through aiagentNsfwSendIdle's CommandAnimation path (it's an anim EVENT, not a PlayIdle record) and resets the
// actor + drops any held AnimObject. Fired ONCE on the transition, then the tracker is zeroed so it never spams.
// ============================================
// Skooma worn off (level 0) or the drug-animation checkbox turned off -> release any held skooma pose.
if (!empty($extended_data["aiagent_nsfw_skooma_dance_ts"])
    && ((int)($skoomaLevel ?? 0) < 1 || !_getNsfwSetting("DRUG_ANIMATIONS", true))
    && empty($_inSexScene)) {
    // Only physically reset the actor if the held pose is RECENT (this session). A stale timestamp left over from
    // a prior playthrough (new game) must NOT fire IdleForceDefaultState at every old NPC we meet - that just
    // hitches their animation for nothing. Either way, zero the flag so it never re-checks.
    // LAYERING (fix 2026-07-01p): if the slave system posed her MORE RECENTLY (e.g. she sobered up and is
    // now holding the drink tray), this reset would knock that pose off - let the newer system own its release.
    if ((time() - (int)$extended_data["aiagent_nsfw_skooma_dance_ts"]) < 300
        && (int)($extended_data["aiagent_nsfw_slave_idle_ts"] ?? 0) <= (int)$extended_data["aiagent_nsfw_skooma_dance_ts"]) {
        if (!class_exists('Npcmaster')) { require_once __DIR__ . "/../../lib/core/npc_master.class.php"; }
        $skClrRefid = (new Npcmaster())->getByName($actorName)["refid"] ?? null;
        if (!empty($skClrRefid)) {
            aiagentNsfwSendIdle($actorName, $skClrRefid, "IdleForceDefaultState");
            error_log("[AIAGENTNSFW] Skooma worn off for {$actorName} - sent IdleForceDefaultState to release any held pose");
        }
    }
    $extended_data["aiagent_nsfw_skooma_dance_ts"] = 0;
    $extended_data["aiagent_nsfw_skooma_ritual_dose_lt"] = 0;
}
// Slave-idle checkbox turned off, or she is no longer a slave -> drop the held drink tray / pose.
if (!empty($extended_data["aiagent_nsfw_slave_idle_ts"])
    && (!_getNsfwSetting("SLAVERY_IDLES_ENABLED", true) || !(function_exists('isNpcSlave') && isNpcSlave($actorName)))) {
    if (!class_exists('Npcmaster')) { require_once __DIR__ . "/../../lib/core/npc_master.class.php"; }
    $slClrRefid = (new Npcmaster())->getByName($actorName)["refid"] ?? null;
    // LAYERING (fix 2026-07-01p): if the skooma system posed her more recently, this reset would cancel an
    // active drug pose - skip the physical reset (the tracker is still zeroed below so this never re-fires).
    if ((int)($extended_data["aiagent_nsfw_skooma_dance_ts"] ?? 0) > (int)$extended_data["aiagent_nsfw_slave_idle_ts"]) {
        $slClrRefid = null;
    }
    if (!empty($slClrRefid)) {
        aiagentNsfwSendIdle($actorName, $slClrRefid, "IdleForceDefaultState");
        error_log("[AIAGENTNSFW] Slave idles disabled for {$actorName} - sent IdleForceDefaultState to drop held tray/pose");
    }
    $extended_data["aiagent_nsfw_slave_idle_ts"] = 0;
}

// Save to nsfw_npc_data table (NOT core_npc_master.extended_data)
NsfwNpcData::save($actorName, $extended_data);

// Devious Devices: inject the speaking NPC's worn-restraint awareness (only when DD pushed a list + the toggle is on)
$ddWorn = $extended_data["aiagent_nsfw_devices"] ?? '';
if (!empty($ddWorn) && getGlobalPrompt("dd_awareness") === '1' && isset($GLOBALS["contextDataFull"][0]["content"])) {
    $ddMap = [
        'belt' => "a locked chastity belt (you cannot touch yourself or be penetrated vaginally)",
        'bra' => "a locked chastity bra",
        'gag' => "a gag",
        'collar' => "a locked collar marking you as owned",
        'armbinder' => "an armbinder, arms bound behind your back, leaving you helpless",
        'yoke' => "a yoke locking your hands and neck, leaving you helpless",
        'elbowtie' => "a strict elbow tie, arms bound, leaving you helpless",
        'straitjacket' => "a straitjacket binding your arms, leaving you helpless",
        'blindfold' => "a blindfold and cannot see",
        'hobbleskirt' => "a hobble skirt that restricts your movement",
        'armcuffs' => "arm cuffs",
        'legcuffs' => "leg cuffs",
        'ankleshackles' => "ankle shackles and cannot move quickly",
        'plugvaginal' => "a remotely-controlled vaginal plug",
        'pluganal' => "a remotely-controlled anal plug",
        'clamps' => "painful nipple clamps",
        'corset' => "a tight corset",
        'hood' => "a hood over your head",
        'harness' => "a leather harness",
        'gloves' => "locked gloves",
        'suit' => "a tight full-body restrictive suit",
        'piercingsnipple' => "remotely-controlled nipple piercings",
        'piercingsvaginal' => "a remotely-controlled clitoral piercing",
    ];
    // Only describe devices the user left enabled (UI checkboxes under Device Awareness). Empty/unset = all on
    // (default); '__none__' = all off.
    $ddEnabledRaw = function_exists('getGlobalPrompt') ? trim((string)getGlobalPrompt('dd_enabled_devices')) : '';
    if ($ddEnabledRaw === '__none__') {
        $ddEnabledList = [];
    } else if ($ddEnabledRaw === '') {
        $ddEnabledList = array_keys($ddMap);
    } else {
        $ddEnabledList = array_map('trim', explode(',', $ddEnabledRaw));
    }
    $ddPhrases = [];
    foreach (explode(",", $ddWorn) as $ddTok) {
        $ddTok = trim($ddTok);
        if ($ddTok !== "" && isset($ddMap[$ddTok]) && in_array($ddTok, $ddEnabledList, true)) {
            $ddPhrases[] = $ddMap[$ddTok];
        }
    }
    if (!empty($ddPhrases)) {
        $GLOBALS["contextDataFull"][0]["content"] .= "\n#Restraints: You are wearing " . implode("; ", $ddPhrases) . ". " . getGlobalPrompt("device_aware");
        $ddRefuse = getGlobalPrompt("device_refuse");
        if (!empty($ddRefuse)) {
            $GLOBALS["contextDataFull"][0]["content"] .= " " . $ddRefuse;
        }
        if (strpos(",{$ddWorn},", ",gag,") !== false && getGlobalPrompt("dd_gag_muffle") === '1') {
            $ddGag = getGlobalPrompt("device_gag");
            if (!empty($ddGag)) { $GLOBALS["contextDataFull"][0]["content"] .= "\n#" . $ddGag; }
        }
        if (getGlobalPrompt("dd_beg_keys") === '1') {
            $ddBeg = getGlobalPrompt("device_beg");
            if (!empty($ddBeg)) {
                $ddBeg = str_replace('#PLAYER_NAME#', ($GLOBALS["PLAYER_NAME"] ?? "your partner"), $ddBeg);
                $GLOBALS["contextDataFull"][0]["content"] .= "\n#" . $ddBeg;
            }
        }
    }
}

// Devious Devices: make the speaking NPC aware of the PLAYER's restraints (3rd-person), not just their own
$ddPlayerName = $GLOBALS["PLAYER_NAME"] ?? '';
if ($ddPlayerName !== '' && getGlobalPrompt("dd_awareness") === '1' && isset($GLOBALS["contextDataFull"][0]["content"])) {
    $ddPlayerData = NsfwNpcData::get($ddPlayerName);
    $ddPlayerWorn = $ddPlayerData["aiagent_nsfw_devices"] ?? '';
    if (!empty($ddPlayerWorn)) {
        $ddMapPlayer = [
            'belt' => "a locked chastity belt (their crotch is inaccessible - you cannot penetrate them vaginally)",
            'bra' => "a locked chastity bra",
            'gag' => "a gag (they can only make muffled sounds, they cannot speak clearly)",
            'collar' => "a locked collar marking them as owned",
            'armbinder' => "an armbinder, their arms bound behind their back - helpless",
            'yoke' => "a yoke locking their hands and neck - helpless",
            'elbowtie' => "a strict elbow tie, their arms bound - helpless",
            'straitjacket' => "a straitjacket binding their arms - helpless",
            'blindfold' => "a blindfold - they cannot see",
            'hobbleskirt' => "a hobble skirt that restricts their movement",
            'armcuffs' => "arm cuffs",
            'legcuffs' => "leg cuffs",
            'ankleshackles' => "ankle shackles - they cannot move quickly",
            'plugvaginal' => "a remotely-controlled vaginal plug",
            'pluganal' => "a remotely-controlled anal plug",
            'clamps' => "painful nipple clamps",
            'corset' => "a tight corset",
            'hood' => "a hood over their head",
            'harness' => "a leather harness",
            'gloves' => "locked gloves",
            'suit' => "a tight full-body restrictive suit",
            'piercingsnipple' => "remotely-controlled nipple piercings",
            'piercingsvaginal' => "a remotely-controlled clitoral piercing",
        ];
        $ddPlayerPhrases = [];
        foreach (explode(",", $ddPlayerWorn) as $ddpTok) {
            $ddpTok = trim($ddpTok);
            if ($ddpTok !== "" && isset($ddMapPlayer[$ddpTok])) {
                $ddPlayerPhrases[] = $ddMapPlayer[$ddpTok];
            }
        }
        if (!empty($ddPlayerPhrases)) {
            $GLOBALS["contextDataFull"][0]["content"] .= "\n#{$ddPlayerName} is restrained, wearing " . implode("; ", $ddPlayerPhrases) . ". " . getGlobalPrompt("device_player_aware");
        }
    }
}

// Add note if player is naked
if (playerIsNaked()) {
    error_log("[AIAGENTNSFW] Player is naked");
    $GLOBALS["contextDataFull"][0]["content"].="\n#Note: {$GLOBALS["PLAYER_NAME"]} is nude, not wearing clothes\n";

}

// VR held-items context is now handled by CORE (lib/vr_items.php :: HeldItems, merged from canonical codex/vr-item-awareness 2026-06-19).
// The ext no longer injects it here, to avoid duplicate held-items blocks (<held_items> from core + <VR_HELD_ITEMS> from ext).
?>
