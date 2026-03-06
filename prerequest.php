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
// ORGASM EVENT - Load prompts then exit
// ============================================
// When orgasm fires, we ONLY want the climax_prompt from the speak style
// Load prompts.php (defines ext_nsfw_orgasm), then let handler take over
// ============================================
$currentEvent = $GLOBALS["gameRequest"][0] ?? '';
$isOrgasmEvent = in_array($currentEvent, ['ext_nsfw_orgasm', 'chatnf_sl_climax', 'ext_nsfw_npc_orgasm']);

if ($isOrgasmEvent) {
    // NOTE: Do NOT require_once prompts.php here — it would prevent prompts.php from
    // re-running after prompts/prompts.php resets $PROMPTS at global scope.
    // Instead, store the computed cue in $GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"]
    // and let prompts.php (loaded via requireFilesRecursively) pick it up.

    $actorName     = $GLOBALS["HERIKA_NAME"] ?? '';
    $extended_data = NsfwNpcData::get($actorName);

    // PARSE ORGASM DATA FIRST — needed to correctly choose prompt type
    // Strip CHIM context prefix "(Context location: X)PlayerName:" to get raw OStim data
    $rawOrgasmData = $GLOBALS["gameRequest"][3] ?? '';
    $orgasmPayload = $rawOrgasmData;
    if (($cp = strpos($rawOrgasmData, ')')) !== false) {
        $afterParen = substr($rawOrgasmData, $cp + 1);
        if (($col = strpos($afterParen, ':')) !== false) {
            $orgasmPayload = substr($afterParen, $col + 1);
        }
    }
    $orgasmParts   = explode('/', $orgasmPayload);
    $ostimOrgasmer = trim($orgasmParts[0] ?? '');
    $ostimPartner  = trim($orgasmParts[3] ?? ''); // GetSexPartner() result from Papyrus

    $playerName       = $GLOBALS["PLAYER_NAME"] ?? "Player";
    $isPlayerOrgasm   = (strcasecmp($ostimOrgasmer, $playerName) === 0);
    $thisNpcIsPartner = (!empty($ostimPartner) && strcasecmp($actorName, $ostimPartner) === 0);

    // SELECT CORRECT SPEAK STYLE PROMPT based on who is orgasming
    // isPlayerOrgasm=true  → player came inside this NPC → use partner_climax_prompt (NPC reacts to player)
    // isPlayerOrgasm=false → this NPC is orgasming → use climax_prompt (NPC's own orgasm)
    if (!empty($extended_data["sex_speech_style"])) {
        $speakStyleData      = NsfwData::getSpeakStyle($extended_data["sex_speech_style"]);
        $climaxPrompt        = $speakStyleData['climax_prompt'] ?? '';
        $partnerClimaxPrompt = $speakStyleData['partner_climax_prompt'] ?? '';

        if ($isPlayerOrgasm && !empty($partnerClimaxPrompt)) {
            // Player orgasmed — NPC reacts to partner coming inside them
            $partnerClimaxPrompt = str_replace('#PLAYER_NAME#', $playerName, $partnerClimaxPrompt);
            $partnerClimaxPrompt = str_replace('#PARTNER#',     $playerName, $partnerClimaxPrompt);
            $GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"] = "<partner_climax_instruction>\n{$partnerClimaxPrompt}\n</partner_climax_instruction>";
            error_log("[AIAGENTNSFW] ORGASM: Using partner_climax_prompt for $actorName (player orgasmed)");
        } elseif (!empty($climaxPrompt)) {
            // NPC is orgasming themselves
            $withWhomPrompt = !empty($ostimPartner) ? $ostimPartner : $playerName;
            $climaxPrompt   = str_replace('#PARTNER#', $withWhomPrompt, $climaxPrompt);
            $GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"] = "<climax_instruction>\n{$climaxPrompt}\n</climax_instruction>";
            error_log("[AIAGENTNSFW] ORGASM: Using climax_prompt for $actorName (NPC orgasmed with $withWhomPrompt)");
        }
    }

    // SCENE CONTEXT — inject who is orgasming with whom into personality
    $orgIntimacy    = getIntimacyForActor($actorName);
    $orgSceneDesc   = $orgIntimacy["current_scene_desc"] ?? null;
    $orgSceneActors = $orgIntimacy["scene_actors"] ?? [];

    if (!empty($orgSceneActors)) {
        $orgPartnerNames = array_filter($orgSceneActors, function($a) use ($actorName) {
            return strtolower($a) !== strtolower($actorName);
        });

        if ($isPlayerOrgasm) {
            $withWhom = !empty($ostimPartner) ? $ostimPartner : (reset($orgPartnerNames) ?: $playerName);
            if ($thisNpcIsPartner || empty($ostimPartner)) {
                $orgSceneBlock = "<current_scene>\n{$playerName} is having an orgasm with you.";
            } else {
                $orgSceneBlock = "<current_scene>\n{$playerName} is having an orgasm with {$withWhom}. You are present in the scene.";
            }
        } else {
            $withWhom      = !empty($ostimPartner) ? $ostimPartner : $playerName;
            $orgSceneBlock = "<current_scene>\nYou are having an orgasm with {$withWhom}.";
        }

        if (!empty($orgSceneDesc)) {
            $orgSceneBlock .= "\nCurrent position: $orgSceneDesc";
        }
        $orgSceneTags = $orgIntimacy["current_scene_tags"] ?? [];
        if (!empty($orgSceneTags)) {
            $orgSceneBlock .= "\nScene tags: " . implode(", ", $orgSceneTags);
        }
        $orgSceneBlock .= "\n</current_scene>";
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $orgSceneBlock;

        // Replace any remaining #PARTNER# in the orgasm cue override and chatnf_sl_climax
        if (!empty($GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"])) {
            $GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"] = str_replace('#PARTNER#', $withWhom, $GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"]);
        }
        if (isset($GLOBALS["PROMPTS"]["chatnf_sl_climax"]["cue"][0])) {
            $GLOBALS["PROMPTS"]["chatnf_sl_climax"]["cue"][0] = str_replace('#PARTNER#', $withWhom, $GLOBALS["PROMPTS"]["chatnf_sl_climax"]["cue"][0]);
        }
    }

    error_log("[AIAGENTNSFW] ORGASM for $actorName — orgasmer: $ostimOrgasmer | partner: $ostimPartner | isPlayerOrgasm: " . ($isPlayerOrgasm ? 'yes' : 'no'));
    return;  // Exit - don't inject speech style or other stuff
}

// ============================================
// PILLOW TALK INJECTION (POST-ORGASM + SCENE END)
// ============================================
// Pillow talk requires BOTH: scene_end AND orgasm occurred
// Only NPCs who orgasmed during the scene get pillow_talk_pending set
// NPCs who didn't orgasm have their scene state cleared but no pillow talk
// CRITICAL: This MUST clear scene state to prevent sex prompts from firing
// ============================================
$isPillowTalkMode = false;  // Flag to skip sex prompt injection
if (!empty($intimacyStatus["pillow_talk_pending"]) && !empty($intimacyStatus["pillow_talk_prompt"])) {
    $pillowTalkPrompt = $intimacyStatus["pillow_talk_prompt"];
    $GLOBALS["HERIKA_PERSONALITY"] .= "\n<post_scene_instruction>\nIMPORTANT: The intimate scene has ENDED. You are NO LONGER having sex. STOP all orgasm expressions like 'Oh gods', 'CUMMING', 'YES!', moaning, etc. Return to normal conversation immediately. The physical act is completely over.\n{$pillowTalkPrompt}\n</post_scene_instruction>";
    error_log("[AIAGENTNSFW] Injected pillow talk for {$GLOBALS["HERIKA_NAME"]}");

    // CLEAR ALL SCENE STATE to prevent sex prompts from firing
    $intimacyStatus["pillow_talk_pending"] = false;
    $intimacyStatus["pillow_talk_prompt"] = "";
    $intimacyStatus["level"] = 0;  // Force level 0 to disable sex prompts
    $intimacyStatus["scene_actors"] = null;  // Clear scene actors
    $intimacyStatus["scene_phase"] = null;  // Clear phase
    $intimacyStatus["is_npc_scene"] = false;
    $intimacyStatus["npc_scene_partner"] = null;
    updateIntimacyForActor($GLOBALS["HERIKA_NAME"], $intimacyStatus);

    $isPillowTalkMode = true;  // Skip all sex prompt injection below

    // CRITICAL: Override ALL sex-related prompts to pillow talk
    // This handles late chatnf_sl events that Papyrus might send after scene_end
    $pillowTalkCue = "<post_scene_instruction>\nThe intimate scene has ENDED. You are NOT having sex anymore. Have a brief, natural post-intimacy conversation. Do NOT moan, gasp, or use sexual expressions. Talk normally.\n{$pillowTalkPrompt}\n</post_scene_instruction> " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");

    // Override chatnf_sl (main sex scene prompt)
    $GLOBALS["PROMPTS"]["chatnf_sl"]["cue"] = [$pillowTalkCue];

    // Override chatnf_sl_end (this IS pillow talk, but ensure it's correct)
    $GLOBALS["PROMPTS"]["chatnf_sl_end"]["cue"] = [$pillowTalkCue];

    // Override climax prompts (in case of late events)
    $GLOBALS["PROMPTS"]["chatnf_sl_climax"]["cue"] = [$pillowTalkCue];
    $GLOBALS["PROMPTS"]["ext_nsfw_orgasm"]["cue"] = [$pillowTalkCue];

    // Override NPC scene prompts too
    if (isset($GLOBALS["PROMPTS"]["ext_nsfw_npc_scene"])) {
        $GLOBALS["PROMPTS"]["ext_nsfw_npc_scene"]["cue"] = [$pillowTalkCue];
    }
    if (isset($GLOBALS["PROMPTS"]["ext_nsfw_npc_orgasm"])) {
        $GLOBALS["PROMPTS"]["ext_nsfw_npc_orgasm"]["cue"] = [$pillowTalkCue];
    }

    error_log("[AIAGENTNSFW] Pillow talk mode active - overrode ALL sex prompts for {$GLOBALS["HERIKA_NAME"]}");
}

if ($codeName == "the_narrator") {
    return; // Narrator updates scene data only — no LLM call, no log entries
}

// ============================================
// NPC-TO-NPC SCENE: #PARTNER# PLACEHOLDER
// ============================================
// For NPC-to-NPC scenes, replace #PARTNER# in prompts with actual partner name
// This must run early before prompts are finalized
// ============================================
if (!empty($intimacyStatus["is_npc_scene"]) && !empty($intimacyStatus["npc_scene_partner"])) {
    $partnerName = $intimacyStatus["npc_scene_partner"];

    // Replace in relevant prompt arrays
    $promptKeys = ['ext_nsfw_npc_scene', 'ext_nsfw_npc_invite', 'ext_nsfw_npc_orgasm', 'chatnf_npc_sl'];
    foreach ($promptKeys as $key) {
        if (isset($GLOBALS["PROMPTS"][$key]["cue"])) {
            foreach ($GLOBALS["PROMPTS"][$key]["cue"] as &$cue) {
                $cue = str_replace('#PARTNER#', $partnerName, $cue);
                $cue = str_replace('#PARTNER_NAME#', $partnerName, $cue);
            }
            unset($cue);  // Break reference
        }
    }

    error_log("[AIAGENTNSFW] Replaced #PARTNER# placeholder with: {$partnerName} for NPC-to-NPC scene");
}

// From here should apply to profiled actors

// Disposal modifiers per iteration
// Every iteration we lower sex_disposal by 1
// ONLY if sex_disposal system is enabled
if (isSexDisposalEnabled()) {
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
}

$actorName=$GLOBALS["HERIKA_NAME"];
$npcManager=new NpcMaster();
$npcData=$npcManager->getByName($actorName);
// Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
require_once __DIR__ . "/nsfw_data.php";
$extended_data = NsfwNpcData::get($actorName);
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
                // Save to nsfw_npc_data table (NOT core_npc_master.extended_data)
                NsfwNpcData::save($actorName, $extended_data);
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
    if (isSexDisposalEnabled()) {
        $intimacyStatus["sex_disposal"]=($intimacyStatus["sex_disposal"]<20)?20: $intimacyStatus["sex_disposal"];
    }
    $intimacyStatus["adult_entertainment_services_autodetected"]=true;

} else {
    $intimacyStatus["adult_entertainment_services_autodetected"]=false;
}


// Arousal from NPC data or profile.
// Only apply if sex_disposal system is enabled
if (isSexDisposalEnabled()) {
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
}


// ============================================
// PROSTITUTE PAYMENT FLOW
// ============================================
// Prostitutes now use CollectPayment function directly.
// CollectPayment uses PaymentHandler to transfer gold via Papyrus
// and sets payment_confirmed=true to unlock scene progression.
// No automatic detection needed - NPC controls when to collect.
// ============================================

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
$GLOBALS["AIAGENTNSFW_SCENE_PHASE"] = $scenePhase;

// SKIP ALL SCENE PHASE HANDLING if in pillow talk mode
// Pillow talk means scene just ended - no more sex prompts!
if ($isPillowTalkMode) {
    $scenePhase = null;  // Force skip all phase handling
    error_log("[AIAGENTNSFW] Pillow talk mode - skipping all scene phase handling for $actorName");
}

// ============================================
// AROUSAL GATING OFF - BYPASS ALL PHASE/LEVEL COMPLEXITY
// ============================================
// When arousal gating is OFF, skip ALL the tier_prompt/accepted/level stuff.
// Any NPC in a scene event goes straight to engaged state.
// This is the master bypass - no levels, no phases, just engaged.
// ============================================
if (!isSexDisposalEnabled() && !$isPillowTalkMode) {
    $sceneEventTypes = [
        'chatnf_sl', 'chatnf_sl_climax', 'chatnf_sl_moan', 'chatnf_sl_naked',
        'ext_nsfw_sexcene', 'ext_nsfw_action', 'ext_nsfw_scene', 'ext_nsfw_orgasm',
        'ext_nsfw_npc_scene', 'ext_nsfw_npc_invite', 'ext_nsfw_npc_orgasm'
    ];
    if (in_array($gameRequest[0] ?? '', $sceneEventTypes)) {
        // Tier prompt is the most important injection - it sets the emotional tone
        // Allow it to fire ONCE, then progress through accepted (for sex prompt injection) to engaged
        if ($scenePhase === "rejected") {
            // NPC refused - preserve rejection/non-consent handling
            error_log("[AIAGENTNSFW] AROUSAL GATING OFF - preserving rejected phase for $actorName");
        } else if ($scenePhase === "tier_prompt" && !isset($intimacyStatus["tier_prompt_sent"])) {
            // Let tier_prompt fire - don't skip to engaged yet
            error_log("[AIAGENTNSFW] AROUSAL GATING OFF - allowing tier_prompt to fire for $actorName");
        } else if ($scenePhase === "tier_prompt" && isset($intimacyStatus["tier_prompt_sent"])) {
            // Tier prompt done - progress to accepted so sex prompts/speech styles inject
            $intimacyStatus["scene_phase"] = "accepted";
            $scenePhase = "accepted";
            error_log("[AIAGENTNSFW] AROUSAL GATING OFF - tier prompt done, progressing through accepted for $actorName");
        } else if ($scenePhase === "accepted") {
            // Let accepted phase run - it injects sex prompts, then auto-transitions to engaged
            error_log("[AIAGENTNSFW] AROUSAL GATING OFF - allowing accepted phase for $actorName");
        } else {
            // Past all phases - force engaged
            $intimacyStatus["scene_phase"] = "engaged";
            $scenePhase = "engaged";
            error_log("[AIAGENTNSFW] AROUSAL GATING OFF - forcing $actorName to engaged phase");
        }
    }
}

// ============================================
// REJECTED PHASE: NPC called RefuseSex - HANDLE REFUSAL OR NON-CONSENT
// ============================================
// When an NPC refuses:
// 1. First time: inject refusal confirmation prompt, set refusal_expressed flag
// 2. If scene events continue (player forced it): inject non-consent prompt
// ============================================
if ($scenePhase === "rejected") {
    require_once __DIR__ . "/nsfw_data.php";

    // Check if this is a scene event (indicating forced continuation after refusal)
    $isForcedSceneEvent = in_array($gameRequest[0] ?? '', [
        'chatnf_sl', 'chatnf_sl_climax', 'chatnf_sl_moan', 'chatnf_sl_naked',
        'ext_nsfw_sexcene', 'ext_nsfw_action', 'ext_nsfw_scene', 'ext_nsfw_orgasm'
    ]);

    // Get partner name for prompts
    $partnerName = $intimacyStatus["scene_actors"][0] ?? $GLOBALS["PLAYER_NAME"] ?? "them";
    if (is_array($intimacyStatus["scene_actors"])) {
        foreach ($intimacyStatus["scene_actors"] as $actor) {
            if (strtolower($actor) !== strtolower($actorName)) {
                $partnerName = $actor;
                break;
            }
        }
    }

    // If refusal was already expressed AND this is a scene event = NON-CONSENT
    if (!empty($intimacyStatus["refusal_expressed"]) && $isForcedSceneEvent) {
        error_log("[AIAGENTNSFW] NON-CONSENT: $actorName refused but scene continues");

        // Only inject non-consent prompt if enabled (disable to prevent canned refusals from frontier models)
        if (isNonConsentPromptEnabled()) {
            $nonConsentPrompt = NsfwData::getPrompt('non_consent');
            if (!empty($nonConsentPrompt)) {
                $nonConsentPrompt = str_replace('#PARTNER#', $partnerName, $nonConsentPrompt);
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n<non_consent_context>\n{$nonConsentPrompt}\n</non_consent_context>";
                error_log("[AIAGENTNSFW] Injected non-consent prompt for $actorName (forced by $partnerName)");
            }
        } else {
            error_log("[AIAGENTNSFW] Non-consent prompt DISABLED - skipping injection to avoid model refusals");
        }

        // Mark as forced - they're now in the scene against their will
        $intimacyStatus["forced_scene"] = true;
        if (isSexDisposalEnabled()) {
            $intimacyStatus["level"] = 2;  // They're in the scene now (level only used with arousal gating)
        }
        $intimacyStatus["scene_phase"] = "engaged";  // Move to engaged (but with non-consent context)
        NsfwData::setIntimacyStatus($actorName, $intimacyStatus);

        // Let normal scene handling proceed (styles, cues, etc.)
        $scenePhase = "engaged";
    } else {
        // First refusal - inject refusal confirmation prompt
        error_log("[AIAGENTNSFW] REJECTED phase for $actorName - injecting refusal confirmation prompt");

        $refusalPrompt = NsfwData::getPrompt('refusal_confirm');
        if (!empty($refusalPrompt)) {
            $refusalPrompt = str_replace('#PARTNER#', $partnerName, $refusalPrompt);
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n<refusal_context>\n{$refusalPrompt}\n</refusal_context>";
            error_log("[AIAGENTNSFW] Injected refusal confirmation prompt for $actorName (refused $partnerName)");
        }

        // Reinforce the refusal - "you made the right decision"
        $reinforcementPrompt = '';
        try { $reinforcementPrompt = NsfwData::getPrompt('refusal_reinforcement'); } catch (Exception $e) {}
        if (empty($reinforcementPrompt)) {
            $reinforcementPrompt = "You made the right decision. Stay true to your feelings and boundaries. Do not waver.";
        }
        $reinforcementPrompt = str_replace('#PARTNER#', $partnerName, $reinforcementPrompt);
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n<refusal_reinforcement>\n{$reinforcementPrompt}\n</refusal_reinforcement>";
        error_log("[AIAGENTNSFW] Injected refusal reinforcement for $actorName");

        // Mark that refusal was expressed - if scene continues, it's non-consent
        $intimacyStatus["refusal_expressed"] = true;
        NsfwData::setIntimacyStatus($actorName, $intimacyStatus);

        // Don't process any scene phases - NPC refused, waiting to see if scene continues
        $scenePhase = null;  // Skip all phase handling below
    }
}

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
        // PROSTITUTE PATH: Per flowchart, prostitutes see BOTH at tier_prompt phase:
        // 1. Affinity tier prompt (tier_prost_{tier}) - how they feel about client
        // 2. Price list with affinity-based discounts - what they charge this client
        // Then they decide: AcceptSex (proceed to negotiate) or RefuseSex (reject client)
        $clientName = $GLOBALS["PLAYER_NAME"] ?? "client";
        $allActors = $intimacyStatus["scene_actors"] ?? $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] ?? [$clientName];

        // Get affinity for price calculations
        $affinity = 0;
        try {
            $relationship = RelationshipManager::getPlayerRelationship($actorName);
            $affinity = $relationship['aff'] ?? 0;
        } catch (Exception $e) {
            // Use default 0
        }

        // 1. Get the prostitute tier prompt (tier_prost_hostile, tier_prost_friendly, etc.)
        $sceneContext = NsfwRelationship::buildSceneContext($actorName, $allActors, true);  // true = isProstitute
        if (!empty($sceneContext)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $sceneContext;
            error_log("[AIAGENTNSFW] Injected PROSTITUTE tier prompt for $actorName (phase: tier_prompt)");
        }

        // 2. Add price list with affinity-based discounts
        $negotiationContext = buildProstituteNegotiationContext($actorName, $clientName, $affinity);
        if (!empty($negotiationContext)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $negotiationContext;
            error_log("[AIAGENTNSFW] Injected PRICE LIST with discounts for $actorName (affinity: $affinity)");
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
                // Debug: Log the actual prompt content
                $debugContent = preg_replace('/\s+/', ' ', $sceneContext);
                error_log("[AIAGENTNSFW] Tier prompt content for $actorName: " . $debugContent);
            }
        } else {
            error_log("[AIAGENTNSFW] WARNING: No scene actors found for tier prompt injection for $actorName");
        }
    }

    // For slaves, auto-accept (no refusal logic - slaves must comply)
    // For prostitutes and regular NPCs, model decides based on tier prompt
    if ($isSlave) {
        // Auto-progress to accepted phase
        $intimacyStatus["scene_phase"] = "accepted";
        error_log("[AIAGENTNSFW] Auto-accepting for $actorName (slave - must comply)");
    } else {
        // Regular NPC - mark that tier prompt was sent, wait for next request
        // The model's response will determine if they accepted
        // For now, we assume if scene continues (chatnf_sl), they accepted
        $intimacyStatus["tier_prompt_sent"] = true;

        // ============================================
        // CRITICAL: Use the TIER PROMPT from DB as the CUE
        // ============================================
        // The tier prompt (from UI) already contains the accept/refuse instruction
        // It should be the CUE that triggers the response, not just personality context
        // This ensures the model responds to the proposition based on UI config
        // ============================================

        // Store tier cue in global so prompts.php can use it as the CUE override.
        // We can't override PROMPTS here because prompts.php loads AFTER prerequest
        // and would overwrite our changes with the default explicit sex commentary.
        $allActors = $intimacyStatus["scene_actors"] ?? $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] ?? [$GLOBALS["PLAYER_NAME"]];
        $tierCueContext = NsfwRelationship::buildSceneContext($actorName, $allActors, $isProstitute);

        if (!empty($tierCueContext)) {
            $GLOBALS["AIAGENTNSFW_TIER_CUE_OVERRIDE"] = $tierCueContext;
            error_log("[AIAGENTNSFW] Stored tier prompt CUE override for prompts.php to pick up");
        }
    }

    $tierContextAlreadyInjected = true;  // Prevent re-injection in universal block

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

    // ACCEPT sets level = 1 (pre-intimate) - ONLY when arousal gating is ON
    if (isSexDisposalEnabled()) {
        $intimacyStatus["level"] = 1;
        error_log("[AIAGENTNSFW] Phase: accepted for $actorName, level set to 1");
    } else {
        error_log("[AIAGENTNSFW] Phase: accepted for $actorName (arousal gating OFF - no level set)");
    }

    // ============================================
    // INJECT PERSONALITY/STYLE at Level 1
    // ============================================
    // Styles inject AFTER accept but BEFORE arousal check
    // ============================================
    error_log("[AIAGENTNSFW] Injecting styles at level 1 for $actorName");

    // ============================================
    // ACCEPTED PHASE: Wait for actual sex to start
    // ============================================
    // At ACCEPTED, we only inject basic personality context
    // Sex prompts, speech styles, profanity, and KINKS inject
    // when sex_started == true (actual sex stage, not idle)
    // WHEN AROUSAL GATING IS OFF: bypass sex_started check - inject immediately
    // ============================================
    $sexStarted = !empty($intimacyStatus["sex_started"]) || !isSexDisposalEnabled();

    // Inject personality and speech style based on NPC type
    if ($isSlave) {
        // SLAVE PATH ONLY: Slave-specific personality and speech
        $slavePersonality = NsfwRelationship::getSlavePersonality($slaveAffinity, $slaveOwnerName);
        if (!empty($slavePersonality)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . NsfwRelationship::wrapXml('slave_personality', $slavePersonality);
            error_log("[AIAGENTNSFW] Injected SLAVE personality for $actorName (affinity: $slaveAffinity)");
        }
        $slavePromptAlreadyInjected = true;  // Prevent duplicate injection in universal block

        // Slave speech style and scene cues only when sex ACTUALLY starts
        if ($sexStarted) {
            $slaveSpeech = NsfwRelationship::getSlaveSpeechStyle($slaveAffinity, $slaveOwnerName);
            if (!empty($slaveSpeech)) {
                $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n" . NsfwRelationship::wrapXml('slave_speech', $slaveSpeech);
                error_log("[AIAGENTNSFW] Injected SLAVE speech style for $actorName (sex started)");
            }
        } else {
            error_log("[AIAGENTNSFW] SLAVE $actorName accepted - waiting for actual sex to start");
        }
    } else if ($isProstitute) {
        // PROSTITUTE PATH ONLY: Negotiation context if payment not confirmed
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
            }
        } else if ($sexStarted) {
            // Payment confirmed AND sex started - inject prostitute sex prompts
            error_log("[AIAGENTNSFW] Prostitute payment confirmed + sex started - using sex prompts for $actorName");
            setSexPrompt($GLOBALS["HERIKA_NAME"]);
            setSexSpeechStyle($GLOBALS["HERIKA_NAME"]);
        } else {
            error_log("[AIAGENTNSFW] PROSTITUTE $actorName payment confirmed - waiting for actual sex to start");
        }
    } else {
        // REGULAR NPC / MARRIAGE / AFFAIR PATH
        // Sex prompts, speech style, profanity, and KINKS only when sex ACTUALLY starts
        if ($sexStarted) {
            // Check if NPC has profile (for profile vs default prompts)
            require_once __DIR__ . "/nsfw_data.php";
            $npcExtended = NsfwNpcData::get($actorName);
            $hasProfile = isset($npcExtended['source']) && !empty($npcExtended['source']);

            if ($hasProfile) {
                error_log("[AIAGENTNSFW] NPC $actorName HAS PROFILE - using profile prompts");
            } else {
                error_log("[AIAGENTNSFW] NPC $actorName NO PROFILE - using default prompts");
            }

            // Inject sex prompt, speech style, profanity, and kinks (affinity-gated)
            setSexPrompt($GLOBALS["HERIKA_NAME"]);
            setSexSpeechStyle($GLOBALS["HERIKA_NAME"]);
            error_log("[AIAGENTNSFW] Injected sex prompts for $actorName (sex started)");
        } else {
            error_log("[AIAGENTNSFW] Regular NPC $actorName accepted - waiting for actual sex to start");
        }
    }

    // ============================================
    // AROUSAL CHECK - determines level 1 vs 2
    // ============================================
    // ONLY runs when arousal gating is ON
    // When OFF, skip straight to engaged
    // ============================================
    if (isSexDisposalEnabled()) {
        // AROUSAL GATING ON - check thresholds
        $arousalThreshold = 10;
        $currentArousal = $intimacyStatus["sex_disposal"] ?? 0;

        if ($isProstitute) {
            // PROSTITUTE: Payment confirmation is REQUIRED before engaging
            if (!empty($intimacyStatus["payment_confirmed"])) {
                $intimacyStatus["scene_phase"] = "engaged";
                $intimacyStatus["level"] = 2;
                error_log("[AIAGENTNSFW] Prostitute payment confirmed - engaging scene for $actorName");
            } else {
                $intimacyStatus["level"] = 0;
                error_log("[AIAGENTNSFW] Prostitute awaiting payment - staying at level 0 for $actorName");
            }
        } else if ($isSlave || $currentArousal >= $arousalThreshold) {
            $intimacyStatus["scene_phase"] = "engaged";
            $intimacyStatus["level"] = 2;
            error_log("[AIAGENTNSFW] Arousal check passed ($currentArousal >= $arousalThreshold) - engaging scene for $actorName");
        } else {
            error_log("[AIAGENTNSFW] Arousal too low ($currentArousal < $arousalThreshold) - foreplay for $actorName");
        }
    } else {
        // AROUSAL GATING OFF - go straight to engaged, no checks
        $intimacyStatus["scene_phase"] = "engaged";
        $intimacyStatus["level"] = 2;
        error_log("[AIAGENTNSFW] Arousal gating OFF - engaging scene for $actorName (no arousal checks)");
    }
}

// ============================================
// LEVEL MANAGEMENT - ONLY WHEN AROUSAL GATING IS ON
// ============================================
// Levels are part of the sex_disposal/arousal system.
// When arousal gating is OFF, skip ALL level manipulation.
// ============================================
if (isSexDisposalEnabled()) {
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
    // SLAVE FIX: Slaves skip acceptance phase - they just comply
    // But ONLY if there's actually a scene in progress (level >= 1 or scene_actors present)
    // AND we're in an actual scene event - not regular dialogue after rejection
    // ============================================
    $sceneActorsForSlaveCheck = $intimacyStatus["scene_actors"] ?? [];
    $isSceneEventForSlaveCheck = in_array($gameRequest[0] ?? '', [
        'chatnf_sl', 'chatnf_sl_climax', 'chatnf_sl_moan', 'chatnf_sl_naked',
        'ext_nsfw_sexcene', 'ext_nsfw_action', 'ext_nsfw_scene', 'ext_nsfw_orgasm',
        'ext_nsfw_npc_scene', 'ext_nsfw_npc_invite', 'ext_nsfw_npc_orgasm'
    ]);
    $sceneInProgress = $isSceneEventForSlaveCheck && (
        ($intimacyStatus["level"] >= 1) || (!empty($sceneActorsForSlaveCheck) && count($sceneActorsForSlaveCheck) > 0)
    );

    if ($isSlave && $sceneInProgress && $intimacyStatus["level"] < 2) {
        $intimacyStatus["level"] = 2;
        $intimacyStatus["scene_phase"] = "engaged";
        error_log("[AIAGENTNSFW] Slave in active scene - forcing $actorName to level 2 (slaves always comply)");
    }
}

// ============================================
// GROUP SCENE FIX: If scene_actors exists and has entries,
// the scene is active - force level to at least 1
// This ensures ALL participants get prompts once anyone accepts
// BUT ONLY if we're in an active scene event (chatnf_sl, ext_nsfw_sexcene, etc)
// NOT on regular dialogue - that means the scene was rejected/cancelled
// ONLY applies when arousal gating is ON (levels are part of that system)
// ============================================
$sceneActors = $intimacyStatus["scene_actors"] ?? [];
$isSceneEvent = in_array($gameRequest[0] ?? '', [
    'chatnf_sl', 'chatnf_sl_climax', 'chatnf_sl_moan', 'chatnf_sl_naked',
    'ext_nsfw_sexcene', 'ext_nsfw_action', 'ext_nsfw_scene', 'ext_nsfw_orgasm',
    'ext_nsfw_npc_scene', 'ext_nsfw_npc_invite', 'ext_nsfw_npc_orgasm'
]);

// Group scene level management - ONLY when arousal gating is ON
if (isSexDisposalEnabled()) {
    if (!empty($sceneActors) && count($sceneActors) > 1 && $intimacyStatus["level"] < 1 && $isSceneEvent) {
        // Check if tier_prompt has been sent - only auto-accept AFTER tier prompt phase
        // This ensures NPCs get a chance to accept/refuse before proceeding
        $tierPromptSent = !empty($intimacyStatus["tier_prompt_sent"]);

        if ($tierPromptSent || $intimacyStatus["scene_phase"] === "accepted") {
            // Tier prompt was already sent (or explicitly accepted) - safe to proceed
            $intimacyStatus["level"] = 1;
            if (empty($intimacyStatus["scene_phase"]) || $intimacyStatus["scene_phase"] === "tier_prompt") {
                $intimacyStatus["scene_phase"] = "accepted";
            }
            error_log("[AIAGENTNSFW] Group scene active - forcing $actorName to level 1 (scene has " . count($sceneActors) . " actors, tier_prompt already sent)");
        } else {
            // Tier prompt NOT sent yet - let them go through accept/refuse phase first
            error_log("[AIAGENTNSFW] Group scene for $actorName - tier_prompt phase not complete, allowing accept/refuse");
        }
    } else if (!empty($sceneActors) && count($sceneActors) > 1 && !$isSceneEvent && $intimacyStatus["level"] < 1) {
        // Scene actors exist but this is NOT a scene event - scene was rejected/cancelled
        // Clear the stale scene data
        error_log("[AIAGENTNSFW] Stale scene_actors detected for $actorName on non-scene event ({$gameRequest[0]}) - clearing");
        $intimacyStatus["scene_actors"] = null;
        $intimacyStatus["scene_phase"] = null;
        $intimacyStatus["level"] = 0;
        updateIntimacyForActor($actorName, $intimacyStatus);
    }
}

// Force mood and inject prompts during scenes
// When arousal gating is ON: check level > 0
// When arousal gating is OFF: check if we're in a scene event
// SKIP if in pillow talk mode (scene just ended)
$isInActiveScene = isSexDisposalEnabled() ? ($intimacyStatus["level"] > 0) : $isSceneEvent;
if ($isInActiveScene && !$isPillowTalkMode) {
    // Don't force sexy mood during tier_prompt - scene just started, nothing sexual yet
    $currentScenePhaseForMood = $intimacyStatus["scene_phase"] ?? null;
    if ($currentScenePhaseForMood !== "tier_prompt") {
        $GLOBALS["FORCE_MOOD"] = "sexy";
    }

    // ============================================
    // UNIVERSAL PROMPT INJECTION - ANY LEVEL > 0
    // ============================================
    // Sex prompts and affinity context should hit on EVERY request
    // during an active scene, regardless of level 1 or 2
    // ============================================
    // ============================================
    // INJECT SCENE CONTEXT - BUT WITHOUT TIER PROMPT FOR ENGAGED NPCs
    // ============================================
    // The tier prompt (accept/refuse instructions) is ONLY needed during tier_prompt phase.
    // Once accepted/engaged, we only need participant info - NOT the emotional_state block
    // that tells them to refuse. This fixes the bug where scene changes mid-sex
    // would re-inject "REFUSE SEX" instructions.
    // ============================================
    $sceneActorsForContext = $intimacyStatus["scene_actors"] ?? [];
    $currentScenePhase = $intimacyStatus["scene_phase"] ?? null;
    if (!empty($sceneActorsForContext)) {
        // Only inject FULL context (with tier prompt) during tier_prompt phase
        // For accepted/engaged phases, only inject participant info (no tier prompt)
        if ($currentScenePhase === "tier_prompt" && empty($tierContextAlreadyInjected)) {
            // Only inject if tier_prompt handler didn't already do it above
            $sceneContext = NsfwRelationship::buildSceneContext($actorName, $sceneActorsForContext, $isProstitute);
            if (!empty($sceneContext)) {
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $sceneContext;
                error_log("[AIAGENTNSFW] Injected FULL <intimate_scene> context for $actorName (tier_prompt phase, universal block)");
            }
        } else if ($currentScenePhase === "tier_prompt" && !empty($tierContextAlreadyInjected)) {
            // Tier prompt handler already injected this - skip to avoid double injection
            error_log("[AIAGENTNSFW] Skipping tier prompt re-injection for $actorName (already injected by tier_prompt handler)");
        } else {
            // Accepted/engaged: Only inject participant info, NO tier prompt
            // This prevents "REFUSE SEX" from being re-injected on scene changes
            $participantResult = NsfwRelationship::buildMultiActorContext($actorName, $sceneActorsForContext, $isProstitute);
            if (!empty($participantResult['context'])) {
                // Wrap participant info only (no tierPrompt) in intimate_scene block
                $participantContext = NsfwRelationship::wrapXml('intimate_scene', $participantResult['context']);
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $participantContext;
                error_log("[AIAGENTNSFW] Injected PARTICIPANT-ONLY context for $actorName (phase: $currentScenePhase)");
            }
        }
    }

    // Scene description injection moved OUTSIDE this block (see below)
    // so it applies to ALL events including orgasms, regardless of level

    // ============================================
    // PERSISTENT NON-CONSENT CONTEXT
    // ============================================
    // When forced_scene is true, the NPC refused but the scene continued.
    // Re-inject the non-consent/unwilling context on EVERY scene change
    // so the NPC consistently expresses being unwilling throughout.
    // ============================================
    if (!empty($intimacyStatus["forced_scene"])) {
        $partnerName = $GLOBALS["PLAYER_NAME"] ?? "them";
        if (is_array($intimacyStatus["scene_actors"] ?? null)) {
            foreach ($intimacyStatus["scene_actors"] as $actor) {
                if (strtolower($actor) !== strtolower($actorName)) {
                    $partnerName = $actor;
                    break;
                }
            }
        }

        if (isNonConsentPromptEnabled()) {
            $nonConsentPrompt = NsfwData::getPrompt('non_consent');
            if (!empty($nonConsentPrompt)) {
                $nonConsentPrompt = str_replace('#PARTNER#', $partnerName, $nonConsentPrompt);
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n<non_consent_context>\n{$nonConsentPrompt}\n</non_consent_context>";
            }
        }
        error_log("[AIAGENTNSFW] Persistent non-consent context injected for $actorName (forced scene, every scene change)");
    }

    // ============================================
    // NPC TYPE SPECIFIC PROMPTS - PERSONALITY ONLY
    // ============================================
    // NOTE: setSexPrompt() and setSexSpeechStyle() are called ONCE
    // when scene is first accepted (lines 674-696 above).
    // DO NOT call them here - this block runs on EVERY event.
    // Only add slave-specific personality text here.
    // ============================================
    if ($isSlave && empty($slavePromptAlreadyInjected)) {
        // SLAVE: Add slave-specific personality and speech style
        // (skipped if already injected by accepted phase handler above)
        $slavePersonality = NsfwRelationship::getSlavePersonality($slaveAffinity, $slaveOwnerName);
        $slaveSpeech = NsfwRelationship::getSlaveSpeechStyle($slaveAffinity, $slaveOwnerName);
        if (!empty($slavePersonality)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . NsfwRelationship::wrapXml('slave_personality', $slavePersonality);
        }
        if (!empty($slaveSpeech)) {
            $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n" . NsfwRelationship::wrapXml('slave_speech', $slaveSpeech);
        }
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

// Determine if we should inject full speak style
// When arousal gating is ON: needs level 2 AND engaged phase
// When arousal gating is OFF: just needs engaged phase (no level check)
// SKIP if in pillow talk mode (scene just ended)
$arousalGatingEnabled = isSexDisposalEnabled();
$sceneActorsArray = $intimacyStatus["scene_actors"] ?? [];
$hasActiveScene = !empty($sceneActorsArray) && count($sceneActorsArray) > 0;
$isEngagedPhase = ($intimacyStatus["scene_phase"] ?? '') === "engaged";

$shouldInjectEngagedContent = !$isPillowTalkMode && (
    ($arousalGatingEnabled && $intimacyStatus["level"] == 2 && $isEngagedPhase)
    || (!$arousalGatingEnabled && $isEngagedPhase)
);

if ($shouldInjectEngagedContent) {
    if (!$arousalGatingEnabled) {
        error_log("[AIAGENTNSFW] Phase: engaged (arousal gating OFF - phase only) for $actorName");
    } else {
        error_log("[AIAGENTNSFW] Phase: engaged (level 2) for $actorName");
    }

    // SLAVE SCENE CUES at Level 2
    if ($isSlave) {
        $slaveSceneCues = NsfwRelationship::getSlaveSceneCues($slaveAffinity, $slaveOwnerName);
        if (!empty($slaveSceneCues)) {
            // Override chatnf_sl cues with slave-specific cues
            $GLOBALS["PROMPTS"]["chatnf_sl"]["cue"] = ["<response_instruction>\n{$slaveSceneCues}\n</response_instruction> " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")];
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
        } else if (!empty($intimacyStatus["is_transaction"])) {
            $serviceContext .= "This is a business transaction - ensure you get paid for your services. ";
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
    // DEBUG: Log what we have for this NPC
    error_log("[AIAGENTNSFW] DEBUG speak style for {$GLOBALS["HERIKA_NAME"]}: sex_speech_style=" . ($extended_data["sex_speech_style"] ?? 'NOT SET'));

    if (isset($extended_data["sex_speech_style"]) && !empty($extended_data["sex_speech_style"]) && $extended_data["sex_speech_style"] !== 'auto') {
        require_once __DIR__ . "/nsfw_data.php";

        // Get FULL speak style data (includes content, climax_prompt, pillow_talk_prompt, etc.)
        $speakStyleData = NsfwData::getSpeakStyle($extended_data["sex_speech_style"]);
        $styleContent = $speakStyleData['content'] ?? '';

        error_log("[AIAGENTNSFW] DEBUG getSpeakStyle('{$extended_data["sex_speech_style"]}') returned: content=" . (empty($styleContent) ? 'EMPTY' : strlen($styleContent) . ' chars') . ", climax_prompt=" . (!empty($speakStyleData['climax_prompt']) ? 'SET' : 'NOT SET'));

        // Fallback to file if not in JSONB
        if (empty($styleContent)) {
            $styleFile = __DIR__ . "/speakStyles/" . $extended_data["sex_speech_style"] . ".txt";
            if (file_exists($styleFile)) {
                $styleContent = file_get_contents($styleFile);
                error_log("[AIAGENTNSFW] DEBUG loaded from file: $styleFile");
            } else {
                error_log("[AIAGENTNSFW] DEBUG file not found: $styleFile");
            }
        }

        if (!empty($styleContent)) {
            // Override the chatnf_sl cue with the NPC's speak style
            $styledCue = "<response_instruction>\n{$styleContent}\n</response_instruction> " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
            $GLOBALS["PROMPTS"]["chatnf_sl"]["cue"] = [$styledCue];

            // For chatnf_sl_climax (legacy climax event), use the specific climax_prompt if available
            // NOTE: ext_nsfw_orgasm is handled ENTIRELY by nsfw_ostim_handler.php which knows
            // whether the NPC is orgasming (uses climax_prompt) or reacting to partner (uses partner_climax_prompt)
            // DO NOT touch ext_nsfw_orgasm here - it will overwrite the handler's correct prompt!
            $climaxPrompt = $speakStyleData['climax_prompt'] ?? '';
            if (!empty($climaxPrompt)) {
                $climaxPrompt = str_replace('#NPC_NAME#', $GLOBALS["HERIKA_NAME"] ?? '', $climaxPrompt);
                $climaxCue = "<response_instruction>\n{$climaxPrompt}\n</response_instruction> " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
                $GLOBALS["PROMPTS"]["chatnf_sl_climax"]["cue"] = [$climaxCue];
                $GLOBALS["PROMPTS"]["ext_nsfw_npc_orgasm"]["cue"] = [$climaxCue];
                error_log("[AIAGENTNSFW] Overriding chatnf_sl_climax with climax_prompt for {$GLOBALS["HERIKA_NAME"]}");
            } else {
                // No specific climax_prompt - use general speak style
                $GLOBALS["PROMPTS"]["chatnf_sl_climax"]["cue"] = [$styledCue];
                $GLOBALS["PROMPTS"]["ext_nsfw_npc_orgasm"]["cue"] = [$styledCue];
            }

            error_log("[AIAGENTNSFW] Overriding chatnf_sl with speak style '{$extended_data["sex_speech_style"]}' for {$GLOBALS["HERIKA_NAME"]}");
        } else {
            error_log("[AIAGENTNSFW] WARNING: NPC has sex_speech_style='{$extended_data["sex_speech_style"]}' but content is empty!");
        }
    } else {
        error_log("[AIAGENTNSFW] DEBUG: NPC {$GLOBALS["HERIKA_NAME"]} has no configured sex_speech_style - using defaults");
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

// ============================================
// INJECT CURRENT SCENE DESCRIPTION — FOR ALL EVENTS
// ============================================
// Runs OUTSIDE the level-gated block so orgasms, climax events, and all
// scene-related events get the scene context. Without this, the model
// has no idea what position/animation is happening or who the partners are.
// ============================================
$storedSceneDesc = $intimacyStatus["current_scene_desc"] ?? null;
$storedSceneTags = $intimacyStatus["current_scene_tags"] ?? [];
$storedSceneActors = $intimacyStatus["scene_actors"] ?? [];
$storedPrimaryPartner = $intimacyStatus["current_primary_partner"] ?? null;
if (!empty($storedSceneDesc) && !empty($storedSceneActors) && !$isPillowTalkMode) {
    $partnerNames = array_filter($storedSceneActors, function($a) { return strtolower($a) !== strtolower($GLOBALS["PLAYER_NAME"]); });
    // Highlight primary partner from OStim's actor ordering
    if ($storedPrimaryPartner && count($partnerNames) > 1) {
        $otherPartners = array_filter($partnerNames, function($a) use ($storedPrimaryPartner) { return $a !== $storedPrimaryPartner; });
        $partnerStr = "$storedPrimaryPartner (primary) and " . implode(", ", $otherPartners);
    } else {
        $partnerStr = $storedPrimaryPartner ?? (!empty($partnerNames) ? implode(" and ", $partnerNames) : "partner");
    }
    $sceneDescBlock = "<current_scene>\nINTIMATE SCENE with $partnerStr: $storedSceneDesc";
    if (!empty($storedSceneTags)) {
        $sceneDescBlock .= "\nScene tags: " . implode(", ", $storedSceneTags);
    }
    $sceneDescBlock .= "\n</current_scene>";
    $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $sceneDescBlock;
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

// ============================================
// XTTS VOICE MODIFICATIONS
// ============================================
// Work independently of arousal gating - tied to scene presence, not arousal levels.
// When arousal gating is ON: Level 2 = in scene, Level 1 = foreplay
// When arousal gating is OFF: Any scene event = "in scene" (no foreplay phase exists)
// ============================================
$xttsInScene = isSexDisposalEnabled() ? ($intimacyStatus["level"] == 2) : $isSceneEvent;
$xttsInForeplay = isSexDisposalEnabled() ? ($intimacyStatus["level"] == 1) : false;  // Foreplay only exists with arousal gating

// XTTS Level 2 = In active sex scene (moans, speed changes)
if ($xttsInScene && $xttsLevel2Enabled) {
    $moansActive = $enableRandomMoans && $npcAffinityMeetsMoanThreshold;
    error_log("Adding XTTS hook (in scene) (XTTS_MODIFY_LEVEL2 enabled, speed: {$xttsSpeedLevel2}, random moans: " . ($moansActive ? 'ON' : 'OFF - affinity too low') . ")");

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
        apply_tts_settings(["temperature"=>1,"speed"=>$speed,"enable_text_splitting"=>false,"top_p"=> 1,"top_k"=>100],true);
        return $result;

    };
} else if ($xttsInScene && !$xttsLevel2Enabled) {
    error_log("[AIAGENTNSFW] XTTS hook (in scene) DISABLED by settings");
}

// XTTS Level 1 = Foreplay (slower voice, no moans) - ONLY applies when arousal gating is ON
if ($xttsInForeplay && $xttsLevel1Enabled) {
    error_log("Adding XTTS hook (foreplay) (XTTS_MODIFY_LEVEL1 enabled, speed: {$xttsSpeedLevel1})");
    $GLOBALS["AIAGENTNSFW_XTTS_SPEED_LEVEL1"] = $xttsSpeedLevel1;

    $GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"][]=function($text) {
        $speed = $GLOBALS["AIAGENTNSFW_XTTS_SPEED_LEVEL1"] ?? 0.8;
        Logger::info("Applying speed modifier for XTTS (speed: {$speed}) $text => $text ".__FILE__);

        apply_tts_settings(["temperature"=>1,"speed"=>$speed,"enable_text_splitting"=>false,"top_p"=> 1,"top_k"=>100],true);
        return $text;

    };
} else if ($xttsInForeplay && !$xttsLevel1Enabled) {
    error_log("[AIAGENTNSFW] XTTS hook (foreplay) DISABLED by settings");
}

// ============================================
// RESET XTTS SPEED when not in a scene
// ============================================
// This ensures XTTS goes back to normal speed after a scene ends.
// Without this, the slow speed from the previous scene persists.
// When arousal gating is ON: level 0 means not in scene
// When arousal gating is OFF: not being in a scene event means not in scene
// ============================================
$xttsNotInScene = isSexDisposalEnabled() ? ($intimacyStatus["level"] == 0) : !$isSceneEvent;
if ($xttsNotInScene) {
    // Add a hook that resets speed to normal (1.0)
    $GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"][] = function($text) {
        // Reset to normal TTS settings
        apply_tts_settings(["temperature" => 0.75, "speed" => 1.0, "enable_text_splitting" => true, "top_p" => 0.85, "top_k" => 50], true);
        return $text;
    };
}


?>