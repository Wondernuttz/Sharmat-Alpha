<?php
/**
 * NSFW Function Definitions
 * This file defines the FUNCTIONS array entries for NSFW actions.
 * Helper functions are in helpers.php (loaded separately to avoid early-load issues).
 */

require_once(__DIR__."/common.php");

// Load NSFW prompts AFTER core prompts (TEMPLATE_DIALOG is now available)
require_once(__DIR__."/prompts.php");

// Helper functions (isSexDisposalEnabled, etc.) are now in helpers.php
// which is loaded by common.php before this file runs

// Change default actors name, so descriptions can use NPC name.
if ($GLOBALS["HERIKA_NAME"]=="The Narrator") {
    $GLOBALS["HERIKA_NAME"]="Character";
}

// DEBUG: Log that we're defining NSFW functions
error_log("[AIAGENTNSFW-FUNCS] === DEFINING NSFW FUNCTION DEFINITIONS ===");

/******
ExtCmdHug
******/
$GLOBALS["F_NAMES"]["ExtCmdHug"]="GiveHug";                           
$GLOBALS["F_TRANSLATIONS"]["ExtCmdHug"]="Gives a hug,squeeze,embrace to {$GLOBALS["PLAYER_NAME"]}";
$GLOBALS["FUNCTIONS"][] =
    [
        "name" => $GLOBALS["F_NAMES"]["ExtCmdHug"],
        "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdHug"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "Target NPC, Actor, or being",
                ]
            ],
            "required" => ["target"],
        ],
    ]
;


$GLOBALS["FUNCSERV"]["ExtCmdHug"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    aiagentNsfwStampAffection($GLOBALS["HERIKA_NAME"]); // affection anti-spam throttle
    aiagentNsfwInstantCrush($GLOBALS["HERIKA_NAME"], 'affection', $gameRequest[3] ?? '');
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
 
    // Participants
    $functionCallRet = explode("@", $gameRequest[3]); // Function returns here
    // Update intimacy status for al lparticipants

    // Actor who issued command
    $actors[]=npcNameToCodename($GLOBALS["HERIKA_NAME"]);;
    $actorList=explode(",",$functionCallRet[2]);
    foreach($actorList as $actor) {
        if (trim($actor)!=$GLOBALS["PLAYER_NAME"])
            $actors[]=($actor);

    }

    foreach ($actors as $actorName) {

        $intimacyStatus=getIntimacyForActor($actorName);
        if (isSexDisposalEnabled()) {
            $intimacyStatus["sex_disposal"]+=(int)aiagentNsfwArousalNum('AROUSAL_GAIN_AFFECTION', 1); // affection: small arousal, builds over time
        }
        //$intimacyStatus["level"]=0;
        updateIntimacyForActor($actorName,$intimacyStatus);

    }

    $GLOBALS["AVOID_LLM_CALL"]=true;

 };

/******
ExtCmdHoldHands
******/
$GLOBALS["F_NAMES"]["ExtCmdHoldHands"]="HoldHands";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdHoldHands"]="{$GLOBALS["HERIKA_NAME"]} holds the target actor's hand in a non-sexual affectionate gesture";
$GLOBALS["FUNCTIONS"][] =
    [
        "name" => $GLOBALS["F_NAMES"]["ExtCmdHoldHands"],
        "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdHoldHands"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "Target NPC, Actor, or being",
                ]
            ],
            "required" => ["target"],
        ],
    ]
;


$GLOBALS["FUNCSERV"]["ExtCmdHoldHands"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    aiagentNsfwStampAffection($GLOBALS["HERIKA_NAME"]); // affection anti-spam throttle
    aiagentNsfwInstantCrush($GLOBALS["HERIKA_NAME"], 'affection', $gameRequest[3] ?? '');

    $functionCallRet = explode("@", $gameRequest[3]);
    $actors[]=npcNameToCodename($GLOBALS["HERIKA_NAME"]);
    $actorList=explode(",",$functionCallRet[2] ?? "");
    foreach($actorList as $actor) {
        if (trim($actor)!="" && trim($actor)!=$GLOBALS["PLAYER_NAME"])
            $actors[]=($actor);

    }

    foreach ($actors as $actorName) {

        $intimacyStatus=getIntimacyForActor($actorName);
        if (isSexDisposalEnabled()) {
            $intimacyStatus["sex_disposal"]+=(int)aiagentNsfwArousalNum('AROUSAL_GAIN_AFFECTION', 1);
        }
        updateIntimacyForActor($actorName,$intimacyStatus);

    }

    $GLOBALS["AVOID_LLM_CALL"]=true;

 };

/******
ExtCmdRemoveClothes
******/

$GLOBALS["F_NAMES"]["ExtCmdRemoveClothes"]="RemoveClothes";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdRemoveClothes"]="{$GLOBALS["HERIKA_NAME"]} removes worn clothes. ";
$GLOBALS["FUNCTIONS"][] =
    [
        "name" => $GLOBALS["F_NAMES"]["ExtCmdRemoveClothes"],
        "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdRemoveClothes"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "Keep it blank",
                ]
            ],
            "required" => []
        ],
    ]
;
error_log("[AIAGENTNSFW-FUNCS] Added RemoveClothes to FUNCTIONS, current count=" . count($GLOBALS["FUNCTIONS"]));

$GLOBALS["FUNCSERV"]["ExtCmdRemoveClothes"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdRemoveClothes FUNCSERV");
    
    $intimacyStatus=getIntimacyForActor($GLOBALS["HERIKA_NAME"]);
    if (isSexDisposalEnabled()) {
        $intimacyStatus["sex_disposal"]+=(int)aiagentNsfwArousalNum('AROUSAL_GAIN_UNDRESS', 6); // undress: strong push, not an instant accept-sex unlock
    }
    $intimacyStatus["is_naked"]=1;  // Track naked state always (informational)
    updateIntimacyForActor($GLOBALS["HERIKA_NAME"],$intimacyStatus);
    $GLOBALS["AVOID_LLM_CALL"]=true;


/*
    if (isset($frResponse["argName"]))
    $argName = $frResponse["argName"];
if (isset($frResponse["request"]))
    $request = $frResponse["request"];
if (isset($frResponse["useFunctionsAgain"]))
    $useFunctionsAgain = $frResponse["useFunctionsAgain"];
*/
};

/******
ExtCmdStartSex
******/

$GLOBALS["F_NAMES"]["ExtCmdStartSex"]="MakeLove";                           
$GLOBALS["F_TRANSLATIONS"]["ExtCmdStartSex"]="{$GLOBALS["HERIKA_NAME"]} starts an intimate scene with another actor";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdStartSex"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdStartSex"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "target" => [
                "type" => "string",
                "description" => "Target NPC, Actor, or being",
            ]
        ],
        "required" => ["target"],
    ]
]
;


// "She started it = consent" (user directive 2026-06-28, PLAYER-NPC scenes ONLY). An NPC who chooses to initiate
// a sex act via her own Start* tool IS giving consent - so accepted_sex unlocks immediately (speak style + kinks)
// and stays sticky for the rest of the scene; she is never parked at the player-initiated consent gate. NPC-to-NPC
// scenes have their own separate logic and are deliberately untouched. Prostitutes keep their payment gate; slaves
// are forced upstream. Called from every player-facing sex-initiation FUNCSERV below.
if (!function_exists('aiagentNsfwMarkNpcInitiatedSexConsent')) {
    function aiagentNsfwMarkNpcInitiatedSexConsent($npcName, $targetName) {
        if (function_exists('nsfwIsNarratorName') && nsfwIsNarratorName($targetName)) {
            $targetName = $GLOBALS["PLAYER_NAME"] ?? $targetName;
        }
        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";
        if (strcasecmp((string)$targetName, (string)$playerName) !== 0) { return; }     // PLAYER-NPC ONLY; NPC-NPC separate
        $st = getIntimacyForActor($npcName);
        if (!empty($st["is_npc_scene"])) { return; }                                   // NPC-to-NPC = separate logic
        if (function_exists('isProstitute') && isProstitute($npcName)) { return; }      // payment gate owns this
        $st["accepted_sex"] = true;
        $st["scene_phase"] = "accepted";
        $st["refusal_expressed"] = false;
        $st["refused_until_scene_end"] = false;
        $st["request_scene_stop"] = false;
        $st["forced_scene"] = false;
        $st["last_accept_sex_time"] = time();
        $st["last_accept_sex_partner"] = $targetName;
        $st["scene_type"] = "personal";
        $aff = function_exists('getNpcAffinity') ? (int)getNpcAffinity($npcName, $targetName) : 0;
        if (!(function_exists('isNpcSlave') && isNpcSlave($npcName))) {
            $st["show_normal_kinks"] = ($aff >= 56);
            $st["show_secret_kinks"] = ($aff >= 76);
        }
        updateIntimacyForActor($npcName, $st);
        error_log("[AIAGENTNSFW] NPC-initiated sex -> auto-accept (she started it = consent) for {$npcName} with {$targetName} (aff={$aff})");
    }
}
// Convenience wrapper: resolve the NPC (HERIKA_NAME) + target from the current gameRequest payload and mark
// NPC-initiated consent. Call this from each sex-initiation FUNCSERV with one identical line.
if (!function_exists('aiagentNsfwMarkNpcInitiatedSexConsentFromRequest')) {
    function aiagentNsfwMarkNpcInitiatedSexConsentFromRequest() {
        $gr = $GLOBALS["gameRequest"] ?? [];
        $parts = explode("@", (string)($gr[3] ?? ""));
        $target = trim((string)(explode(",", (string)($parts[2] ?? ""))[0] ?? ""));
        if ($target === "") { $target = $GLOBALS["PLAYER_NAME"] ?? "Player"; }
        aiagentNsfwMarkNpcInitiatedSexConsent((string)($GLOBALS["HERIKA_NAME"] ?? ""), $target);
    }
}

$GLOBALS["FUNCSERV"]["ExtCmdStartSex"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdStartSex FUNCSERV");

    // Participants
    $functionCallRet = explode("@", $gameRequest[3]);
    $npcName = $GLOBALS["HERIKA_NAME"];

    // Get target name from function call
    $targetName = $functionCallRet[2] ?? $GLOBALS["PLAYER_NAME"];
    // Clean up target name (take first actor if multiple)
    $targetParts = explode(",", $targetName);
    $primaryTarget = trim($targetParts[0]);
    if (empty($primaryTarget)) {
        $primaryTarget = $GLOBALS["PLAYER_NAME"];
    }

    // Affinity check - only when affinity gating is enabled
    if (isAffinityGatingEnabled()) {
        $affinity = getNpcAffinity($npcName, $primaryTarget);
        $isProstitute = isProstitute($npcName);
        $isSlave = isNpcSlave($npcName);

        // NO hardcoded affinity gate on initiating sex - consent is PROMPT-DRIVEN (the model's AcceptSex /
        // RefuseSex, guided by the relationship tier prompt). The affinity number only selects which PROMPT is
        // shown, never whether sex can start. Structural exceptions (slave forced / prostitute payment) are
        // enforced downstream, not here.
        error_log("[StartSex] {$npcName} affinity {$affinity} with {$primaryTarget} - no hard gate (prompt-driven consent)");
    }

    // Actor who issued command
    $actors = [npcNameToCodename($npcName)];
    $actorList = explode(",", $functionCallRet[2]);
    foreach ($actorList as $actor) {
        if (trim($actor) != $GLOBALS["PLAYER_NAME"]) {
            $actors[] = trim($actor);
        }
    }

    foreach ($actors as $actorName) {
        $intimacyStatus = getIntimacyForActor($actorName);
        if (isSexDisposalEnabled()) {
            $intimacyStatus["sex_disposal"] += (int)aiagentNsfwArousalNum('AROUSAL_GAIN_SEXACT', 15);
        }
        $intimacyStatus["level"] = 2; // full sex act -> active-sex level (matches blowjob/anal)
        updateIntimacyForActor($actorName, $intimacyStatus);
    }

    aiagentNsfwMarkNpcInitiatedSexConsent($npcName, $primaryTarget); // she started it = consent

    $GLOBALS["AVOID_LLM_CALL"] = true;
};

/******
ExtCmdStartBlowJob
******/

$GLOBALS["F_NAMES"]["ExtCmdStartBlowJob"]="GiveOralSex";                           
$GLOBALS["F_TRANSLATIONS"]["ExtCmdStartBlowJob"]="{$GLOBALS["HERIKA_NAME"]} starts an intimate scene with another actor, giving oral sex";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdStartBlowJob"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdStartBlowJob"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "target" => [
                "type" => "string",
                "description" => "Target NPC, Actor, or being",
            ]
        ],
        "required" => ["target"],
    ]
]
;


$GLOBALS["FUNCSERV"]["ExtCmdStartBlowJob"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdStartBlowJob FUNCSERV");
    aiagentNsfwMarkNpcInitiatedSexConsentFromRequest(); // she started it = consent (PLAYER-NPC only)
  
    // Participants
    $functionCallRet = explode("@", $gameRequest[3]); // Function returns here
    // Update intimacy status for al lparticipants

    // Actor who issued command
    $actors[]=npcNameToCodename($GLOBALS["HERIKA_NAME"]);;
    $actorList=explode(",",$functionCallRet[2]);
    foreach($actorList as $actor) {
        if (trim($actor)!=$GLOBALS["PLAYER_NAME"])
            $actors[]=($actor);

    }

    foreach ($actors as $actorName) {

        $intimacyStatus=getIntimacyForActor($actorName);
        if (isSexDisposalEnabled()) {
            $intimacyStatus["sex_disposal"]+=(int)aiagentNsfwArousalNum('AROUSAL_GAIN_SEXACT', 15);
        }
        $intimacyStatus["level"]=2;
        updateIntimacyForActor($actorName,$intimacyStatus);

    }

    $GLOBALS["AVOID_LLM_CALL"]=true; // Don't speak
};


/******
ExtCmdStartAnalSex
******/

$GLOBALS["F_NAMES"]["ExtCmdStartAnalSex"]="StartAnalSex";                           
$GLOBALS["F_TRANSLATIONS"]["ExtCmdStartAnalSex"]="{$GLOBALS["HERIKA_NAME"]} starts an intimate scene with another actor, anal sex";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdStartAnalSex"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdStartAnalSex"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "target" => [
                "type" => "string",
                "description" => "Target NPC, Actor, or being",
            ]
        ],
        "required" => ["target"],
    ]
]
;


$GLOBALS["FUNCSERV"]["ExtCmdStartAnalSex"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdStartAnalSex FUNCSERV");
    aiagentNsfwMarkNpcInitiatedSexConsentFromRequest(); // she started it = consent (PLAYER-NPC only)
  
    // Participants
    $functionCallRet = explode("@", $gameRequest[3]); // Function returns here
    // Update intimacy status for al lparticipants

    // Actor who issued command
    $actors[]=npcNameToCodename($GLOBALS["HERIKA_NAME"]);;
    $actorList=explode(",",$functionCallRet[2]);
    foreach($actorList as $actor) {
        if (trim($actor)!=$GLOBALS["PLAYER_NAME"])
            $actors[]=($actor);

    }

    foreach ($actors as $actorName) {

        $intimacyStatus=getIntimacyForActor($actorName);
        if (isSexDisposalEnabled()) {
            $intimacyStatus["sex_disposal"]+=(int)aiagentNsfwArousalNum('AROUSAL_GAIN_SEXACT', 15);
        }
        $intimacyStatus["level"]=2;
        updateIntimacyForActor($actorName,$intimacyStatus);

    }

};


/******
ExtCmdStartMassage
******/

$GLOBALS["F_NAMES"]["ExtCmdStartMassage"]="GiveMassage";                           
$GLOBALS["F_TRANSLATIONS"]["ExtCmdStartMassage"]="{$GLOBALS["HERIKA_NAME"]} receives a massage from {$GLOBALS["PLAYER_NAME"]}. You must issue this action to allow starting a massage or if you want one.";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdStartMassage"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdStartMassage"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "target" => [
                "type" => "string",
                "description" => "Target NPC, Actor, or being",
            ]
        ],
        "required" => ["target"],
    ]
]
;


$GLOBALS["FUNCSERV"]["ExtCmdStartMassage"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdStartMassage FUNCSERV");
   
    // Participants
    $functionCallRet = explode("@", $gameRequest[3]); // Function returns here
    // Update intimacy status for al lparticipants

    // Actor who issued command
    $actors[]=npcNameToCodename($GLOBALS["HERIKA_NAME"]);;
    $actorList=explode(",",$functionCallRet[2]);
    foreach($actorList as $actor) {
        if (trim($actor)!=$GLOBALS["PLAYER_NAME"])
            $actors[]=($actor);

    }

    foreach ($actors as $actorName) {

        $intimacyStatus=getIntimacyForActor($actorName);
        if (isSexDisposalEnabled()) {
            $intimacyStatus["sex_disposal"]+=(int)aiagentNsfwArousalNum('AROUSAL_GAIN_MASSAGE', 5); // massage: sensual, lower arousal than full sex
        }
        $intimacyStatus["level"]=1;
        updateIntimacyForActor($actorName,$intimacyStatus);

    }

};

/******
ExtCmdStartThreesome
******/

$GLOBALS["F_NAMES"]["ExtCmdStartThreesome"]="StartThreesome";                           
$GLOBALS["F_TRANSLATIONS"]["ExtCmdStartThreesome"]="{$GLOBALS["HERIKA_NAME"]} starts a sex scene, (put partners in target property, comma separated)";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdStartThreesome"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdStartThreesome"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "target" => [
                "type" => "string",
                "description" => "involved partners",
            ]
        ],
        "required" => ["target"],
    ]
]
;


$GLOBALS["FUNCSERV"]["ExtCmdStartThreesome"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdStartThreesome FUNCSERV, status {$gameRequest[3]}");
    aiagentNsfwMarkNpcInitiatedSexConsentFromRequest(); // she started it = consent (PLAYER-NPC only)

    // Participants
    $functionCallRet = explode("@", $gameRequest[3]); // Function returns here
    // Update intimacy status for al lparticipants

    // Actor who issued command
    $actors[]=npcNameToCodename($GLOBALS["HERIKA_NAME"]);;
    $actorList=explode(",",$functionCallRet[2]);
    foreach($actorList as $actor) {
        if (trim($actor)!=$GLOBALS["PLAYER_NAME"])
            $actors[]=($actor);

    }

    foreach ($actors as $actorName) {

        $intimacyStatus=getIntimacyForActor($actorName);
        if (isSexDisposalEnabled()) {
            $intimacyStatus["sex_disposal"]+=(int)aiagentNsfwArousalNum('AROUSAL_GAIN_SEXACT', 15);
        }
        $intimacyStatus["level"]=2; // threesome: full sex act -> active-sex level
        updateIntimacyForActor($actorName,$intimacyStatus);

    }


    $GLOBALS["AVOID_LLM_CALL"]=true;

};


/******
ExtCmdStartTitfuck
******/

$GLOBALS["F_NAMES"]["ExtCmdStartHandJobSex"]="Masturbate";                           
$GLOBALS["F_TRANSLATIONS"]["ExtCmdStartHandJobSex"]="{$GLOBALS["HERIKA_NAME"]} masturbates target";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdStartHandJobSex"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdStartHandJobSex"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "target" => [
                "type" => "string",
                "description" => "Target NPC, Actor, or being",
            ]
        ],
        "required" => ["target"],
    ]
]
;


$GLOBALS["FUNCSERV"]["ExtCmdStartHandJobSex"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdStartHandJobSex FUNCSERV");
    aiagentNsfwMarkNpcInitiatedSexConsentFromRequest(); // she started it = consent (PLAYER-NPC only)

    // Participants
    $functionCallRet = explode("@", $gameRequest[3]); // Function returns here
    // Update intimacy status for al lparticipants

    // Actor who issued command
    $actors[]=npcNameToCodename($GLOBALS["HERIKA_NAME"]);;
    $actorList=explode(",",$functionCallRet[2]);
    foreach($actorList as $actor) {
        if (trim($actor)!=$GLOBALS["PLAYER_NAME"])
            $actors[]=($actor);

    }

    foreach ($actors as $actorName) {

        $intimacyStatus=getIntimacyForActor($actorName);
        if (isSexDisposalEnabled()) {
            $intimacyStatus["sex_disposal"]+=(int)aiagentNsfwArousalNum('AROUSAL_GAIN_SEXACT', 15);
        }
        $intimacyStatus["level"]=1;
        updateIntimacyForActor($actorName,$intimacyStatus);

    }


    $GLOBALS["AVOID_LLM_CALL"]=false;

};

/******
ExtCmdStartTitfuck
******/

$GLOBALS["F_NAMES"]["ExtCmdStartTitfuck"]="StartBoobjob";                           
$GLOBALS["F_TRANSLATIONS"]["ExtCmdStartTitfuck"]="{$GLOBALS["HERIKA_NAME"]} starts a sex scene using her breasts. (aka  Titfuck,boobjob,titjob,paizuri ) (put partner in target poperty)";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdStartTitfuck"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdStartTitfuck"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "target" => [
                "type" => "string",
                "description" => "Target NPC, Actor, or being",
            ]
        ],
        "required" => ["target"],
    ]
]
;


$GLOBALS["FUNCSERV"]["ExtCmdStartTitfuck"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdStartTitfuck FUNCSERV");
    aiagentNsfwMarkNpcInitiatedSexConsentFromRequest(); // she started it = consent (PLAYER-NPC only)

    // Participants
    $functionCallRet = explode("@", $gameRequest[3]); // Function returns here
    // Update intimacy status for al lparticipants

    // Actor who issued command
    $actors[]=npcNameToCodename($GLOBALS["HERIKA_NAME"]);;
    $actorList=explode(",",$functionCallRet[2]);
    foreach($actorList as $actor) {
        if (trim($actor)!=$GLOBALS["PLAYER_NAME"])
            $actors[]=($actor);

    }

    foreach ($actors as $actorName) {

        $intimacyStatus=getIntimacyForActor($actorName);
        if (isSexDisposalEnabled()) {
            $intimacyStatus["sex_disposal"]+=(int)aiagentNsfwArousalNum('AROUSAL_GAIN_SEXACT', 15);
        }
        $intimacyStatus["level"]=1;
        updateIntimacyForActor($actorName,$intimacyStatus);

    }


    $GLOBALS["AVOID_LLM_CALL"]=true;

};


/******
ExtCmdDrinkBloodSex - VAMPIRE-ONLY intimate blood-feeding scene (user directive 2026-07-01). Gated to vampire NPCs
(aiagentNsfwIsVampireNpc) under the SAME conditions as sex scenes. Routes through MinAI's existing vampire-bite scene
(minai_Sex -vampirebite- / OStim "vampirebite" tag; SexLab vampire-feed tags) via the SceneEngine "bloodfeed" act.
******/

$GLOBALS["F_NAMES"]["ExtCmdDrinkBloodSex"]="DrinkBlood";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdDrinkBloodSex"]="{$GLOBALS["HERIKA_NAME"]} sinks her fangs into the target's neck in an intimate, erotic blood-feeding scene. VAMPIRE ONLY - initiates the blood-drinking scene with the target (put partner in target property).";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdDrinkBloodSex"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdDrinkBloodSex"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "target" => [
                "type" => "string",
                "description" => "Target to feed on",
            ]
        ],
        "required" => ["target"],
    ]
]
;


$GLOBALS["FUNCSERV"]["ExtCmdDrinkBloodSex"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdDrinkBloodSex FUNCSERV");
    aiagentNsfwMarkNpcInitiatedSexConsentFromRequest(); // she started it = consent (PLAYER-NPC only)

    // Participants
    $functionCallRet = explode("@", $gameRequest[3]);
    $actors[]=npcNameToCodename($GLOBALS["HERIKA_NAME"]);
    $actorList=explode(",",$functionCallRet[2]);
    foreach($actorList as $actor) {
        if (trim($actor)!=$GLOBALS["PLAYER_NAME"])
            $actors[]=($actor);
    }

    foreach ($actors as $actorName) {
        $intimacyStatus=getIntimacyForActor($actorName);
        if (isSexDisposalEnabled()) {
            $intimacyStatus["sex_disposal"]+=(int)aiagentNsfwArousalNum('AROUSAL_GAIN_SEXACT', 15);
        }
        $intimacyStatus["level"]=1;
        updateIntimacyForActor($actorName,$intimacyStatus);
    }

    $GLOBALS["AVOID_LLM_CALL"]=true;

};


/******
ExtCmdStartSelfMasturbation
******/

$GLOBALS["F_NAMES"]["ExtCmdStartSelfMasturbation"]="StartSelfMasturbation";                           
$GLOBALS["F_TRANSLATIONS"]["ExtCmdStartSelfMasturbation"]="{$GLOBALS["HERIKA_NAME"]} starts self masturbation";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdStartSelfMasturbation"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdStartSelfMasturbation"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "target" => [
                "type" => "string",
                // self-directed: requiring a target made strict cores DROP the call when the model
                // (correctly) sent none - same disease as the PutOnClothes redress drop.
                "description" => "Keep it blank",
            ]
        ],
        "required" => [],
    ]
]
;


$GLOBALS["FUNCSERV"]["ExtCmdStartSelfMasturbation"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdStartSelfMasturbation FUNCSERV");
   
    $intimacyStatus=getIntimacyForActor($GLOBALS["HERIKA_NAME"]);
    if (isSexDisposalEnabled()) {
        $intimacyStatus["sex_disposal"]+=(int)aiagentNsfwArousalNum('AROUSAL_GAIN_MASSAGE', 5);
    }
    $intimacyStatus["level"]=1;
    updateIntimacyForActor($GLOBALS["HERIKA_NAME"],$intimacyStatus);

    $GLOBALS["AVOID_LLM_CALL"]=false;

};



/******
ExtCdmKiss
******/

$GLOBALS["F_NAMES"]["ExtCmdKiss"]="Kiss";                           
$GLOBALS["F_TRANSLATIONS"]["ExtCmdKiss"]="{$GLOBALS["HERIKA_NAME"]} kisses target actor";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdKiss"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdKiss"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "target" => [
                "type" => "string",
                "description" => "Target NPC, Actor, or being",
            ]
        ],
        "required" => ["target"],
    ]
]
;


$GLOBALS["FUNCSERV"]["ExtCmdKiss"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    aiagentNsfwStampAffection($GLOBALS["HERIKA_NAME"]); // affection anti-spam throttle
    aiagentNsfwInstantCrush($GLOBALS["HERIKA_NAME"], 'affection', $gameRequest[3] ?? '');
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdKiss FUNCSERV");
   
    $functionCallRet = explode("@", $gameRequest[3]); // Function returns here
    // Update intimacy status for al lparticipants

    // Actor who issued command
    $actors[]=($GLOBALS["HERIKA_NAME"]);;
    $actorList=explode(",",$functionCallRet[2]);
    foreach($actorList as $actor) {
        if (trim($actor)!=$GLOBALS["PLAYER_NAME"])
            $actors[]=($actor);

    }

    foreach ($actors as $actorName) {

        $intimacyStatus=getIntimacyForActor($actorName);
        if (isSexDisposalEnabled()) {
            $intimacyStatus["sex_disposal"]+=(int)aiagentNsfwArousalNum('AROUSAL_GAIN_AFFECTION', 1) * 2; // kiss: double the base affection gain
        }
        // affection: leave scene level unchanged (was level=0, which dropped active scenes — matches hug)
        updateIntimacyForActor($actorName,$intimacyStatus);

    }


    $GLOBALS["AIAGENTNSFW_FORCE_STOP"]=false;

};


/******
ExtCmdPutOnClothes
******/

$GLOBALS["F_NAMES"]["ExtCmdPutOnClothes"]="PutOnClothes";                           
$GLOBALS["F_TRANSLATIONS"]["ExtCmdPutOnClothes"]="{$GLOBALS["HERIKA_NAME"]} puts clothes on";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdPutOnClothes"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdPutOnClothes"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "target" => [
                "type" => "string",
                "description" => "Keep it blank",
            ]
        ],
        "required" => []
    ],
]
;


$GLOBALS["FUNCSERV"]["ExtCmdPutOnClothes"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdPutOnClothes FUNCSERV");
   
    $intimacyStatus=getIntimacyForActor($GLOBALS["HERIKA_NAME"]);
    if (isSexDisposalEnabled()) {
        $intimacyStatus["sex_disposal"]-=10;
    }
    $intimacyStatus["level"]=0;
    $intimacyStatus["is_naked"]=0;  // Track naked state always (informational)
    updateIntimacyForActor($GLOBALS["HERIKA_NAME"],$intimacyStatus);    

    $GLOBALS["AVOID_LLM_CALL"]=true;



};


/******
ExtCmdAcceptSex - NPC agrees to sexual advances (Acquaintance tier 6+ required)
Unlocks sex personality prompt and kinks based on affinity tier
******/

$GLOBALS["F_NAMES"]["ExtCmdAcceptSex"]="AcceptSex";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdAcceptSex"]="{$GLOBALS["HERIKA_NAME"]} agrees to engage in intimacy with the target";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdAcceptSex"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdAcceptSex"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "target" => [
                "type" => "string",
                "description" => "The person being accepted",
            ]
        ],
        "required" => ["target"],
    ],
]
;

$GLOBALS["FUNCSERV"]["ExtCmdAcceptSex"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdAcceptSex FUNCSERV");

    $functionCallRet = explode("@", $gameRequest[3]);
    $targetName = $functionCallRet[1] ?? $GLOBALS["PLAYER_NAME"];
    // The Narrator must never be a sex partner / affinity target - coerce to the real player.
    if (nsfwIsNarratorName($targetName)) {
        $targetName = $GLOBALS["PLAYER_NAME"];
        error_log("[AcceptSex] target was the narrator - coerced to player");
    }
    $npcName = $GLOBALS["HERIKA_NAME"];

    $affinity = getNpcAffinity($npcName, $targetName);
    $intimacyStatus = getIntimacyForActor($npcName);

    $refusalLatched = (($intimacyStatus["scene_phase"] ?? null) === "rejected")
        || !empty($intimacyStatus["refusal_expressed"])
        || !empty($intimacyStatus["request_scene_stop"]);
    $acceptCommandConfirmed = false;
    if ($refusalLatched && function_exists('aiagentNsfwLatestSexConsentCommandForActor')) {
        $latestConsentCommand = aiagentNsfwLatestSexConsentCommandForActor($npcName, $intimacyStatus, $targetName);
        $acceptCommandConfirmed = is_array($latestConsentCommand)
            && (string)($latestConsentCommand["action"] ?? '') === 'ExtCmdAcceptSex';
    }
    if ($refusalLatched && !$acceptCommandConfirmed && empty($intimacyStatus["is_npc_scene"])) {
        $intimacyStatus["scene_phase"] = "rejected";
        $intimacyStatus["accepted_sex"] = false;
        $intimacyStatus["refusal_expressed"] = true;
        $intimacyStatus["request_scene_stop"] = !(
            function_exists('aiagentNsfwSceneExitLocked') && aiagentNsfwSceneExitLocked($npcName)
        );
        updateIntimacyForActor($npcName, $intimacyStatus);
        error_log("[AcceptSex] ignored for {$npcName}: RefuseSex is latched until total scene exit");
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    } elseif ($refusalLatched && $acceptCommandConfirmed) {
        error_log("[AcceptSex] accepting {$npcName}'s confirmed ExtCmdAcceptSex despite stale refusal latch");
    }

    // ============================================
    // PROSTITUTE CHECK: Transaction vs Personal
    // ============================================
    // If NPC is a prostitute, this is a BUSINESS TRANSACTION
    // Uses prostitute prompts, negotiation phase, no personal kinks
    // ============================================
    if (isProstitute($npcName)) {
        error_log("[AcceptSex] {$npcName} is prostitute - handling as TRANSACTION");

        if (isSexDisposalEnabled()) {
            $intimacyStatus["sex_disposal"] = ($intimacyStatus["sex_disposal"] ?? 0) + (int)aiagentNsfwArousalNum('AROUSAL_GAIN_TRANSACTION', 10); // Less arousal boost for transactions
        }
        $intimacyStatus["is_transaction"] = true;  // KEY FLAG: Uses prostitute prompt, not personal sex prompt
        $intimacyStatus["transaction_client"] = $targetName;
        $intimacyStatus["accepted_sex"] = false;   // NOT personal acceptance
        $intimacyStatus["negotiation_phase"] = true;  // In negotiation phase
        $intimacyStatus["scene_type"] = "transaction";

        // Transactions: NO personal kinks are revealed - business only
        $intimacyStatus["show_normal_kinks"] = false;
        $intimacyStatus["show_secret_kinks"] = false;

        updateIntimacyForActor($npcName, $intimacyStatus);

        // Inject negotiation context with pricing menu
        $negotiationContext = buildProstituteNegotiationContext($npcName, $targetName, $affinity);
        if (!empty($negotiationContext)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= $negotiationContext;
            error_log("[AcceptSex] Injected negotiation context for prostitute {$npcName}");
        }

        error_log("[AcceptSex] {$npcName} accepted TRANSACTION with {$targetName}. Affinity: {$affinity}. Now in NEGOTIATION phase.");
    } else {
        // ============================================
        // PERSONAL SEX: Non-prostitute or personal intimacy
        // ============================================
        if (isSexDisposalEnabled()) {
            $intimacyStatus["sex_disposal"] = ($intimacyStatus["sex_disposal"] ?? 0) + (int)aiagentNsfwArousalNum('AROUSAL_GAIN_ACCEPTSEX', 20);
        }
	        $intimacyStatus["arousal_pacing_decline_pending"] = false; // she chose to proceed - pacing hold is moot
	        $intimacyStatus["accepted_sex"] = true;
	        $intimacyStatus["scene_phase"] = "accepted";
	        $intimacyStatus["refusal_expressed"] = false;
	        $intimacyStatus["request_scene_stop"] = false;
	        $intimacyStatus["forced_scene"] = false;
	        $intimacyStatus["skooma_bargain_refused"] = false;
	        $intimacyStatus["sex_partner"] = $targetName;
	        $intimacyStatus["last_accept_sex_time"] = time();
	        $intimacyStatus["last_accept_sex_partner"] = $targetName;
	        $intimacyStatus["scene_type"] = "personal";
	        if (!empty($intimacyStatus["sex_started"]) || ((int)($intimacyStatus["intensity_tier"] ?? 0) >= 3 && empty($intimacyStatus["scene_is_idle"]))) {
	            $intimacyStatus["had_sex_in_scene"] = true;
	        }

        // Kink unlocks based on affinity
        // Normal kinks: Fond 56+
        // Secret kinks: Devoted 76+
        $intimacyStatus["show_normal_kinks"] = ($affinity >= 56);
        $intimacyStatus["show_secret_kinks"] = ($affinity >= 76);

        updateIntimacyForActor($npcName, $intimacyStatus);

        error_log("[AcceptSex] {$npcName} accepted personal sex with {$targetName}. Affinity: {$affinity}, Normal kinks: " . ($intimacyStatus["show_normal_kinks"] ? 'yes' : 'no') . ", Secret kinks: " . ($intimacyStatus["show_secret_kinks"] ? 'yes' : 'no'));
        aiagentNsfwInstantCrush($npcName, 'AcceptSex', $targetName);
    }

    $GLOBALS["AVOID_LLM_CALL"] = false;
};


/******
ExtCmdRefuseSex - NPC refuses sexual advances (available when the active gate allows refusal)
Clearer intent signal than EndSceneEarly for the LLM
******/

$GLOBALS["F_NAMES"]["ExtCmdRefuseSex"]="RefuseSex";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdRefuseSex"]="{$GLOBALS["HERIKA_NAME"]} refuses or rejects sexual advances";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdRefuseSex"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdRefuseSex"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "reason" => [
                "type" => "string",
                "description" => "Brief reason for refusing (e.g., 'not interested', 'wrong time', 'don't trust you')",
            ]
        ],
        "required" => []
    ],
]
;

$GLOBALS["FUNCSERV"]["ExtCmdRefuseSex"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdRefuseSex FUNCSERV");

    // ---- REFUSAL STATE (ALWAYS) - drives relationship/refusal consequences + blocks every sex unlock.
    // ExtCmdRefuseSex is now SPEECH/STATE ONLY; the mechanical scene stop is decided below by the exit-lock. ----
    $npcName = $GLOBALS["HERIKA_NAME"];
    $intimacyStatus = getIntimacyForActor($npcName);
    $intimacyStatus["scene_phase"] = "rejected";  // CRITICAL: Block all sex prompts
    $intimacyStatus["accepted_sex"] = false;
    $intimacyStatus["refusal_expressed"] = true;
    // STICKY REFUSAL: once she says no, the refusal holds until the OStim/SexLab engine scene actually ends.
    // The consent formula + scene-tick rebuild both honor this so sex_started/had_sex_in_scene can't re-consent
    // her mid-scene. handleSceneEnd clears it. (The NPC-scene dialogue-only branch below unsets it - that path
    // deliberately keeps the scene running.)
    $intimacyStatus["refused_until_scene_end"] = true;
    // HARD 15-MIN CAP (user directive 2026-06-28): the refusal normally clears at scene end, but if the engine
    // scene-end signal is ever lost the lock could strand her in "rejected" forever. Stamp the refusal time so the
    // scene-tick rebuild can auto-release the lock after the cap even without a clean scene-end.
    $intimacyStatus["refused_until_scene_end_time"] = time();
    $intimacyStatus["sex_started"] = false;  // Ensure sex prompts don't fire
    $intimacyStatus["show_normal_kinks"] = false;
    $intimacyStatus["show_secret_kinks"] = false;
    $intimacyStatus["is_transaction"] = false;
    $intimacyStatus["sex_partner"] = null;
    $intimacyStatus["last_accept_sex_time"] = null;
    $intimacyStatus["last_accept_sex_partner"] = null;
    if (!empty($intimacyStatus["skooma_addiction_bargain"])) {
        $intimacyStatus["skooma_bargain_refused"] = true;
    }
    $intimacyStatus["skooma_addiction_bargain"] = false;
    if (isSexDisposalEnabled()) {
        if (!empty($intimacyStatus["arousal_pacing_decline_pending"])) {
            // Pacing refusal ("not yet - warm me up"): don't drain the warmth she just asked the player to build.
            $intimacyStatus["arousal_pacing_decline_pending"] = false;
        } else {
            $intimacyStatus["sex_disposal"] = max(0, ($intimacyStatus["sex_disposal"] ?? 0) - (int)aiagentNsfwArousalNum('AROUSAL_DROP_REFUSAL', 15));
        }
    }

    // ASSAULT-WITNESS (user directive 2026-07-01): a refusal DURING A PLAYER SEX SCENE from a Friendly-and-below NPC
    // emits a SILENT witness bulletin ("forcing themselves on") to surrounding NPCs who can perceive it. NPC-to-NPC
    // refusals (is_npc_scene) are excluded - the player is not forcing anyone there. Placed before the exit-lock
    // branch so it fires on both exit-locked and normal refusals.
    // SCENE-ACTIVE GUARD (fix 2026-07-01): only broadcast "forcing themselves" when a sex scene is actually running
    // (scene state or the engine marker) - a purely VERBAL refusal of a proposition is not an assault.
    $witnessSceneTs = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt") ?: 0);
    $witnessSceneRunning = !empty($intimacyStatus["sex_started"]) || (int)($intimacyStatus["level"] ?? 0) >= 1
        || ($witnessSceneTs > 0 && (time() - $witnessSceneTs) < 600);
    if ($witnessSceneRunning && empty($intimacyStatus["is_npc_scene"]) && function_exists('aiagentNsfwEmitWitnessLine')
        && function_exists('getNpcAffinity') && (int)getNpcAffinity($npcName) <= 55) {
        aiagentNsfwEmitWitnessLine('forcing', $npcName, $GLOBALS["PLAYER_NAME"] ?? 'Player');
    }

    if (function_exists('aiagentNsfwSceneExitLocked') && aiagentNsfwSceneExitLocked($npcName)) {
        // EXIT-LOCKED (drunk >= threshold / on sap): she refuses but is too far gone to leave. Keep her IN the
        // scene (level + scene_actors intact) so she protests and the consequences keep landing. NO hard stop.
        $intimacyStatus["request_scene_stop"] = false;
        updateIntimacyForActor($npcName, $intimacyStatus);
        error_log("[AIAGENTNSFW] RefuseSex (EXIT-LOCKED) for {$npcName} - rejected state set, scene CONTINUES, no stop");
    } else {
        // Normal refusal: tear the scene down + request the real mechanical stop. RefuseSex no longer stops in
        // Papyrus, so PHP must queue the correct low-level stop here for the scene to actually end.
        $stopRetryDue = function_exists('aiagentNsfwSceneStopRetryDue') ? aiagentNsfwSceneStopRetryDue($intimacyStatus) : empty($intimacyStatus["stop_command_sent"]);
        $isNpcSceneRefusal = !empty($intimacyStatus["is_npc_scene"]);
        $npcSceneThreadID = isset($intimacyStatus["npc_scene_thread_id"]) ? intval($intimacyStatus["npc_scene_thread_id"]) : -1;
        if ($npcSceneThreadID < 0 && isset($GLOBALS["AIAGENTNSFW_NPC_THREAD_ID"])) {
            $npcSceneThreadID = intval($GLOBALS["AIAGENTNSFW_NPC_THREAD_ID"]);
        }
        if ($isNpcSceneRefusal) {
            // NPC expansion thread control is not reliable enough to enforce refusal mechanically.
            // Keep the scene route alive and treat this as dialogue/state only.
            $intimacyStatus["scene_phase"] = (!empty($intimacyStatus["sex_started"]) || !empty($intimacyStatus["had_sex_in_scene"]))
                ? "engaged"
                : "accepted";
            $intimacyStatus["accepted_sex"] = true;
            $intimacyStatus["sex_started"] = true;
            $intimacyStatus["had_sex_in_scene"] = true;
            $intimacyStatus["request_scene_stop"] = false;
            $intimacyStatus["stop_command_sent"] = false;
            $intimacyStatus["npc_refusal_dialogue_only"] = true;
            $intimacyStatus["refused_until_scene_end"] = false;  // NPC-scene path keeps the scene alive (dialogue-only)
            updateIntimacyForActor($npcName, $intimacyStatus);
            error_log("[AIAGENTNSFW] RefuseSex called during NPC-to-NPC scene for {$npcName}; kept NPC scene alive, no hard stop queued");
            $GLOBALS["AVOID_LLM_CALL"] = false;
            return;
        }
        // The model CHOSE RefuseSex - this is the NPC's decision to EXIT the scene. The exit-lock above
        // (drunk-at-threshold / sleeping-tree sap) is the ONLY thing that prevents leaving; a sober NPC who refuses
        // leaves. Queue the real OStim/SexLab stop. This is model-driven (never a server-forced refusal).
        $intimacyStatus["request_scene_stop"] = true;
        $intimacyStatus["level"] = 0;
        $sceneActors = is_array($intimacyStatus["scene_actors"] ?? null) ? $intimacyStatus["scene_actors"] : [];
        $intimacyStatus["scene_actors"] = $sceneActors;
        updateIntimacyForActor($npcName, $intimacyStatus);
        $stopQueued = false;
        if ($stopRetryDue && function_exists('aiagentNsfwQueuePlayerSceneStop')) {
            // Group scene: the refuser LEAVES, the others continue (ExtCmdLeaveScene). Couple: full stop.
            $stopQueued = aiagentNsfwQueuePlayerSceneStop($npcName, aiagentNsfwSceneExitCommand($intimacyStatus));
            if ($stopQueued) {
                if (function_exists('aiagentNsfwMarkSceneStopQueued')) { aiagentNsfwMarkSceneStopQueued($intimacyStatus); }
                else { $intimacyStatus["stop_command_sent"] = true; }
                updateIntimacyForActor($npcName, $intimacyStatus);
            }
        }
        foreach ($sceneActors as $sceneActor) {
            if (strcasecmp($sceneActor, $npcName) === 0) {
                continue;
            }
            $otherStatus = getIntimacyForActor($sceneActor);
            if (!empty($otherStatus["is_npc_scene"])) {
                $otherStatus["level"] = 0;
                $otherStatus["scene_phase"] = "rejected";
                $otherStatus["accepted_sex"] = false;
                $otherStatus["sex_started"] = false;
                $otherStatus["npc_scene_partner"] = null;
                $otherStatus["npc_scene_thread_id"] = null;
                $otherStatus["scene_actors"] = null;
                updateIntimacyForActor($sceneActor, $otherStatus);
            }
        }
        if ($isNpcSceneRefusal) {
            error_log("[AIAGENTNSFW] RefuseSex for {$npcName} - rejected + NPC scene torn down + ExtCmdStopNpcScene route " . ($stopQueued ? "queued" : "not queued"));
        } else {
            error_log("[AIAGENTNSFW] RefuseSex for {$npcName} - rejected + player scene torn down + ExtCmdStopScene route " . ($stopQueued ? "queued" : "not queued"));
        }
    }

    $GLOBALS["AVOID_LLM_CALL"] = false;
};


/******
ExtCmdQuitProstitution - At top affinity (Bonded, aff>=91) the NPC chooses to stop selling sex to the player
Only surfaced when isProstitute + love tier (see ENABLED_FUNCTIONS gating). Un-ticks the is_prostitute checkbox.
******/

$GLOBALS["F_NAMES"]["ExtCmdQuitProstitution"]="QuitProstitution";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdQuitProstitution"]="{$GLOBALS["HERIKA_NAME"]} stops working as a prostitute for {$GLOBALS["PLAYER_NAME"]} - no longer charges, gives herself freely out of love";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdQuitProstitution"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdQuitProstitution"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "reason" => [
                "type" => "string",
                "description" => "Brief in-character reason for stopping (e.g., 'I love you', 'I don't want your gold anymore')",
            ]
        ],
        "required" => []
    ],
]
;

$GLOBALS["FUNCSERV"]["ExtCmdQuitProstitution"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdQuitProstitution FUNCSERV");

    // Un-tick the prostitute checkbox (single source of truth) - she stops charging; relationship/affinity untouched
    setNpcProstituteStatus($GLOBALS["HERIKA_NAME"], false);

    // Clear in-flight transaction/negotiation + stale prostitute cache so she reverts cleanly
    $intimacyStatus = getIntimacyForActor($GLOBALS["HERIKA_NAME"]);
    $intimacyStatus["is_transaction"] = false;
    $intimacyStatus["negotiation_phase"] = null;
    $intimacyStatus["npc_is_prostitute"] = false;
    updateIntimacyForActor($GLOBALS["HERIKA_NAME"], $intimacyStatus);

    error_log("[AIAGENTNSFW] QuitProstitution - {$GLOBALS["HERIKA_NAME"]} is no longer a prostitute");

    // Let the model voice the moment (she tells the player she won't charge anymore)
    $GLOBALS["AVOID_LLM_CALL"] = false;
};


/******
ExtCmdGiveFreeService - At Devoted (aff>=76) a prostitute MAY choose to give THIS service for free: no charge,
no price modifier. Unlike QuitProstitution she STAYS a prostitute - she just waives payment for this one
encounter. Unlocks the scene exactly like a paid service (durable payment ledger), minus the gold.
******/

$GLOBALS["F_NAMES"]["ExtCmdGiveFreeService"]="GiveFreeService";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdGiveFreeService"]="{$GLOBALS["HERIKA_NAME"]} chooses to give {$GLOBALS["PLAYER_NAME"]} this service for free - no charge, no price - because she wants to, not for gold";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdGiveFreeService"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdGiveFreeService"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "reason" => [
                "type" => "string",
                "description" => "Brief in-character reason for not charging this time (e.g., 'this one is on me', 'I don't want your gold tonight')",
            ]
        ],
        "required" => []
    ],
]
;

$GLOBALS["FUNCSERV"]["ExtCmdGiveFreeService"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdGiveFreeService FUNCSERV");
    $npc = $GLOBALS["HERIKA_NAME"];

    // Waive payment for THIS service without un-ticking the prostitute checkbox. Reuse the durable payment
    // ledger so every existing payment gate + the orgasm/window consume path treat it exactly like a paid
    // service - just with zero gold. No gold is removed, so the payment-received outcome prompt never fires.
    if (function_exists('aiagentNsfwSetPaymentLedger')) {
        aiagentNsfwSetPaymentLedger($npc, 0, 0, 0);
    }
    $intimacyStatus = getIntimacyForActor($npc);
    $intimacyStatus["payment_confirmed"] = true;
    $intimacyStatus["free_service"] = true;
    $intimacyStatus["is_transaction"] = false;
    $intimacyStatus["negotiation_phase"] = null;
    updateIntimacyForActor($npc, $intimacyStatus);

    error_log("[AIAGENTNSFW] GiveFreeService - {$npc} waived payment (Devoted); service unlocked free");

    // Let the model voice it (she tells the player it's free this time)
    $GLOBALS["AVOID_LLM_CALL"] = false;
};


/******
ExtCmdWorshipMaster - A slave chooses to worship/pray to their master. Plays the pray idle in-game and lets
the slave voice their devotion. Offered only to slaves (see ENABLED_FUNCTIONS gating).
******/
$GLOBALS["F_NAMES"]["ExtCmdWorshipMaster"]="WorshipMaster";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdWorshipMaster"]="{$GLOBALS["HERIKA_NAME"]} kneels and worships their master {$GLOBALS["PLAYER_NAME"]} in devotion";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdWorshipMaster"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdWorshipMaster"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "reason" => [
                "type" => "string",
                "description" => "Brief in-character expression of devotion to your master.",
            ]
        ],
        "required" => []
    ],
];
$GLOBALS["FUNCSERV"]["ExtCmdWorshipMaster"]=function() {
    error_log("Running ExtCmdWorshipMaster FUNCSERV");
    $npc = $GLOBALS["HERIKA_NAME"];
    if (!class_exists('Npcmaster')) { require_once __DIR__ . "/../../lib/core/npc_master.class.php"; }
    $refid = (new Npcmaster())->getByName($npc)["refid"] ?? null;
    if (!empty($refid) && function_exists('aiagentNsfwSendIdle')) {
        aiagentNsfwSendIdle($npc, $refid, "IdlePray"); // resolver -> 0x0006F300 -> PlayIdle
        // ONE PRAY, NOT A LIFESTYLE (fix 2026-07-01o): IdlePray LOOPS until the actor is reset, so schedule
        // the release. IdleForceDefaultState rides the CommandAnimation queue with a future localts (same
        // scheduled-delivery the skooma cheer uses); default 15s covers one full pray cycle.
        $worshipSecs = max(3, (int)_getNsfwSetting('SLAVERY_WORSHIP_DURATION_SECONDS', 15));
        aiagentNsfwSendIdle($npc, $refid, "IdleForceDefaultState", time() + $worshipSecs);
    }
    error_log("[AIAGENTNSFW] WorshipMaster - {$npc} worships (pray idle)");
    $GLOBALS["AVOID_LLM_CALL"] = false; // let the slave voice the devotion
};


/******
ExtCmdAskForFreedom - A slave asks their master for freedom. REQUEST ONLY: sets a pending flag and lets the
slave voice the plea. It does NOT free her - the master must explicitly agree, after which ExtCmdAcceptFreedom
is surfaced. Offered only when SLAVERY_ALLOW_ASK_FREEDOM is enabled (slave UI toggle).
******/
$GLOBALS["F_NAMES"]["ExtCmdAskForFreedom"]="AskForFreedom";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdAskForFreedom"]="{$GLOBALS["HERIKA_NAME"]} pleads with their master {$GLOBALS["PLAYER_NAME"]} for freedom - a request only; the master must decide";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdAskForFreedom"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdAskForFreedom"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "reason" => [
                "type" => "string",
                "description" => "In-character reason for asking to be freed.",
            ]
        ],
        "required" => []
    ],
];
$GLOBALS["FUNCSERV"]["ExtCmdAskForFreedom"]=function() {
    error_log("Running ExtCmdAskForFreedom FUNCSERV");
    $npc = $GLOBALS["HERIKA_NAME"];
    $intimacyStatus = getIntimacyForActor($npc);
    $intimacyStatus["freedom_requested"] = true;
    $intimacyStatus["freedom_requested_time"] = time();
    updateIntimacyForActor($npc, $intimacyStatus);
    error_log("[AIAGENTNSFW] AskForFreedom - {$npc} requested freedom (pending master's decision)");
    $GLOBALS["AVOID_LLM_CALL"] = false; // let the slave voice the plea
};


/******
ExtCmdAcceptFreedom - Surfaced ONLY after ExtCmdAskForFreedom set the pending flag. The slave frees herself
because the master EXPLICITLY agreed (the slave prompt instructs her to use this only on the master's clear
consent). Clears is_slave (like QuitProstitution clears is_prostitute).
******/
$GLOBALS["F_NAMES"]["ExtCmdAcceptFreedom"]="AcceptFreedom";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdAcceptFreedom"]="{$GLOBALS["HERIKA_NAME"]}'s master grants them freedom - they are no longer a slave";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdAcceptFreedom"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdAcceptFreedom"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "reason" => [
                "type" => "string",
                "description" => "Brief in-character reaction to being freed. ONLY use this if your master explicitly agreed to free you.",
            ]
        ],
        "required" => []
    ],
];
$GLOBALS["FUNCSERV"]["ExtCmdAcceptFreedom"]=function() {
    error_log("Running ExtCmdAcceptFreedom FUNCSERV");
    $npc = $GLOBALS["HERIKA_NAME"];
    if (function_exists('freeNpcSlave')) { freeNpcSlave($npc); }  // clears is_slave (single source of truth)
    $intimacyStatus = getIntimacyForActor($npc);
    $intimacyStatus["freedom_requested"] = false;
    $intimacyStatus["freedom_requested_time"] = null;
    $intimacyStatus["npc_is_slave"] = false;
    updateIntimacyForActor($npc, $intimacyStatus);
    error_log("[AIAGENTNSFW] AcceptFreedom - {$npc} freed (is_slave cleared)");
    $GLOBALS["AVOID_LLM_CALL"] = false; // let the freed NPC voice the moment
};


/******
ExtCmdPoisonMasterFood - Hidden slave revenge command. Arms a player-alias poison state;
the player only sees a notification if the next eligible consumable actually triggers it.
******/

$GLOBALS["F_NAMES"]["ExtCmdPoisonMasterFood"]="PoisonMasterFood";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdPoisonMasterFood"]="Secretly poison the player's next eligible consumable";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdPoisonMasterFood"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdPoisonMasterFood"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "reason" => [
                "type" => "string",
                "description" => "Private reason for attempting revenge. Do not say this out loud.",
            ]
        ],
        "required" => []
    ],
]
;

$GLOBALS["FUNCSERV"]["ExtCmdPoisonMasterFood"]=function() {
    global $gameRequest;
    error_log("Running ExtCmdPoisonMasterFood FUNCSERV");

    $expireHours = (int)_getNsfwSetting('SLAVERY_POISON_EXPIRE_GAME_HOURS', 24);
    if ($expireHours <= 0) { $expireHours = 24; }
    $successChance = max(0, min(100, (int)_getNsfwSetting('SLAVERY_POISON_SUCCESS_CHANCE', 65)));
    $durationSeconds = max(5, (int)_getNsfwSetting('SLAVERY_POISON_DURATION_SECONDS', 120));
    $magnitude = max(1, (int)_getNsfwSetting('SLAVERY_POISON_MAGNITUDE', 3));
    $notify = _getNsfwSetting('SLAVERY_POISON_NOTIFY_PLAYER', true) ? 1 : 0;
    $typesRaw = (string)_getNsfwSetting('SLAVERY_POISON_CONSUME_TYPES', "food\ndrink\npotion");
    $types = preg_split('/[\r\n,|]+/', $typesRaw);
    $cleanTypes = [];
    foreach ($types as $type) {
        $type = strtolower(trim((string)$type));
        if ($type !== '' && preg_match('/^[a-z_]+$/', $type)) {
            $cleanTypes[] = $type;
        }
    }
    if (empty($cleanTypes)) {
        $cleanTypes = ['food', 'drink', 'potion'];
    }
    $typeArg = implode(',', array_values(array_unique($cleanTypes)));

    $gameRequest[3] = "ExtCmdPoisonMasterFood@{$expireHours}@{$successChance}@{$durationSeconds}@{$magnitude}@{$notify}@{$typeArg}";
    $GLOBALS["AVOID_LLM_CALL"] = true;
};


/******
ExtCmdStopScene - Ends an active OStim scene early
******/

$GLOBALS["F_NAMES"]["ExtCmdStopScene"]="EndSexScene";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdStopScene"]="{$GLOBALS["HERIKA_NAME"]} ends and EXITS the active SEX scene she is currently in (the live OStim/SexLab intimate scene). The ONLY way to stop having sex and leave the scene - finished, satisfied, uncomfortable, or interrupted.";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdStopScene"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdStopScene"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "reason" => [
                "type" => "string",
                "description" => "Brief reason for ending the scene (e.g., 'uncomfortable', 'interrupted', 'satisfied', 'payment dispute')",
            ]
        ],
        "required" => []
    ],
]
;


$GLOBALS["FUNCSERV"]["ExtCmdStopScene"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdStopScene FUNCSERV");

    $npcName = $GLOBALS["HERIKA_NAME"];

    // Reset intimacy level to indicate scene ended
    $intimacyStatus = getIntimacyForActor($npcName);
    $intimacyStatus["level"] = 0;  // No longer in a scene
    $intimacyStatus["is_naked"] = 0;  // Reset clothing state - NPC is dressed after scene
    if (isSexDisposalEnabled()) {
        $intimacyStatus["sex_disposal"] = max(0, ($intimacyStatus["sex_disposal"] ?? 0) - 10);
    }

    // If this was a prostitute transaction, do service tracking
    if (isProstitute($npcName) && !empty($intimacyStatus["is_transaction"])) {
        $intimacyStatus["service_completed"] = true;
        $intimacyStatus["service_end_time"] = time();

        // Calculate service duration if we have start time
        if (isset($intimacyStatus["scene_start_time"])) {
            $intimacyStatus["service_duration"] = time() - $intimacyStatus["scene_start_time"];
        }

        // Track total services for this NPC
        $intimacyStatus["total_services"] = ($intimacyStatus["total_services"] ?? 0) + 1;

        error_log("[StopScene] Prostitute {$npcName} service completed (total: {$intimacyStatus["total_services"]})");
    }

    // Clear NPC scene data
    $intimacyStatus["npc_scene_partner"] = null;
    $intimacyStatus["npc_scene_thread_id"] = null;
    $intimacyStatus["is_transaction"] = false;
    $intimacyStatus["transaction_client"] = null;
    $intimacyStatus["negotiation_phase"] = false;
    $intimacyStatus["ready_for_service"] = false;
    $intimacyStatus["payment_pending"] = false;
    $intimacyStatus["payment_pending_amount"] = null;
    $intimacyStatus["payment_pending_service"] = null;
    $intimacyStatus["payment_confirmed"] = false;
    $intimacyStatus["payment_confirmed_amount"] = null;
    $intimacyStatus["payment_confirmed_time"] = null;
    $intimacyStatus["payment_service"] = null;
    $intimacyStatus["payment_failed"] = false;
    $intimacyStatus["payment_failure_reason"] = null;
    $intimacyStatus["payment_failed_amount"] = null;
    $intimacyStatus["payment_failed_time"] = null;
    $intimacyStatus["whiskey_dick_fired"] = false;
    $intimacyStatus["whiskey_dick_player_stage"] = null;

    updateIntimacyForActor($npcName, $intimacyStatus);

    $GLOBALS["AVOID_LLM_CALL"] = true;
};

/******
ExtCmdStopNpcScene - Ends an active NPC-to-NPC OStim scene by stored thread ID.
This is the hard stop path for NPC expansion refusal; player scenes still use EndSceneEarly/RefuseSex.
******/

$GLOBALS["F_NAMES"]["ExtCmdStopNpcScene"]="EndNpcScene";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdStopNpcScene"]="{$GLOBALS["HERIKA_NAME"]} refuses or ends an NPC-to-NPC intimate scene and stops that NPC scene thread";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdStopNpcScene"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdStopNpcScene"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "reason" => [
                "type" => "string",
                "description" => "Brief reason for stopping the NPC-to-NPC scene",
            ]
        ],
        "required" => []
    ],
]
;

$GLOBALS["FUNCSERV"]["ExtCmdStopNpcScene"]=function() {
    global $gameRequest;
    error_log("Running ExtCmdStopNpcScene FUNCSERV");

    $npcName = $GLOBALS["HERIKA_NAME"];
    $intimacyStatus = getIntimacyForActor($npcName);
    $threadID = isset($intimacyStatus["npc_scene_thread_id"]) ? intval($intimacyStatus["npc_scene_thread_id"]) : -1;
    if ($threadID < 0 && isset($GLOBALS["AIAGENTNSFW_NPC_THREAD_ID"])) {
        $threadID = intval($GLOBALS["AIAGENTNSFW_NPC_THREAD_ID"]);
    }

    $sceneActors = is_array($intimacyStatus["scene_actors"] ?? null)
        ? $intimacyStatus["scene_actors"]
        : [$npcName];

    foreach ($sceneActors as $sceneActor) {
        $status = getIntimacyForActor($sceneActor);
        $status["level"] = 0;
        $status["scene_phase"] = "rejected";
        $status["accepted_sex"] = false;
        $status["sex_started"] = false;
        $status["request_scene_stop"] = true;
        $status["npc_scene_partner"] = null;
        $status["npc_scene_thread_id"] = null;
        $status["scene_actors"] = null;
        $status["is_npc_scene"] = false;
        updateIntimacyForActor($sceneActor, $status);
    }

    if ($threadID >= 0) {
        $gameRequest[3] = "ExtCmdStopNpcScene@" . $threadID;
        error_log("[AIAGENTNSFW] EndNpcScene requested hard stop for NPC thread {$threadID} by {$npcName}");
    } else {
        error_log("[AIAGENTNSFW] EndNpcScene could not find NPC scene thread for {$npcName}");
    }

    $GLOBALS["AVOID_LLM_CALL"] = false;
};


/******
ExtCmdSexCommand. This will be only be offere when NPC is on sexual scene
******/

$GLOBALS["F_NAMES"]["ExtCmdSexCommand"]="SexAction";                           
$GLOBALS["F_TRANSLATIONS"]["ExtCmdSexCommand"]="{$GLOBALS["HERIKA_NAME"]} performs a sexual action/position/practise";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdSexCommand"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdSexCommand"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "target" => [
                "type" => "string",
                "description" => "blowjob,boobjob,analsex,vaginalsex",
            ]
        ],
        "required" => ["target"],
    ]
]
;


$GLOBALS["FUNCSERV"]["ExtCmdSexCommand"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdSexCommand FUNCSERV");
   
    $functionCallRet = explode("@", $gameRequest[3]); // Function returns here
    // Update intimacy status for al lparticipants

    

    
    $GLOBALS["AIAGENTNSFW_FORCE_STOP"]=false;

};



/******
ExtCmdQuickenPace / ExtCmdSlowPace - deliberate model acts to change the OStim scene TEMPO during an active scene.
Offered only mid-scene, gated on NSFW_ALLOW_PACE_CONTROL (UI toggle). Execution is game-side (CommandManager ->
OThread.SetSpeed); the FUNCSERV is server-side bookkeeping only. The catalog drives the param schema (optional reason).
******/

$GLOBALS["F_NAMES"]["ExtCmdQuickenPace"]="QuickenPace";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdQuickenPace"]="{$GLOBALS["HERIKA_NAME"]} speeds up the pace/tempo of the current sex scene";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdQuickenPace"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdQuickenPace"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "reason" => [ "type" => "string", "description" => "Optional in-character line about picking up the pace" ]
        ],
        "required" => [],
    ]
]
;
$GLOBALS["FUNCSERV"]["ExtCmdQuickenPace"]=function() {
    error_log("Running ExtCmdQuickenPace FUNCSERV");
    $GLOBALS["AIAGENTNSFW_FORCE_STOP"]=false;
};

$GLOBALS["F_NAMES"]["ExtCmdSlowPace"]="SlowPace";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdSlowPace"]="{$GLOBALS["HERIKA_NAME"]} slows down the pace/tempo of the current sex scene";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdSlowPace"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdSlowPace"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "reason" => [ "type" => "string", "description" => "Optional in-character line about slowing down" ]
        ],
        "required" => [],
    ]
]
;
$GLOBALS["FUNCSERV"]["ExtCmdSlowPace"]=function() {
    error_log("Running ExtCmdSlowPace FUNCSERV");
    $GLOBALS["AIAGENTNSFW_FORCE_STOP"]=false;
};


/******
ExtCmdConsumeSoul
******/

$GLOBALS["F_NAMES"]["ExtCmdConsumeSoul"]="RitualConsumeSoul";                           
$GLOBALS["F_TRANSLATIONS"]["ExtCmdConsumeSoul"]="{$GLOBALS["HERIKA_NAME"]} consumes soul of a captured foe (target)";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdConsumeSoul"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdConsumeSoul"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "target" => [
                "type" => "string",
                "description" => "Victim, captured foe",
            ]
        ],
        "required" => ["target"]
    ],
]
;


$GLOBALS["FUNCSERV"]["ExtCmdConsumeSoul"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdConsumeSoul FUNCSERV");


    $GLOBALS["AIAGENTNSFW_FORCE_STOP"]=false;



};


/******
ExtCmdCollectPayment - TakeGold tool transfers negotiated gold from player
Uses PaymentHandler to transfer gold via Papyrus (bypasses consent for negotiated transactions)
Sets payment_confirmed=true to unlock scene progression
******/

$GLOBALS["F_NAMES"]["ExtCmdCollectPayment"]="TakeGold";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdCollectPayment"]="Collect the agreed payment from the player for negotiated services. You MUST set 'amount' to the agreed gold price as a NUMBER (like 50 or 100) - never 0, never empty. Payment is gold by default. If you agreed to barter, set 'item' to the exact item name from the player's inventory and 'amount' to the agreed gold-equivalent price - the item must be worth at least that much. On payment_failed (player lacks it) or payment_insufficient (item not worth enough) respond to that context and do not proceed.";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdCollectPayment"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdCollectPayment"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "amount" => [
                "type" => "integer",
                "description" => "Agreed price in gold (e.g., 50, 100, 250). For barter this is the gold-equivalent the item(s) must be worth.",
            ],
            "service" => [
                "type" => "string",
                "description" => "Brief description of services (e.g., 'massage', 'oral', 'full service')",
            ],
            "item" => [
                "type" => "string",
                "description" => "Optional. For barter only: the exact item name from the player's inventory to take instead of gold (e.g. 'Fire Salts'). The item's value must be at least 'amount'. Omit (or 'gold') for a normal gold payment.",
            ]
        ],
        "required" => ["amount"]
    ],
]
;

$GLOBALS["FUNCSERV"]["ExtCmdCollectPayment"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdCollectPayment FUNCSERV");

    $npcName = $GLOBALS["HERIKA_NAME"];

    // Check if NPC is actually a prostitute
    if (!isProstitute($npcName)) {
        error_log("[TakeGold] {$npcName} is not marked as prostitute - ignoring");
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    }

    $intimacyStatus = getIntimacyForActor($npcName);

    $functionCallRet = explode("@", $gameRequest[3], 4);
    $isFuncret = (($gameRequest[0] ?? '') === 'funcret');

    $parsePaymentArg = function($raw) {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return 0;
        }
        if ($raw[0] === '{') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['amount'])) {
                return (int)$decoded['amount'];
            }
        }
        return (int)$raw;
    };
    $parseServiceArg = function($raw, $fallback = "services") {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return $fallback;
        }
        if ($raw[0] === '{') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['service'])) {
                return (string)$decoded['service'];
            }
            return $fallback;
        }
        return $raw;
    };

    // funcret format: command@ExtCmdCollectPayment@amount@payment_success|payment_failed...
    // tool-call format: ExtCmdCollectPayment@{"amount":100,"service":"full service"}
    // legacy fallback format: ExtCmdCollectPayment@amount@service
    $paymentAmount = $isFuncret
        ? (isset($functionCallRet[2]) ? $parsePaymentArg($functionCallRet[2]) : 0)
        : (isset($functionCallRet[1]) ? $parsePaymentArg($functionCallRet[1]) : 0);
    $serviceDesc = $isFuncret
        ? ($intimacyStatus["payment_pending_service"] ?? "services")
        : (isset($functionCallRet[1]) ? $parseServiceArg($functionCallRet[1], $functionCallRet[2] ?? "services") : ($functionCallRet[2] ?? "services"));
    $resultText = $isFuncret ? ($functionCallRet[3] ?? '') : '';

    // Barter: optional item to take instead of gold. Tool-call args arrive as JSON (functionCallRet[1]);
    // on the funcret we recall what was pending. Empty / 'gold' = normal gold payment.
    $parseItemArg = function($raw) {
        $raw = trim((string)$raw);
        if ($raw !== '' && $raw[0] === '{') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['item'])) {
                return trim((string)$decoded['item']);
            }
        }
        return '';
    };
    $paymentItem = $isFuncret
        ? trim((string)($intimacyStatus["payment_pending_item"] ?? ''))
        : (isset($functionCallRet[1]) ? $parseItemArg($functionCallRet[1]) : '');
    $isGoldPayment = ($paymentItem === '' || strcasecmp($paymentItem, 'gold') === 0);
    error_log("[TakeGold] raw gameRequest[3]=" . ($gameRequest[3] ?? '') . " | parsed amount={$paymentAmount} service={$serviceDesc} item=" . ($paymentItem !== '' ? $paymentItem : 'gold') . " isFuncret=" . ($isFuncret ? '1' : '0'));

    if ($paymentAmount <= 0 && !$isFuncret) {
        // DUMB-MODEL RESCUE (tester report 2026-07-04): weak models call TakeGold with no/zero amount and
        // the doomed command failed in Papyrus ("invalid gold amount"). The ONLY amount the server may
        // reuse is a prior TakeGold's pending amount - HER OWN stated number. The price is NEGOTIATED in
        // dialogue (haggling, discounts), so the menu rate must never be guess-charged.
        $rescued = (int)($intimacyStatus['payment_pending_amount'] ?? 0);
        if ($rescued > 0) {
            $paymentAmount = $rescued;
            // Fixed-position rewrite so Papyrus gets the rescued amount instead of the model's zero.
            $gameRequest[3] = "ExtCmdCollectPayment@{$paymentAmount}@{$serviceDesc}@" . ($isGoldPayment ? '' : $paymentItem);
            error_log("[TakeGold] RESCUED missing/zero amount -> {$paymentAmount} (her prior pending amount) for {$npcName}");
        }
    }

    if ($paymentAmount <= 0) {
        // Discounts can legitimately reach 100% (devoted/bonded tiers = FREE): a zero from a
        // waived-tier client is correct - no charge, the service gate is already open via the waiver.
        // She must never be coached to demand gold from someone she gives herself to freely.
        if (function_exists('aiagentNsfwProstitutePaymentWaived') && aiagentNsfwProstitutePaymentWaived($npcName)) {
            if (!$isFuncret) { $gameRequest[3] = "ExtCmdNoOp@free_service_no_payment_needed"; }
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n<payment_error>No payment is needed - at your bond with this client, your service is freely given. Do not collect gold; simply proceed.</payment_error>";
            error_log("[TakeGold] Zero amount from WAIVED tier for {$npcName} - free service, no charge, no Papyrus call");
            $GLOBALS["AVOID_LLM_CALL"] = false;
            return;
        }
        error_log("[TakeGold] BLOCKED zero/invalid amount in " . ($gameRequest[3] ?? '') . " - command neutralized, coaching model to retry with the agreed number");
        // A zero NEVER reaches the game: neutralize the command (unregistered = the game drops it), so
        // Papyrus can never run a 0-gold payment. No gold is guessed for her either - the price is
        // negotiated, so SHE must restate the agreed number and call again with it.
        if (!$isFuncret) {
            $gameRequest[3] = "ExtCmdNoOp@blocked_zero_payment";
        }
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n<payment_error>Your TakeGold call carried no gold amount, so NO payment was taken. Call TakeGold again with the amount parameter set to the price you and the client AGREED on, as a number (for example: amount 100). If no price was agreed yet, state your price first. Do not provide the service until TakeGold succeeds.</payment_error>";
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    }

    if (!$isFuncret) {
        // Do not confirm here. Papyrus must count/remove the real item (gold or bartered) and return funcret.
        $intimacyStatus["payment_pending"] = true;
        $intimacyStatus["payment_pending_amount"] = $paymentAmount;
        $intimacyStatus["payment_pending_service"] = $serviceDesc;
        $intimacyStatus["payment_pending_item"] = $isGoldPayment ? '' : $paymentItem;
        $intimacyStatus["payment_confirmed"] = false;
        $intimacyStatus["ready_for_service"] = false;
        updateIntimacyForActor($npcName, $intimacyStatus);
        // For barter, hand Papyrus a fixed-position command so an omitted middle arg can't shift the item.
        // Papyrus reads: ExtCmdCollectPayment@<amount>@<service>@<item>  (blank/gold item = gold payment).
        if (!$isGoldPayment) {
            $gameRequest[3] = "ExtCmdCollectPayment@{$paymentAmount}@{$serviceDesc}@{$paymentItem}";
        }
        error_log("[TakeGold] Pending Papyrus check for {$npcName}: {$paymentAmount} via " . ($isGoldPayment ? 'gold' : $paymentItem) . " for {$serviceDesc}; cmd=" . ($gameRequest[3] ?? ''));
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    }

    if (stripos($resultText, 'payment_success') !== false) {
        // Payment successful in Papyrus - set confirmed and unlock scene progression
        $intimacyStatus["payment_confirmed"] = true;
        $intimacyStatus["payment_confirmed_amount"] = $paymentAmount;
        $intimacyStatus["payment_confirmed_time"] = time();
        $intimacyStatus["payment_service"] = $serviceDesc;
        $intimacyStatus["payment_failed"] = false;
        $intimacyStatus["payment_failure_reason"] = null;
        $intimacyStatus["payment_pending"] = false;
        $intimacyStatus["payment_pending_amount"] = null;
        $intimacyStatus["payment_pending_service"] = null;
        $intimacyStatus["payment_pending_item"] = null;
        $intimacyStatus["negotiation_phase"] = false;
        $intimacyStatus["ready_for_service"] = true;
        $intimacyStatus["scene_phase"] = "accepted";
        $intimacyStatus["refusal_expressed"] = false;
        $intimacyStatus["request_scene_stop"] = false;
        $intimacyStatus["stop_command_sent"] = false;
        $intimacyStatus["forced_scene"] = false;
        $intimacyStatus["last_refusal_speech_key"] = null;
        $intimacyStatus["last_refusal_speech_time"] = null;
        $intimacyStatus["last_forced_refusal_scene_key"] = null;
        $intimacyStatus["tier_prompt_sent"] = true;

        // Persist to the durable ledger so a stale intimacy write can't clobber the confirmation
        // (survives until player orgasm or the payment window expires). Same source of truth the
        // item-trade path (handlePaymentCheck) writes to.
        if (function_exists('aiagentNsfwSetPaymentLedger')) {
            aiagentNsfwSetPaymentLedger($npcName, $paymentAmount, $paymentAmount, $isGoldPayment ? $paymentAmount : 0);
        }

        error_log("[TakeGold] Papyrus confirmed {$npcName} collected {$paymentAmount} gold for: {$serviceDesc}. Ready for service.");

        // Inject context so NPC knows payment was received
        $paidWithLabel = $isGoldPayment ? "{$paymentAmount} gold" : "{$paymentItem} (worth at least {$paymentAmount} gold)";
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n<payment_received>You just collected {$paidWithLabel} from {$GLOBALS['PLAYER_NAME']} for {$serviceDesc}. You can now proceed with the agreed services.</payment_received>";
    } else if (stripos($resultText, 'payment_insufficient') !== false) {
        // Barter item exists but is not worth the agreed price - stay in negotiation, do NOT unlock the scene.
        $intimacyStatus["payment_confirmed"] = false;
        $intimacyStatus["payment_confirmed_amount"] = null;
        $intimacyStatus["payment_confirmed_time"] = null;
        $intimacyStatus["payment_failed"] = true;
        $intimacyStatus["payment_failure_reason"] = $resultText;
        $intimacyStatus["payment_failed_amount"] = $paymentAmount;
        $intimacyStatus["payment_failed_time"] = time();
        $intimacyStatus["payment_pending"] = false;
        $intimacyStatus["payment_pending_item"] = null;
        $intimacyStatus["negotiation_phase"] = true;
        $intimacyStatus["ready_for_service"] = false;
        error_log("[TakeGold] Payment insufficient value for {$npcName}: {$resultText}");
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n<payment_insufficient>The offered payment is not worth the agreed {$paymentAmount} gold. Result: {$resultText}. Tell {$GLOBALS['PLAYER_NAME']} it is not enough and do not provide the service until paid properly.</payment_insufficient>";
    } else if (stripos($resultText, 'payment_failed') !== false || stripos($resultText, 'error') !== false) {
        // Payment failed in Papyrus. Keep service locked.
        $intimacyStatus["payment_confirmed"] = false;
        $intimacyStatus["payment_confirmed_amount"] = null;
        $intimacyStatus["payment_confirmed_time"] = null;
        $intimacyStatus["payment_failed"] = true;
        $intimacyStatus["payment_failure_reason"] = $resultText;
        $intimacyStatus["payment_failed_amount"] = $paymentAmount;
        $intimacyStatus["payment_failed_time"] = time();
        $intimacyStatus["payment_pending"] = false;
        $intimacyStatus["payment_pending_amount"] = null;
        $intimacyStatus["payment_pending_service"] = null;
        $intimacyStatus["negotiation_phase"] = true;
        $intimacyStatus["ready_for_service"] = false;

        error_log("[TakeGold] Papyrus rejected payment for {$npcName}: {$resultText}");

        // Inject context so NPC knows payment failed
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n<payment_failed>{$GLOBALS['PLAYER_NAME']} did not pay {$paymentAmount} gold. Result: {$resultText}. Do not provide the service until payment succeeds.</payment_failed>";
    } else {
        // Unknown return; fail closed.
        $intimacyStatus["payment_confirmed"] = false;
        $intimacyStatus["payment_failed"] = true;
        $intimacyStatus["payment_failure_reason"] = "Unrecognized payment result: {$resultText}";
        $intimacyStatus["payment_failed_amount"] = $paymentAmount;
        $intimacyStatus["payment_failed_time"] = time();
        $intimacyStatus["payment_pending"] = false;
        $intimacyStatus["negotiation_phase"] = true;
        $intimacyStatus["ready_for_service"] = false;

        error_log("[TakeGold] Unknown Papyrus payment result for {$npcName}: {$resultText}");
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n<payment_failed>Payment was not confirmed. Do not provide the service until TakeGold succeeds.</payment_failed>";
    }

    updateIntimacyForActor($npcName, $intimacyStatus);
    $GLOBALS["AVOID_LLM_CALL"] = false;
};


// POST FILTER HOOK. Used for cleaning actions returned by LLM
$GLOBALS["action_post_process_fnct_ex"][]=function($actions) {

    foreach ($actions as $n=>$action) {
        
        $actionParts=explode("|",$action);
        $actionParts2=explode("@",$actionParts[2]);
        
        if (isset($actionParts2[1])) {
            // Parameter part 
            if ($actionParts2[0]=="ExtCmdStartSex") {
                // Lets polish the parameters
                $localtarget=$actionParts2[1];
                $mang1=explode(",",$localtarget);
                $mang2=explode(" and ",$mang1[0]);
                $mang3=explode("(",$mang2[0]);

                if (empty(trim($mang3[0])))
                    $mang4=$GLOBALS["PLAYER_NAME"];
                else
                    $mang4=FindClosestNPCName($mang3[0]);

                if (empty($mang4))
                    $mang4=$mang3[0];

                $actions[$n]="{$actionParts[0]}|{$actionParts[1]}|ExtCmdStartSex@{$mang4}";

                error_log("[ACTION POSTFILTER ExtCmdStartSex] $localtarget => {$mang3[0]} => $mang4");

            } else  if ($actionParts2[0]=="ExtCmdKiss") {
                // Lets polish the parameters
                $localtarget=$actionParts2[1];
                $mang1=explode(",",$localtarget);
                $mang2=explode(" and ",$mang1[0]);
                $mang3=explode("(",$mang2[0]);

                if (empty(trim($mang3[0])))
                    $mang4=trim($GLOBALS["PLAYER_NAME"]);
                else
                    $mang4=trim(FindClosestNPCName($mang3[0]));

                if (empty($mang4))
                    $mang4=$mang3[0];

                $actions[$n]="{$actionParts[0]}|{$actionParts[1]}|ExtCmdKiss@{$mang4}";

                // When looking for cooldown, Kiss cooldown will apply globally if player involved, if not, ony apply for involved actors.
                if ($mang4==trim($GLOBALS["PLAYER_NAME"]))
                    $GLOBALS["PATCH_ACTION_ALL_ACTORS"]="*";
                else
                    $GLOBALS["PATCH_ACTION_ALL_ACTORS"]="{$actionParts[0]},{$mang4}";

                error_log("[ACTION POSTFILTER ExtCmdKiss] $localtarget => {$mang3[0]} => $mang4. Cooldown will apply to {$GLOBALS["PATCH_ACTION_ALL_ACTORS"]}");
            
            } else  if ($actionParts2[0]=="ExtCmdStartBlowJob") {
                // Lets polish the parameters
                $localtarget=$actionParts2[1];
                $mang1=explode(",",$localtarget);
                $mang2=explode(" and ",$mang1[0]);
                $mang3=explode("(",$mang2[0]);

                if (empty(trim($mang3[0])))
                    $mang4=trim($GLOBALS["PLAYER_NAME"]);
                else
                    $mang4=trim(FindClosestNPCName($mang3[0]));

                if (empty($mang4))
                    $mang4=$mang3[0];


                    
                $actions[$n]="{$actionParts[0]}|{$actionParts[1]}|ExtCmdStartBlowJob@{$mang4}";

                error_log("[ACTION POSTFILTER ExtCmdStartBlowJob] $localtarget => {$mang3[0]} => $mang4");
            } else  if ($actionParts2[0]=="ExtCmdStartMassage") {
                // Lets polish the parameters
                $localtarget=$actionParts2[1];
                $mang1=explode(",",$localtarget);
                $mang2=explode(" and ",$mang1[0]);
                $mang3=explode("(",$mang2[0]);

                if (empty(trim($mang3[0])))
                    $mang4=trim($GLOBALS["PLAYER_NAME"]);
                else
                    $mang4=trim(FindClosestNPCName($mang3[0]));

                if (empty($mang4))
                    $mang4=$mang3[0];


                    
                $actions[$n]="{$actionParts[0]}|{$actionParts[1]}|ExtCmdStartMassage@{$mang4}";

                error_log("[ACTION POSTFILTER ExtCmdStartMassage] $localtarget => {$mang3[0]} => $mang4");
           
            } else  if ($actionParts2[0]=="ExtCmdStartThreesome") {
                // Lets polish the parameters
                $localtarget=$actionParts2[1];
                $mang1=explode(",",$localtarget);
                

                $sanitized=[];
                if (!is_array($mang1)) {
                    error_log("[ACTION POSTFILTER ExtCmdStartThreesome] Error $localtarget ");

                } else {
                    foreach ($mang1 as $participator) {
                        $sanitized[]=trim($participator);

                    }


                }
                $mang4=implode(",",$sanitized);
                $GLOBALS["PATCH_ACTION_ALL_ACTORS"]="*";//Improve this
                    
                $actions[$n]="{$actionParts[0]}|{$actionParts[1]}|ExtCmdStartThreesome@{$mang4}";

                error_log("[ACTION POSTFILTER ExtCmdStartThreesome] $localtarget => {$mang3[0]} => $mang4");
            }
        }
    }

    // NPC-to-NPC INITIATION GATE (user directive 2026-07-05): after target resolution, drop any sex-scene
    // START whose speaker is an NPC and whose target is ANOTHER NPC unless A->B is eligible (affinity + rel
    // type; prostitute A bypasses, slave A is player-only). Player-directed scenes are NEVER touched (empty
    // target defaults to the player downstream; player named in a threesome CSV counts as player-directed).
    $__nnSpeaker = trim((string)($GLOBALS["HERIKA_NAME"] ?? ""));
    $__nnPlayer  = trim((string)($GLOBALS["PLAYER_NAME"] ?? ""));
    if ($__nnSpeaker !== "" && function_exists('aiagentNsfwNpcToNpcSexEligible')) {
        $__nnStarts = ["ExtCmdStartSex","ExtCmdStartBlowJob","ExtCmdStartAnalSex","ExtCmdStartThreesome","ExtCmdStartTitfuck","ExtCmdStartHandJobSex","ExtCmdStartHandjobSex","ExtCmdStartMassage","ExtCmdDrinkBloodSex"];
        foreach ($actions as $__nnN => $__nnAction) {
            $__nnP = explode("|", $__nnAction);
            if (!isset($__nnP[2])) { continue; }
            $__nnCT = explode("@", $__nnP[2]);
            if (!in_array($__nnCT[0], $__nnStarts, true)) { continue; }
            $__nnTgt = trim((string)($__nnCT[1] ?? ""));
            if ($__nnTgt === "" || strcasecmp($__nnTgt, $__nnSpeaker) === 0) { continue; }               // empty/self
            if ($__nnPlayer !== "" && stripos($__nnTgt, $__nnPlayer) !== false) { continue; }            // player-directed (incl. threesome CSV)
            $__nnFirst = trim(explode(",", $__nnTgt)[0]);                                                 // first named partner drives eligibility
            if ($__nnFirst === "" || ($__nnPlayer !== "" && strcasecmp($__nnFirst, $__nnPlayer) === 0)) { continue; }
            if (!aiagentNsfwNpcToNpcSexEligible($__nnSpeaker, $__nnFirst)) {
                error_log("[AIAGENTNSFW] NPC-NPC sex BLOCKED: {$__nnSpeaker} not eligible to start with {$__nnFirst} (affinity/rel-type gate)");
                unset($actions[$__nnN]);
            } else {
                error_log("[AIAGENTNSFW] NPC-NPC sex ALLOWED: {$__nnSpeaker} -> {$__nnFirst} (affinity/rel-type gate passed)");
            }
        }
        $actions = array_values($actions);
    }

    // AFFECTION LEGACY-ANIM FLAG (user directive 2026-07-05): when the opt-out is ON, tag Kiss/Hug with a
    // trailing @legacy token so the mod script uses the lightweight legacy animation instead of a persistent
    // OStim scene. Default OFF = OStim (shipped behavior). HoldHands is always OStim (no legacy anim exists).
    if (function_exists('_getNsfwSetting') && _getNsfwSetting('NSFW_AFFECTION_LEGACY_ANIMS', false)) {
        foreach ($actions as $__laN => $__laAction) {
            $__laP = explode("|", $__laAction);
            if (!isset($__laP[2])) { continue; }
            $__laCmd = explode("@", $__laP[2])[0];
            if (($__laCmd === "ExtCmdKiss" || $__laCmd === "ExtCmdHug") && stripos($__laP[2], "@legacy") === false) {
                $actions[$__laN] = $__laP[0] . "|" . $__laP[1] . "|" . $__laP[2] . "@legacy";
            }
        }
    }

    return $actions;
};

// Get configurable sex cooldown (converts game hours to cooldown units)
$_npcSexCooldown = gameHoursToCooldownUnits(getNpcSexCooldownHours());
$_sexCooldownActive = $_npcSexCooldown > 0;  // False if cooldown is disabled (0 hours)

// Non-sex action cooldowns (shorter, fixed values)
$GLOBALS["COOLDOWNMAP"]["ExtCmdStartMassage"]=300/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdRemoveClothes"]=300/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdPutOnClothes"]=100/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdKiss"]=100/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdHoldHands"]=100/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdHug"]=300/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdStopScene"]=30/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdConsumeSoul"]=300/0.00864;

// Sex action cooldowns - use configurable value (0 = disabled)
if ($_sexCooldownActive) {
    $GLOBALS["COOLDOWNMAP"]["ExtCmdStartSelfMasturbation"]=$_npcSexCooldown;
    $GLOBALS["COOLDOWNMAP"]["ExtCmdStartBlowJob"]=$_npcSexCooldown;
    $GLOBALS["COOLDOWNMAP"]["ExtCmdStartSex"]=$_npcSexCooldown;
    $GLOBALS["COOLDOWNMAP"]["ExtCmdStartThreesome"]=$_npcSexCooldown;
    $GLOBALS["COOLDOWNMAP"]["ExtCmdStartTitfuck"]=$_npcSexCooldown;
    $GLOBALS["COOLDOWNMAP"]["ExtCmdStartAnalSex"]=$_npcSexCooldown;
    $GLOBALS["COOLDOWNMAP"]["ExtCmdStartHandJobSex"]=$_npcSexCooldown;
}
// Note: When cooldown is 0/disabled, these commands have no cooldown entry (unlimited use)

// Scene control cooldowns (shorter, not affected by slider)
$GLOBALS["COOLDOWNMAP"]["ExtCmdSexCommand"]=15/0.00864;

// Affinity-gated functions (shorter cooldowns for decision-making)
$GLOBALS["COOLDOWNMAP"]["ExtCmdAcceptSex"]=60/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdRefuseSex"]=30/0.00864;

// Prostitute payment function
$GLOBALS["COOLDOWNMAP"]["ExtCmdCollectPayment"]=60/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdPoisonMasterFood"]=gameHoursToCooldownUnits((int)_getNsfwSetting('SLAVERY_POISON_COOLDOWN_GAME_HOURS', 72));


/******
VR Event Detection Functions (HIGGS/CBPC)
These are toggles - logic is in nsfw_physics.php and vr_items.php
******/

// VR Touch Detection (CBPC physics collision)
$GLOBALS["F_NAMES"]["ExtCmdVRTouch"]="VRTouchDetection";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdVRTouch"]="Enable VR touch detection (CBPC physics). NPCs react when player touches them in VR.";
// No FUNCTIONS[] entry - this is an event toggle, not an AI-callable function

// VR Grab Detection (HIGGS grab on body parts)
$GLOBALS["F_NAMES"]["ExtCmdVRGrab"]="VRGrabDetection";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdVRGrab"]="Enable VR grab detection (HIGGS). NPCs react when player grabs them in VR.";
// No FUNCTIONS[] entry - this is an event toggle, not an AI-callable function

// VR Item Detection (HIGGS pickup/drop items)
$GLOBALS["F_NAMES"]["ExtCmdVRItem"]="VRItemDetection";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdVRItem"]="Enable VR item awareness (HIGGS). NPCs notice when player picks up or puts down items.";
// No FUNCTIONS[] entry - this is an event toggle, not an AI-callable function

// Add to DEFINED_FUNCTIONS so they show up in Action Editor as toggleable checkboxes
$GLOBALS["DEFINED_FUNCTIONS"][] = "ExtCmdVRTouch";
$GLOBALS["DEFINED_FUNCTIONS"][] = "ExtCmdVRGrab";
$GLOBALS["DEFINED_FUNCTIONS"][] = "ExtCmdVRItem";


// Consent is now ALWAYS model-driven (the "Enable Affinity Gating" checkbox was removed). The NPC decides from
// the relationship tier prompt + context; there is no hard accept gate. The romanticized floor (Fond+) plus the
// prostitute payment gate and slave-forced handling are the only structural gates, enforced downstream. Kept as a
// function (not deleted) so the existing call sites stay valid.
function isAffinityGatingEnabled() {
    return false;
}

function aiagentNsfwAddEnabledFunction($functionName) {
    if (!isset($GLOBALS["ENABLED_FUNCTIONS"]) || !is_array($GLOBALS["ENABLED_FUNCTIONS"])) {
        $GLOBALS["ENABLED_FUNCTIONS"] = [];
    }
    if (!in_array($functionName, $GLOBALS["ENABLED_FUNCTIONS"], true)) {
        $GLOBALS["ENABLED_FUNCTIONS"][] = $functionName;
    }
}

function aiagentNsfwSuppressCoreTakeGoldFromPlayerAction() {
    if (!empty($GLOBALS["AIAGENTNSFW_SUPPRESS_CORE_TAKE_GOLD_HOOKED"])) {
        return;
    }
    $GLOBALS["AIAGENTNSFW_SUPPRESS_CORE_TAKE_GOLD_HOOKED"] = true;
    $GLOBALS["HOOKS"]["JSON_TEMPLATE"][] = function() {
        $GLOBALS["FUNC_LIST"] = array_values(array_filter(
            is_array($GLOBALS["FUNC_LIST"] ?? null) ? $GLOBALS["FUNC_LIST"] : [],
            function($actionName) {
                return stripos((string)$actionName, 'Take_Gold_From_') !== 0;
            }
        ));

        if (isset($GLOBALS["PROMPT_ACTIONS_LIST"])) {
            $GLOBALS["PROMPT_ACTIONS_LIST"] = preg_replace(
                '/\nAVAILABLE ACTION: Take_Gold_From_[^\n]*/',
                '',
                (string)$GLOBALS["PROMPT_ACTIONS_LIST"]
            );
        }

        if (isset($GLOBALS["responseTemplate"]["action"])) {
            $actions = array_values(array_filter(explode('|', (string)$GLOBALS["responseTemplate"]["action"]), function($actionName) {
                return stripos(trim((string)$actionName), 'Take_Gold_From_') !== 0;
            }));
            $GLOBALS["responseTemplate"]["action"] = implode('|', $actions);
        }

        $actionProperty =& $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"]["action"];
        if (is_array($actionProperty ?? null) && is_array($actionProperty["enum"] ?? null)) {
            $actionProperty["enum"] = array_values(array_filter($actionProperty["enum"], function($actionName) {
                return stripos(trim((string)$actionName), 'Take_Gold_From_') !== 0;
            }));
        }
    };
}

function aiagentNsfwShouldExposeSlavePoison($npcName, $intimacyStatus, $affinity) {
    if (!_getNsfwSetting('SLAVERY_POISON_ENABLED', false)) {
        return false;
    }
    if (!isNpcSlave($npcName)) {
        return false;
    }

    $sceneOnly = _getNsfwSetting('SLAVERY_POISON_SCENE_ONLY', true);
    $level = (int)($intimacyStatus["level"] ?? 0);
    if ($sceneOnly && $level < 1) {
        return false;
    }

    $tier = getAffinityTierName((int)$affinity);
    $rank = [
        'hostile' => 0,
        'hateful' => 1,
        'resentful' => 2,
        'cold' => 3,
        'wary' => 4,
        'neutral' => 5,
        'acquaintance' => 6,
        'friendly' => 7,
        'fond' => 8,
        'devoted' => 9,
        'bonded' => 10,
    ];
    $minTier = (string)_getNsfwSetting('SLAVERY_POISON_MIN_TIER', 'hateful');
    if (!isset($rank[$minTier])) {
        $minTier = 'hateful';
    }
    if (($rank[$tier] ?? 10) > $rank[$minTier]) {
        return false;
    }

    $chance = ($tier === 'hostile')
        ? (int)_getNsfwSetting('SLAVERY_POISON_HOSTILE_CHANCE', 100)
        : (int)_getNsfwSetting('SLAVERY_POISON_HATEFUL_CHANCE', 25);
    $chance = max(0, min(100, $chance));
    if ($chance <= 0) {
        return false;
    }
    if ($chance >= 100) {
        return true;
    }
    return random_int(1, 100) <= $chance;
}

// DEBUG: Log what gameRequest looks like
$gameReqSet = isset($GLOBALS["gameRequest"]) ? "SET" : "NOT_SET";
$gameReq0 = $GLOBALS["gameRequest"][0] ?? "NULL";
error_log("[AIAGENTNSFW-FUNCS] gameRequest={$gameReqSet} gameRequest[0]={$gameReq0} HERIKA_NAME=" . ($GLOBALS["HERIKA_NAME"] ?? "NULL"));

$_nsfwFunctionEvent = $GLOBALS["gameRequest"][0] ?? "";
$_nsfwNpcRouteEvents = ["ext_nsfw_npc_scene", "ext_nsfw_npc_invite", "ext_nsfw_npc_orgasm", "chatnf_npc_sl"];
$_nsfwExplicitNpcFunctionRoute = in_array($_nsfwFunctionEvent, $_nsfwNpcRouteEvents, true);
$_nsfwNpcSceneDialogueEnabled = function_exists('_getNsfwSetting') ? _getNsfwSetting('NPC_SCENE_LLM_ENABLED', true) : true;

// Heavy intoxication LOCKS the scene EXIT (NOT the refusal): a deeply drunk (>= DRUNK_SCENE_LOCK_STAGE) or
// Sleeping-Tree-Sap-dosed NPC can STILL RefuseSex (protest / say no -> the relationship model applies the
// consequences), but is too far gone to END the scene. Single source = aiagentNsfwSceneExitLocked (common.php),
// also used by the refusal router + the server stop helper. Withholds ONLY EndSceneEarly + EndNpcScene.
$sceneExitLocked = !empty($GLOBALS["HERIKA_NAME"]) && function_exists('aiagentNsfwSceneExitLocked')
    && aiagentNsfwSceneExitLocked($GLOBALS["HERIKA_NAME"]);
if ($sceneExitLocked) {
    error_log("[AIAGENTNSFW] SCENE-EXIT LOCKED for " . $GLOBALS["HERIKA_NAME"] . " (drunk/sap) - EndSceneEarly + EndNpcScene withheld; RefuseSex still allowed");
}

if (isset($GLOBALS["gameRequest"]) && $GLOBALS["gameRequest"][0]!="instruction" && $GLOBALS["gameRequest"][0]!="funcret") {
    $intimacyStatus=getIntimacyForActor($GLOBALS["HERIKA_NAME"]);
    $sexDisposalEnabled = isSexDisposalEnabled();
    $affinityGatingEnabled = isAffinityGatingEnabled();
    $npcName = $GLOBALS["HERIKA_NAME"];
    $isProstitute = isProstitute($npcName);
    $isSlave = isNpcSlave($npcName);
    $isNpcScene = $_nsfwExplicitNpcFunctionRoute || (!empty($intimacyStatus["is_npc_scene"]) && $_nsfwNpcSceneDialogueEnabled);
    $npcSceneGateDisabled = $isNpcScene;
    $affinity = getNpcAffinity($npcName);
    // L3 skooma overrides the sex pathway (addict bargain) even for a prostitute: unlock the sex acts
    // independent of the payment gate. Slaves excluded (separate override). L3-gated, so no effect otherwise.
    $skoomaBargain = !$isSlave && function_exists('getDrugStageForActor') && (int)getDrugStageForActor($npcName, 'skooma') >= 3;
    $openModeAdultPlayerRoute = !$isSlave && !$isProstitute && !$isNpcScene && !$skoomaBargain
        && function_exists('aiagentNsfwOpenMode') && aiagentNsfwOpenMode()
        && function_exists('aiagentNsfwIsChildNpc') && !aiagentNsfwIsChildNpc($npcName);

    // SCENE-CALL AFFINITY GATE (user directive 2026-06-29): the relationship floor at which an NPC may autonomously
    // CALL/initiate an OStim/SexLab sex scene with the player. Default 56 = Fond (romance tier). Adjustable in the UI
    // so the player can require a higher tier (e.g. 76 = Devoted) or lower it. Player consent (accepted_sex), slave,
    // paid prostitute, skooma-bargain and NPC-scene routes are separate unlocks and bypass this floor as before.
    // Promiscuous-marked NPCs drop to the Acquaintance(6) floor via aiagentNsfwSceneCallFloorFor.
    $sceneCallMinAffinity = function_exists('aiagentNsfwSceneCallFloorFor')
        ? (int)aiagentNsfwSceneCallFloorFor($npcName)
        : (int)_getNsfwSetting('NSFW_SCENE_CALL_MIN_AFFINITY', 56);

    // PROSTITUTE RULE: a prostitute may NOT call/drive OStim sex scenes with the player until the service is
    // actually covered - payment confirmed/bartered, auto-waived (bonded), or deliberately given free via
    // GiveFreeService (offered only at Devoted+). accepted_sex alone does NOT unlock her; gold or a chosen
    // free service does. (Skooma L3 addict-bargain and NPC-to-NPC routes remain separate overrides.)
    $prostituteServiceUnlocked = $isProstitute && (
        !empty($intimacyStatus["payment_confirmed"])
        || !empty($intimacyStatus["free_service"])
        || aiagentNsfwProstitutePaymentWaived($npcName)
    );

// Only offer this action if sex disposal is >20 for this actor (when gating enabled)
    if (isset($intimacyStatus["level"])&&$intimacyStatus["level"]==0) {

        // Player-started OStim gates use one accept/refuse lane. Non-sex affection is handled as
        // context or concrete actions (Hug/Kiss/HoldHands), not a consent tool.
        $isAffectionPhase = (($intimacyStatus["scene_phase"] ?? '') === "affection");
        $isRejectedPhase = (($intimacyStatus["scene_phase"] ?? '') === "rejected");
        if ($isProstitute && empty($intimacyStatus["payment_confirmed"]) && !aiagentNsfwProstitutePaymentWaived($npcName)) {
            // ADDITIVE: keep ALL core CHIM functions (inventory, give/take item, movement, etc.). Only
            // suppress the redundant core gold-grab so payment flows through Sharmat's TakeGold; NSFW tools
            // are added below. She can still open/check her inventory to confirm barter (e.g. fire salts + gold).
            aiagentNsfwSuppressCoreTakeGoldFromPlayerAction();
            error_log("[AIAGENTNSFW] Unpaid prostitute level-0: core functions preserved, NSFW tools added for " . $npcName);
        }

        // ORIENTATION AFFECTION GATE (user directive 2026-07-05): tier-1/2 affection tools (Kiss/Hug/HoldHands)
        // require the NPC's sexual orientation to allow the player's gender. Slaves (compliance lane) and
        // prostitutes (transactional lane) bypass. Fails OPEN on unknown/missing data - only an explicit
        // mismatch or an asexual NPC blocks. Sex stays gated by rel-type eligibility (which needs crush/romantic,
        // itself now orientation-gated at crush formation), so orientation flows into sex transitively.
        $affectionOrientationOk = $isSlave || $isProstitute
            || !function_exists('aiagentNsfwOrientationAllowsPlayer')
            || aiagentNsfwOrientationAllowsPlayer($npcName);

        if ($sexDisposalEnabled && !$isProstitute) {
            // Arousal gating enabled, but only AFTER the relationship/model gate.
            // Before AcceptSex, the model can only accept/refuse or use affection actions.
            $currentArousal = $intimacyStatus["sex_disposal"] ?? 0;
            $isNaked = $intimacyStatus["is_naked"] ?? 0;
            // Fond+ (>=56): a regular NPC has her OWN autonomy to initiate sex - she does not need AcceptSex.
            $_consentedFns = !empty($intimacyStatus["accepted_sex"]) || $isSlave || $npcSceneGateDisabled || $skoomaBargain || ($affinity >= $sceneCallMinAffinity);
            // Sticky refusal also blocks re-initiating sex acts until the scene ends.
            if (!$isSlave && !empty($intimacyStatus["refused_until_scene_end"])) { $_consentedFns = false; }

            // Affection floor is per-NPC: promiscuous mark drops it to Acquaintance(6), everyone else Fond(56).
            $affectionFloor = function_exists('aiagentNsfwAffectionFloorFor') ? (int)aiagentNsfwAffectionFloorFor($npcName) : 56;
            if (($affinity >= $affectionFloor && $affectionOrientationOk) || $isSlave) { $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdKiss"; } // affection needs the per-NPC floor + orientation match
            // Initiation autonomy follows the same per-NPC scene-call floor as the toolset (was hardcoded 56,
            // which left a promiscuous NPC holding sex tools at Acquainted with no cue she may use them).
            if ($_consentedFns && $affinity >= $sceneCallMinAffinity && (!function_exists('aiagentNsfwRelTypeSexEligible') || aiagentNsfwRelTypeSexEligible($relGateNpc ?? ($GLOBALS['HERIKA_NAME'] ?? '')))) {
                $GLOBALS['AIAGENTNSFW_INITIATION_AUTONOMY'] = true; // context_pre injects the initiation nudge (OStim audit fix 1)
            } elseif ($affinity >= $affectionFloor && $affectionOrientationOk) {
                // Fond+ AND orientation-compatible but rel type not eligible: the courtship lane. She holds the
                // affection tools; chosen affection flips her to crush, which opens the full gate.
                $GLOBALS['AIAGENTNSFW_AFFECTION_AUTONOMY'] = true;
            }
            if ($_consentedFns) {
                if ($currentArousal >= (int)aiagentNsfwArousalNum('AROUSAL_THRESHOLD_UNDRESS', 5)) {
                    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRemoveClothes";
                }
                if ($currentArousal >= (int)aiagentNsfwArousalNum('AROUSAL_THRESHOLD_FOREPLAY', 10)) {
                    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartMassage";
                    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartSelfMasturbation";
                    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartHandJobSex";

                }
                if ($currentArousal >= (int)aiagentNsfwArousalNum('AROUSAL_THRESHOLD_SEX', 20)) {
                    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartBlowJob";
                    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartSex";
                    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartThreesome";
                    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartTitfuck";
                    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartAnalSex";
                    // Vampire-only: DrinkBloodSex rides the same consent gate as the sex acts above, plus a race check.
                    if (function_exists('aiagentNsfwIsVampireNpc') && aiagentNsfwIsVampireNpc($npcName)) { $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdDrinkBloodSex"; }

                }
                error_log("[AIAGENTNSFW] sex_disposal ENABLED - consented, arousal={$currentArousal}, isNaked={$isNaked} for " . $npcName);
            } else {
                error_log("[AIAGENTNSFW] sex_disposal ENABLED - NOT consented, sex acts WITHHELD before relationship/model gate for " . $npcName);
            }
        } else {
            // Arousal gating disabled. CONSENT GATE: affection (Kiss) needs Fond+, and the SEX-act
            // initiators only unlock once she has consented (AcceptSex / slave / paid prostitute). Until then
            // the model gets only Kiss + Accept/Refuse - so it CANNOT blow past the relationship gate by
            // calling StartSex directly. Marriage/affair/normal all require the explicit AcceptSex.
            // Affection floor is per-NPC: promiscuous mark drops it to Acquaintance(6), everyone else Fond(56).
            $affectionFloor = function_exists('aiagentNsfwAffectionFloorFor') ? (int)aiagentNsfwAffectionFloorFor($npcName) : 56;
            if (($affinity >= $affectionFloor && $affectionOrientationOk) || $isSlave) { $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdKiss"; }
            if ($isProstitute) {
                // Prostitutes: OStim sex acts gated on the service being covered (paid/free), NOT accepted_sex.
                $_consentedFns = $prostituteServiceUnlocked || $isSlave || $npcSceneGateDisabled || $skoomaBargain;
            } else {
                // Fond+ (>=56): a regular NPC has her OWN autonomy to initiate sex - she does not need AcceptSex.
                $_consentedFns = !empty($intimacyStatus["accepted_sex"]) || $isSlave || $npcSceneGateDisabled || $skoomaBargain || ($affinity >= $sceneCallMinAffinity);
            }
            // Sticky refusal also blocks re-initiating sex acts until the scene ends.
            if (!$isSlave && !empty($intimacyStatus["refused_until_scene_end"])) { $_consentedFns = false; }
            if ($_consentedFns) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRemoveClothes";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartMassage";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartSelfMasturbation";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartHandJobSex";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartBlowJob";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartSex";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartThreesome";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartTitfuck";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartAnalSex";
                // Vampire-only: DrinkBloodSex rides the same consent gate as the sex acts above, plus a race check.
                if (function_exists('aiagentNsfwIsVampireNpc') && aiagentNsfwIsVampireNpc($npcName)) { $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdDrinkBloodSex"; }
                error_log("[AIAGENTNSFW] Arousal OFF - consented, sex functions unlocked for " . $npcName);
            } else {
                error_log("[AIAGENTNSFW] Arousal OFF - NOT consented, sex acts WITHHELD (Kiss/Accept/Refuse only) for " . $npcName);
            }
        }

        // Affection needs the per-NPC floor (Fond 56; Acquaintance 6 for promiscuous marks) AND orientation
        // compatibility; below that no hugs or hand-holding (slaves keep theirs - the slavery lane owns
        // compliance; prostitutes bypass orientation via $affectionOrientationOk).
        // Was unconditional, so hostile/stranger NPCs could call hand-holding, blipping OStim idles and the sex-ask machinery.
        if (($affinity >= (function_exists('aiagentNsfwAffectionFloorFor') ? (int)aiagentNsfwAffectionFloorFor($npcName) : 56) && $affectionOrientationOk) || $isSlave) {
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdHug";
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdHoldHands";
        }
        // Always available
        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdPutOnClothes";
        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdConsumeSoul";

        // ===== AFFINITY-GATED FUNCTIONS =====
        // RefuseSex (protest / say no) stays available unless the path suppresses refusal
        // (slave or NPC-to-NPC route). NPC-to-NPC dialogue does not get hard scene-control tools.
        if (!$isSlave && !$npcSceneGateDisabled && !$isNpcScene) {
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRefuseSex";
        }

        // AcceptSex is a consent bark for an EXPLICIT (tier-3) sex scene that is awaiting the NPC's decision. Offering
        // it during normal conversation or a tier 1-2 affection beat let the model fire it prematurely (user report:
        // "Vivian got to select AcceptSex early"). Require scene_phase==tier_prompt AND intensity_tier>=3. Prostitutes
        // are exempt below (they use AcceptSex to accept a paid transaction, not a tier-3 scene).
        $isExplicitTierPrompt = (($intimacyStatus["scene_phase"] ?? '') === "tier_prompt")
            && ((int)($intimacyStatus["intensity_tier"] ?? 0) >= 3);

        if ($affinityGatingEnabled) {
            // Affinity gating enabled - tier prompts guide LLM behavior
            // All functions available, LLM decides based on tier prompts

            // === REGULAR NPC FUNCTIONS ===
            if (!$isProstitute && !$isRejectedPhase && !$npcSceneGateDisabled && !$isNpcScene && !$openModeAdultPlayerRoute && $isExplicitTierPrompt) {
                $GLOBALS["ENABLED_FUNCTIONS"][]= "ExtCmdAcceptSex";
            }

            // === PROSTITUTE-SPECIFIC FUNCTIONS ===
            // AcceptSex auto-detects prostitute and handles as transaction
            // TakeGold directly takes gold via PaymentHandler (bypasses consent)
            if ($isProstitute && !$isRejectedPhase) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdAcceptSex";  // Prostitutes use AcceptSex for transactions
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdCollectPayment";  // TakeGold directly
                // Bonded/love tier only: she can choose to stop selling herself (matches the top affinity prompt)
                if ($affinity >= 91) {
                    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdQuitProstitution";
                }
                // Devoted+ (aff>=76): she MAY choose to give this one free (no price modifier), distinct from
                // permanently quitting. Only meaningful while unpaid AND not already auto-waived (bonded auto-free).
                if ($affinity >= 76 && empty($intimacyStatus["payment_confirmed"]) && !aiagentNsfwProstitutePaymentWaived($npcName)) {
                    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdGiveFreeService";
                }
            }

        } else {
            // Affinity gating disabled - all affinity functions available
            if (!$isRejectedPhase && !$npcSceneGateDisabled && !$isNpcScene && !$openModeAdultPlayerRoute && ($isExplicitTierPrompt || $isProstitute)) {
                $GLOBALS["ENABLED_FUNCTIONS"][]= "ExtCmdAcceptSex";
            }
            if ($isProstitute && !$isRejectedPhase) {
                // TakeGold directly takes gold via PaymentHandler
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdCollectPayment";
                // Gating off = model-driven: she may also choose to give the service free instead of charging.
                if (empty($intimacyStatus["payment_confirmed"]) && !aiagentNsfwProstitutePaymentWaived($npcName)) {
                    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdGiveFreeService";
                }
            }
            error_log("[AIAGENTNSFW] Affinity gating disabled - all affinity functions available");
        }

        if (aiagentNsfwShouldExposeSlavePoison($npcName, $intimacyStatus, $affinity)) {
            aiagentNsfwAddEnabledFunction("ExtCmdPoisonMasterFood");
        }

        // === SLAVE-SPECIFIC FUNCTIONS ===
        // Worship is always available to a slave (she may choose to pray to her master). Ask/Accept Freedom are
        // a handshake: AskForFreedom is a REQUEST (only if the master enabled it via the SLAVERY_ALLOW_ASK_FREEDOM
        // UI toggle); AcceptFreedom is surfaced ONLY while a request is pending, and the slave prompt instructs
        // her to use it only if the master EXPLICITLY agreed.
        if ($isSlave) {
            // Worship is offered only to Devoted+ slaves (aff >= 76): a low / negative-affinity slave will not
            // willingly kneel and pray to her master (user directive 2026-06-29).
            if ($affinity >= 76) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdWorshipMaster";
            }
            $freedomPending = !empty($intimacyStatus["freedom_requested"]);
            // BONDED-ONLY (user directive 2026-06-29): a slave may only ASK for freedom once Bonded (aff >= 91) - the
            // deepest trust tier. Below Bonded the request is never offered. Still also gated on the master having
            // enabled it via the SLAVERY_ALLOW_ASK_FREEDOM toggle.
            if (!$freedomPending && $affinity >= 91 && _getNsfwSetting("SLAVERY_ALLOW_ASK_FREEDOM", false)) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdAskForFreedom";
            }
            if ($freedomPending) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdAcceptFreedom";
            }
        }

        // GLOBAL SCENE-CALL COOLDOWN (user directive 2026-06-29): hold back ALL sex-scene initiators (NOT affection,
        // NOT Accept/Refuse) for a short window after the player's last scene, so multiple NPCs can't bombard the
        // player with scene-calls back to back. Player scenes only; NPC-to-NPC is handled separately. Setting 0 = off.
        if (!$isNpcScene && (aiagentNsfwPlayerSceneCallCooldownActive() || aiagentNsfwWhiskeyDickActive())) {
            $sceneCallInitiators = ["ExtCmdStartSex","ExtCmdStartBlowJob","ExtCmdStartAnalSex","ExtCmdStartMassage","ExtCmdStartThreesome","ExtCmdStartHandJobSex","ExtCmdStartTitfuck","ExtCmdStartSelfMasturbation","ExtCmdDrinkBloodSex","ExtCmdSexCommand","ExtCmdQuickenPace","ExtCmdSlowPace"];
            $GLOBALS["ENABLED_FUNCTIONS"] = array_values(array_filter($GLOBALS["ENABLED_FUNCTIONS"], function($f) use ($sceneCallInitiators) { return !in_array($f, $sceneCallInitiators, true); }));
            $_whyHeld = aiagentNsfwWhiskeyDickActive() ? "whiskey dick - player impotent for the duration window" : "global scene-call cooldown (anti-bombardment)";
            error_log("[AIAGENTNSFW] Sex-scene initiators withheld for {$npcName} - {$_whyHeld}");
        }

        // ============================================================
        // HARD STATE MACHINE - PLAYER CONSENT DECISION (user directive 2026-06-29)
        // ============================================================
        // PHP owns the scene state; the model is just a text generator behind it. In a PLAYER scene that has reached
        // explicit (tier 3) and she has NOT decided (no accepted_sex, no refusal), the ONLY tools she may call are
        // AcceptSex / RefuseSex - hard strip. All the determination context (overhead, affinity, spouse, orientation,
        // feelings) still reaches her via the personality + tier cue; only the TOOLSET is narrowed here. prerequest
        // sets the dumb decision cue and strips the speak style for this same state. The instant she answers AcceptSex,
        // the accepted path fires speech style + kinks on the response turn. NPC-NPC / slave / prostitute excluded
        // (separate paths). NPC-initiated player scenes are auto-accepted server-side, so they never reach this.
        // FOND+ AUTONOMY BYPASS (user directive 2026-07-01): an eligible, Fond+ NPC already has her OWN agency to
        // initiate/drive a scene (the consent block above grants her the full sex toolset at affinity >= the scene-call
        // floor). Hard-locking her to AcceptSex/RefuseSex-only here CONTRADICTS that and is exactly why an eligible
        // Fond+ NPC "only ever got to pick AcceptSex" and could never call/steer a scene herself. She keeps RefuseSex
        // (she can still say no); a refusal already in progress (refused_until_scene_end) re-imposes the lock.
        $sceneCallMinAffinity = function_exists('aiagentNsfwSceneCallFloorFor')
            ? (int)aiagentNsfwSceneCallFloorFor($npcName)   // promiscuous mark drops her floor to Acquaintance(6)
            : (int)_getNsfwSetting('NSFW_SCENE_CALL_MIN_AFFINITY', 56);
        $isAutonomousFondPlus = empty($intimacyStatus["refused_until_scene_end"])
            && ($openModeAdultPlayerRoute
                || (((int)$affinity >= $sceneCallMinAffinity)
                    && function_exists('aiagentNsfwRelTypeSexEligible')
                    && aiagentNsfwRelTypeSexEligible($npcName)));
        $playerNeedsDecision =
            empty($intimacyStatus["is_npc_scene"])
            && (int)($intimacyStatus["intensity_tier"] ?? 0) >= 3
            && empty($intimacyStatus["scene_is_idle"])
            && empty($intimacyStatus["accepted_sex"])
            && empty($intimacyStatus["refusal_expressed"])
            && !$isSlave
            && !$isProstitute
            && !$skoomaBargain
            && !$npcSceneGateDisabled
            && !$isAutonomousFondPlus;
        if ($playerNeedsDecision) {
            $GLOBALS["ENABLED_FUNCTIONS"] = ["ExtCmdAcceptSex", "ExtCmdRefuseSex"];
            error_log("[AIAGENTNSFW] PLAYER DECISION PHASE for {$npcName}: toolset stripped to AcceptSex/RefuseSex only (tier 3, undecided)");
        } else if ((int)($intimacyStatus["intensity_tier"] ?? 0) >= 3 && empty($intimacyStatus["accepted_sex"]) && $isAutonomousFondPlus) {
            error_log("[AIAGENTNSFW] Fond+ autonomy bypass for {$npcName}: keeps initiator toolset at tier 3 (affinity {$affinity} >= {$sceneCallMinAffinity}, rel-type eligible)");
        }

    } else if (isset($intimacyStatus["level"])&&$intimacyStatus["level"]>=1) {
        // ADDITIVE: do NOT wipe the core function list during an active scene - every NPC keeps ALL core
        // CHIM tools (inventory, movement, etc.) at all times. Only the NSFW scene tools below are gated.
        // CONSENT GATE: only a consenting NPC may DRIVE the scene (SexCommand). A not-yet-consented / refusing
        // NPC gets only Accept/Refuse/Stop - never the scene driver. Consent by class: slave=forced,
        // prostitute=ONLY once paid (the gold), marriage=spouse consents by type, affair/regular=accepted_sex
        // (set by AcceptSex or the affinity auto-decision; affair is affinity-gated upstream).
        $_consentPartner = $GLOBALS["PLAYER_NAME"] ?? "Player";
        if (is_array($intimacyStatus["scene_actors"] ?? null)) {
            foreach ($intimacyStatus["scene_actors"] as $_sa) {
                if (strtolower($_sa) !== strtolower($npcName)) { $_consentPartner = $_sa; break; }
            }
        }
        if ($isProstitute) {
            $_consented = $prostituteServiceUnlocked;  // paid / bartered / auto-waived (bonded) / GiveFreeService - never accepted_sex alone
        } else {
            // Regular/marriage/affair. Fond+ (>=56) = her own autonomy (no AcceptSex needed). AND once a scene
            // is underway it STAYS consented until it ends (sex_started/had_sex_in_scene) - never re-gate her
            // mid-scene. Low-affinity NPCs (< Fond, e.g. a despising spouse) still gate on accepted_sex.
            $_consented = $isSlave || $npcSceneGateDisabled || $openModeAdultPlayerRoute || !empty($intimacyStatus["accepted_sex"])
                || ($affinity >= 56) || !empty($intimacyStatus["sex_started"]) || !empty($intimacyStatus["had_sex_in_scene"]);
            // REFUSAL DOMINATES: once she refuses, she stays non-consenting (no SexCommand, gets the refuse prompt)
            // until the engine scene actually ends - even though sex_started/had_sex_in_scene above would otherwise
            // re-consent her mid-scene. Slaves can't refuse, so they're exempt. Cleared by handleSceneEnd.
            if (!$isSlave && !empty($intimacyStatus["refused_until_scene_end"])) {
                $_consented = false;
            }
        }
        if (!$sceneExitLocked && !$isNpcScene) {
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStopScene";  // can't END the scene if intoxication-locked
        }
        if (!$isSlave && !$npcSceneGateDisabled && !$isNpcScene) {
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRefuseSex";  // can refuse/protest even when intoxication-locked (slaves cannot refuse)
        }
        if ($_consented && !$isNpcScene && ($intimacyStatus["scene_phase"] ?? '') !== "rejected") {
            // Consenting NPC: can drive the scene. Mid-scene position steering (SexCommand) and pace control are each
            // independently toggleable in the UI; both are model-driven choices (steering is NOT tied to kink selection).
            if (_getNsfwSetting('NSFW_ALLOW_MIDSCENE_STEERING', true)) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdSexCommand";
                $GLOBALS["HOOKS"]["JSON_TEMPLATE"][]=function() {
                    $GLOBALS["responseTemplate"]["target"]="blowjob,boobjob,analsex,vaginalsex,handjob,frenchkissing,cunnilingus";
                    $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"]["target"]["description"]="blowjob,boobjob,analsex,vaginalsex,handjob,frenchkissing,cunnilingus";
                };
            }
            if (_getNsfwSetting('NSFW_ALLOW_PACE_CONTROL', true)) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdQuickenPace";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdSlowPace";
            }
        } else if (!$isSlave && !$isNpcScene && !$openModeAdultPlayerRoute && (int)($intimacyStatus["intensity_tier"] ?? 0) >= 3) {
            // Not consented yet AND the scene has reached EXPLICIT (tier 3): can opt IN (Accept), but CANNOT drive the
            // scene. Below tier 3 (standing/affection - e.g. an additional partner in a multi-partner/orgy scene who is
            // "just standing there") AcceptSex is NOT offered: it only unlocks at the tier-3 prompt (which opens the
            // sex speak styles). Prevents extra partners consenting to sex while nothing explicit is happening. (2026-07-01)
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdAcceptSex";
        }

        // Prostitutes can still collect payment mid-scene if needed
        if ($isProstitute) {
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdCollectPayment";
        }

        if (aiagentNsfwShouldExposeSlavePoison($npcName, $intimacyStatus, $affinity)) {
            aiagentNsfwAddEnabledFunction("ExtCmdPoisonMasterFood");
        }
    }

    if ($isNpcScene && (!isset($GLOBALS["ENABLED_FUNCTIONS"]) || !is_array($GLOBALS["ENABLED_FUNCTIONS"]))) {
        $GLOBALS["ENABLED_FUNCTIONS"] = [];
    }

    if ($isNpcScene) {
        $npcSceneSuppressedTools = [
            "ExtCmdAcceptSex",
            "ExtCmdRefuseSex",
            "ExtCmdStopScene",
            "ExtCmdSexCommand",
            "ExtCmdCollectPayment",
            "ExtCmdKiss",
            "ExtCmdHoldHands",
            "ExtCmdHug",
            "ExtCmdRemoveClothes",
            "ExtCmdPutOnClothes",
            "ExtCmdStartMassage",
            "ExtCmdStartSelfMasturbation",
            "ExtCmdStartHandJobSex",
            "ExtCmdStartBlowJob",
            "ExtCmdStartSex",
            "ExtCmdStartThreesome",
            "ExtCmdStartTitfuck",
            "ExtCmdStartAnalSex",
            "ExtCmdDrinkBloodSex"
        ];
        // JOIN/THREESOME OFFER (user directive 2026-06-29): when NSFW_ALLOW_NPC_JOIN_SCENES is ON (default), an NPC who
        // is already in an NPC-to-NPC scene MAY reach for StartSex/StartThreesome to pull another PRESENT actor -
        // including the player - into her scene. The game-side engine
        // (AIAgentNSFWSceneEngine via CommandManager) executes the actual OStim/SexLab join. The target enum built
        // later this request constrains the target to actors who are actually nearby, so she cannot pull in someone
        // absent. Without this the model never SEES the join action and "no action returns". When OFF, both stay
        // suppressed and in-scene NPCs react in dialogue only.
        $allowNpcJoin = _getNsfwSetting("NSFW_ALLOW_NPC_JOIN_SCENES", true);
        if ($allowNpcJoin) {
            $npcSceneSuppressedTools = array_values(array_diff($npcSceneSuppressedTools, ["ExtCmdStartSex", "ExtCmdStartThreesome"]));
        }
        $GLOBALS["ENABLED_FUNCTIONS"] = array_values(array_filter(
            $GLOBALS["ENABLED_FUNCTIONS"],
            function($functionName) use ($npcSceneSuppressedTools) {
                return !in_array($functionName, $npcSceneSuppressedTools, true);
            }
        ));
        if ($allowNpcJoin) {
            // Explicitly OFFER the join actions even if the level/consent branches above never added them for an
            // in-scene NPC. de-dup so they appear once.
            $GLOBALS["ENABLED_FUNCTIONS"][] = "ExtCmdStartSex";
            $GLOBALS["ENABLED_FUNCTIONS"][] = "ExtCmdStartThreesome";
            $GLOBALS["ENABLED_FUNCTIONS"] = array_values(array_unique($GLOBALS["ENABLED_FUNCTIONS"]));
            error_log("[AIAGENTNSFW] NPC-to-NPC scene route: join ENABLED (NSFW_ALLOW_NPC_JOIN_SCENES on) - StartSex/StartThreesome offered so an in-scene NPC can pull a present actor (incl. player) in");
        }
        // EndNpcScene (ExtCmdStopNpcScene) REMOVED (user directive 2026-06-28): NPC-scene thread control is NOT
        // reliable - calling it kept tearing down the PLAYER's scene instead of the NPC's own. An NPC in an
        // NPC-to-NPC scene can no longer end the scene mechanically; it reacts in dialogue only. Do NOT re-add it.
        error_log("[AIAGENTNSFW] NPC-to-NPC scene route: suppressed player/mechanical scene tools; EndNpcScene NOT offered (removed - unreliable, ended player scene)");
    }
	} else {
	    $fallbackNpcName = $GLOBALS["HERIKA_NAME"] ?? "";
	    $fallbackIntimacy = !empty($fallbackNpcName) ? getIntimacyForActor($fallbackNpcName) : [];
	    $fallbackIsProstitute = !empty($fallbackNpcName) && isProstitute($fallbackNpcName);
	    // FALLBACK-ROUTE SCOPE FIX (2026-07-01, from live log warnings): $skoomaBargain and $sceneCallMinAffinity
	    // are locals of the main gated branch and are UNDEFINED on this route - the null comparison made
	    // $fallbackConsented ALWAYS true, so the STRANGER GATE below never stripped sex initiators here.
	    // Compute the same values locally.
	    $fallbackIsSlaveActor = function_exists('isNpcSlave') && isNpcSlave($fallbackNpcName);
	    $fallbackSkoomaBargain = !$fallbackIsSlaveActor && function_exists('getDrugStageForActor') && (int)getDrugStageForActor($fallbackNpcName, 'skooma') >= 3;
	    $fallbackSceneCallMin = function_exists('aiagentNsfwSceneCallFloorFor')
	        ? (int)aiagentNsfwSceneCallFloorFor($fallbackNpcName)   // promiscuous mark drops her floor to Acquaintance(6)
	        : (int)_getNsfwSetting('NSFW_SCENE_CALL_MIN_AFFINITY', 56);
	    $fallbackIsUnpaidProstitute = $fallbackIsProstitute && empty($fallbackIntimacy["payment_confirmed"]) && !$fallbackSkoomaBargain; // L3 skooma overrides payment
	    // Orientation affection gate (parity with the main route); slaves/prostitutes bypass, fails open.
	    $fallbackAffectionOrientationOk = $fallbackIsSlaveActor || $fallbackIsProstitute
	        || !function_exists('aiagentNsfwOrientationAllowsPlayer')
	        || aiagentNsfwOrientationAllowsPlayer($fallbackNpcName);

	    if ($fallbackIsUnpaidProstitute) {
	        // ADDITIVE: keep all core CHIM functions; only suppress the redundant core gold-grab.
	        aiagentNsfwSuppressCoreTakeGoldFromPlayerAction();
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdKiss";
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdHug";
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdHoldHands";
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdPutOnClothes";
	        if (!$sceneExitLocked) { $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStopScene"; }
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdAcceptSex";
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRefuseSex";
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdCollectPayment";
	        error_log("[AIAGENTNSFW] Fallback function route - unpaid prostitute locked to negotiation/payment tools for " . $fallbackNpcName);
	    } else {
	        $fallbackAffinity = function_exists('getNpcAffinity') ? (int)getNpcAffinity($fallbackNpcName) : 0;
	        $fallbackAffectionFloor = function_exists('aiagentNsfwAffectionFloorFor') ? (int)aiagentNsfwAffectionFloorFor($fallbackNpcName) : 56;
	        if (($fallbackAffinity >= $fallbackAffectionFloor && $fallbackAffectionOrientationOk) || $fallbackIsSlaveActor) { $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdKiss"; } // affection needs the per-NPC floor + orientation match
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRemoveClothes";
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartMassage";
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartSelfMasturbation";
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartBlowJob";
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartSex";
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartAnalSex";
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartThreesome";
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartTitfuck";
	        if (($fallbackAffinity >= $fallbackAffectionFloor && $fallbackAffectionOrientationOk) || $fallbackIsSlaveActor) {
	            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdHug";
	            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdHoldHands";
	        }
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdPutOnClothes";
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdConsumeSoul";
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartHandJobSex";
	        if (!$sceneExitLocked) { $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStopScene"; }
	        if (_getNsfwSetting('NSFW_ALLOW_MIDSCENE_STEERING', true)) { $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdSexCommand"; }
	        if (_getNsfwSetting('NSFW_ALLOW_PACE_CONTROL', true)) { $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdQuickenPace"; $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdSlowPace"; }
	        // Affinity-gated functions
	        // TIER-3 GATE (fix 2026-07-01): AcceptSex was added UNGATED on this funcret/instruction route, letting the
	        // model "accept" with no scene at all. Same gate as the main route: only at the tier-3 decision point.
	        if ((($fallbackIntimacy["scene_phase"] ?? '') === 'tier_prompt') && (int)($fallbackIntimacy["intensity_tier"] ?? 0) >= 3) {
	            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdAcceptSex";
	        }
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRefuseSex";  // can refuse, just can't exit (Stop tools gated above)
	        // Prostitute payment function
	        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdCollectPayment";
	
	        // STRANGER GATE: this fallback runs for NPCs with no intimacy record (the gated level-0 block is skipped), i.e.
	        // strangers. Without this a random stranger (e.g. a bard you have never met) got StartSex and tried to
	        // initiate a scene. Strip the sex-act initiators + scene driver unless romanticized (Fond+, aff>=56),
	        // already consented, slave, or skooma-bargain. Affection + Accept/Refuse stay so they can still react.
	        $fallbackAffinity = function_exists('getNpcAffinity') ? (int)getNpcAffinity($fallbackNpcName) : 0;
		        $fallbackConsented = !empty($fallbackIntimacy["accepted_sex"]) || $fallbackIsSlaveActor || $fallbackSkoomaBargain || ($fallbackAffinity >= $fallbackSceneCallMin)
		            || (function_exists('aiagentNsfwOpenMode') && aiagentNsfwOpenMode());   // OPEN MODE: strangers keep the toolset (sticky refusal below still dominates; prostitutes still charge)
		        // REFUSAL DOMINATES (fix 2026-07-01): mirror the main route's sticky-refusal block (~2248). Without this a
		        // refusing Fond+ NPC kept Start* tools on funcret turns and their FUNCSERV auto-consent ERASED the refusal.
		        if (!$fallbackIsSlaveActor && (!empty($fallbackIntimacy["refused_until_scene_end"]) || (($fallbackIntimacy["scene_phase"] ?? '') === 'rejected'))) { $fallbackConsented = false; }
	        if (!$fallbackConsented) {
	            $stripStranger = ["ExtCmdRemoveClothes","ExtCmdStartMassage","ExtCmdStartSelfMasturbation","ExtCmdStartBlowJob","ExtCmdStartSex","ExtCmdStartAnalSex","ExtCmdStartThreesome","ExtCmdStartTitfuck","ExtCmdStartHandJobSex","ExtCmdSexCommand","ExtCmdConsumeSoul","ExtCmdDrinkBloodSex"];
	            $GLOBALS["ENABLED_FUNCTIONS"] = array_values(array_filter($GLOBALS["ENABLED_FUNCTIONS"], function($f) use ($stripStranger) { return !in_array($f, $stripStranger, true); }));
	            error_log("[AIAGENTNSFW] Fallback STRANGER gate: stripped sex initiators for ".$fallbackNpcName." (aff={$fallbackAffinity})");
	        }
	        error_log("[AIAGENTNSFW] All functions available");
	    }
	    // NPC-SCENE GUARD (fix 2026-07-01): the main-route suppression (~2515) never runs on this fallback, so an
	    // is_npc_scene actor taking a funcret/instruction turn kept player consent/scene-control tools. Mirror it
	    // (incl. ConsumeSoul/pace tools, which this fallback adds but the main NPC-scene route never offers).
	    if (!empty($fallbackIntimacy["is_npc_scene"])) {
	        $fallbackNpcSceneSuppressed = ["ExtCmdAcceptSex","ExtCmdRefuseSex","ExtCmdStopScene","ExtCmdSexCommand","ExtCmdCollectPayment","ExtCmdKiss","ExtCmdHoldHands","ExtCmdHug","ExtCmdRemoveClothes","ExtCmdPutOnClothes","ExtCmdStartMassage","ExtCmdStartSelfMasturbation","ExtCmdStartHandJobSex","ExtCmdStartBlowJob","ExtCmdStartSex","ExtCmdStartThreesome","ExtCmdStartTitfuck","ExtCmdStartAnalSex","ExtCmdDrinkBloodSex","ExtCmdConsumeSoul","ExtCmdQuickenPace","ExtCmdSlowPace"];
	        if (_getNsfwSetting("NSFW_ALLOW_NPC_JOIN_SCENES", true)) {
	            $fallbackNpcSceneSuppressed = array_values(array_diff($fallbackNpcSceneSuppressed, ["ExtCmdStartSex","ExtCmdStartThreesome"]));
	        }
	        $GLOBALS["ENABLED_FUNCTIONS"] = array_values(array_filter($GLOBALS["ENABLED_FUNCTIONS"], function($f) use ($fallbackNpcSceneSuppressed) { return !in_array($f, $fallbackNpcSceneSuppressed, true); }));
	        error_log("[AIAGENTNSFW] Fallback NPC-scene guard: player consent/scene tools suppressed for {$fallbackNpcName}");
	    }
	}



// EndNpcScene (ExtCmdStopNpcScene) is PERMANENTLY REMOVED (user directive 2026-06-28/29). It is enabled by default
// via the action catalog, so removing the explicit add was NOT enough - it kept getting offered ("NOT Skipping
// End_Npc_Scene") and the model kept trying to end NPC-to-NPC scenes with it, which unreliably tore down the
// PLAYER's scene. Strip it from EVERY turn here, after all building, no matter how it got enabled. Do NOT re-add it.
if (isset($GLOBALS["ENABLED_FUNCTIONS"]) && is_array($GLOBALS["ENABLED_FUNCTIONS"])) {
    $GLOBALS["ENABLED_FUNCTIONS"] = array_values(array_filter(
        $GLOBALS["ENABLED_FUNCTIONS"],
        function($f) { return $f !== "ExtCmdStopNpcScene"; }
    ));
}

// AFFECTION SPAM THROTTLE (user directive 2026-06-29): the legacy COOLDOWNMAP is never enforced, so without this the
// model spams HoldHands/Hug/Kiss (each starts an OStim alignment scene). When this NPC used an affection act in the
// last ~60s and NSFW_AFFECTION_COOLDOWN_ENABLED is on (default), withhold the affection acts so they can't fire back
// to back. Sex acts, Accept/Refuse, etc. are unaffected.
if (isset($GLOBALS["ENABLED_FUNCTIONS"]) && is_array($GLOBALS["ENABLED_FUNCTIONS"]) && function_exists('aiagentNsfwAffectionOnCooldown') && aiagentNsfwAffectionOnCooldown($GLOBALS["HERIKA_NAME"] ?? "")) {
    $affThrottle = ["ExtCmdHoldHands","ExtCmdHug","ExtCmdKiss"];
    $GLOBALS["ENABLED_FUNCTIONS"] = array_values(array_filter($GLOBALS["ENABLED_FUNCTIONS"], function($f) use ($affThrottle) { return !in_array($f, $affThrottle, true); }));
    error_log("[AIAGENTNSFW] Affection throttle active - HoldHands/Hug/Kiss withheld for " . ($GLOBALS["HERIKA_NAME"] ?? "?") . " (anti-spam)");
}

// RELATIONSHIP-TYPE SEX GATE (user directive 2026-06-29): a regular NPC is sexually available ONLY if her relationship
// TYPE with the PLAYER is one of the UI-eligible types (aiagent_nsfw_reltypes.eligible_types). Affinity does NOT
// override this - a Bonded "student" still kindly refuses. Slaves (forced), prostitutes (paid), and skooma-bargain
// bypass; NPC-to-NPC scenes are a separate route and are exempt. Ineligible -> strip every sex initiator + the scene
// driver + AcceptSex + pace + RemoveClothes so she CANNOT engage, and offer RefuseSex so she declines. Affection stays.
if (isset($GLOBALS["ENABLED_FUNCTIONS"]) && is_array($GLOBALS["ENABLED_FUNCTIONS"]) && function_exists('aiagentNsfwRelTypeSexEligible')) {
    $relGateNpc = $GLOBALS["HERIKA_NAME"] ?? "";
    $relGateIsNpcScene = isset($intimacyStatus) && !empty($intimacyStatus["is_npc_scene"]);
    $relGateSlave = $relGateNpc !== "" && function_exists('isNpcSlave') && isNpcSlave($relGateNpc);
    $relGateProstitute = $relGateNpc !== "" && function_exists('isProstitute') && isProstitute($relGateNpc);
    $relGateSkooma = !$relGateSlave && $relGateNpc !== "" && function_exists('getDrugStageForActor') && (int)getDrugStageForActor($relGateNpc, 'skooma') >= 3;
    if ($relGateNpc !== "" && $relGateNpc !== "(actor)" && strcasecmp($relGateNpc, "The Narrator") !== 0 && !$relGateIsNpcScene && !$relGateSlave && !$relGateProstitute && !$relGateSkooma && !aiagentNsfwRelTypeSexEligible($relGateNpc)) {
        $relGateStrip = ["ExtCmdStartSex","ExtCmdStartBlowJob","ExtCmdStartAnalSex","ExtCmdStartMassage","ExtCmdStartThreesome","ExtCmdStartHandJobSex","ExtCmdStartTitfuck","ExtCmdStartSelfMasturbation","ExtCmdSexCommand","ExtCmdAcceptSex","ExtCmdQuickenPace","ExtCmdSlowPace","ExtCmdRemoveClothes","ExtCmdDrinkBloodSex"];
        $GLOBALS["ENABLED_FUNCTIONS"] = array_values(array_filter($GLOBALS["ENABLED_FUNCTIONS"], function($f) use ($relGateStrip) { return !in_array($f, $relGateStrip, true); }));
        if (!in_array("ExtCmdRefuseSex", $GLOBALS["ENABLED_FUNCTIONS"], true)) { $GLOBALS["ENABLED_FUNCTIONS"][] = "ExtCmdRefuseSex"; }
        error_log("[AIAGENTNSFW] REL-TYPE SEX GATE: {$relGateNpc} relationship type NOT sex-eligible - sex tools + AcceptSex stripped, RefuseSex offered (kind decline)");
    }
}

// PRESENT-ACTOR TARGET ENUM (user directive 2026-06-29): constrain every sex-action 'target' to actors who are actually
// present (scene partners + player + nearby), so the model cannot emit an empty/garbage target that resolves to "no
// action returned". Built at request time because the actor list is per-turn. The enum becomes the closed list of
// valid targets. Group/threesome actions especially need nearby actors in the enum so she can pull someone in.
if (isset($GLOBALS["FUNCTIONS"]) && is_array($GLOBALS["FUNCTIONS"]) && function_exists('getFunctionCodeName')) {
    $nsfwValidTargets = [];
    $nsfwSceneActors = (isset($intimacyStatus) && is_array($intimacyStatus["scene_actors"] ?? null)) ? $intimacyStatus["scene_actors"] : [];
    foreach ($nsfwSceneActors as $sa) { $sa = trim((string)$sa); if ($sa !== '') { $nsfwValidTargets[] = $sa; } }
    if (!empty($GLOBALS["PLAYER_NAME"])) { $nsfwValidTargets[] = $GLOBALS["PLAYER_NAME"]; }
    if (function_exists('getNormalizedNearbyDialogueListenerNames')) {
        foreach ((array)getNormalizedNearbyDialogueListenerNames(true) as $nb) { $nb = trim((string)$nb); if ($nb !== '') { $nsfwValidTargets[] = $nb; } }
    }
    $nsfwValidTargets = array_values(array_unique(array_filter($nsfwValidTargets, function($t){
        return $t !== '' && (!function_exists('nsfwIsNarratorName') || !nsfwIsNarratorName($t));
    })));
    if (!empty($nsfwValidTargets)) {
        $nsfwTargetActions = ["ExtCmdStartSex","ExtCmdStartBlowJob","ExtCmdStartAnalSex","ExtCmdStartThreesome","ExtCmdStartTitfuck","ExtCmdStartHandJobSex","ExtCmdStartMassage","ExtCmdAcceptSex","ExtCmdRefuseSex","ExtCmdKiss","ExtCmdHug","ExtCmdHoldHands","ExtCmdDrinkBloodSex"];
        foreach ($GLOBALS["FUNCTIONS"] as $i => $fn) {
            if (!is_array($fn) || empty($fn["name"])) { continue; }
            $code = getFunctionCodeName($fn["name"]);
            if (in_array($code, $nsfwTargetActions, true) && isset($GLOBALS["FUNCTIONS"][$i]["parameters"]["properties"]["target"])) {
                $GLOBALS["FUNCTIONS"][$i]["parameters"]["properties"]["target"]["enum"] = $nsfwValidTargets;
            }
        }
        error_log("[AIAGENTNSFW] Sex-action target enum constrained to present actors: " . implode(", ", $nsfwValidTargets));
    }
}

// AcceptSex is REQUIRED again. Consent is now a MANDATORY SOFT GATE (user directive 2026-06-28, reversing the
// earlier same-day default-open experiment): a regular NPC's scene unlocks ONLY when the model explicitly calls
// AcceptSex, so the tool MUST stay in the action list or every consensual scene stalls at the consent question.
// The blanket removal that used to live here is gone. The enablement branches above already scope it correctly:
// regular NPCs get it (not rejected / not NPC-scene), prostitutes get it for the paid transaction, and slaves
// never get it (they auto-accept). Do NOT strip ExtCmdAcceptSex here.

// Add this function to enabled array
$GLOBALS["IS_NPC"]=isset($GLOBALS["IS_NPC"])?$GLOBALS["IS_NPC"]:false;
if  (!$GLOBALS["IS_NPC"]) {
   
}

// ============================================================================
// FMR NG FAMILY: training send-off action (user request 2026-07-15).
// The AI can agree to leave home for training when it comes up in conversation.
// Registration is unconditional (so it shows on the CHIM UI actions page like
// every other action); OFFERING is gated below to the one case that makes
// sense: the speaking agent is one of the player's grown children still
// awaiting assignment (FMRNG_Lineage.json trainingStatus 0 - the same file the
// Fertility-tab family prompts read). Game side: the CHIM DLL's ExtCmd bridge
// (Commands.cpp: script name = text between "ExtCmd" and the first "_") calls
// FMRNGBridge.DispatchExternalCommand, which opens FMR NG's faction-gated
// training-location picker on the player - the exact flow the in-game
// send-off dialogue topic uses. The DLL re-validates the lineage record, so a
// stale lineage file cannot missend anyone.
// ============================================================================

$GLOBALS["F_NAMES"]["ExtCmdFMRNGBridge_GoTraining"] = "AgreeToGoTraining";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdFMRNGBridge_GoTraining"] = "{$GLOBALS["HERIKA_NAME"]} agrees it is time to leave home and go away to be trained in a trade (only when going off to training came up in conversation)";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdFMRNGBridge_GoTraining"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdFMRNGBridge_GoTraining"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "trade" => [
                "type" => "string",
                "description" => "Optional: the trade or place {$GLOBALS["HERIKA_NAME"]} would like to train in, if one was discussed",
            ],
        ],
        "required" => [],
    ],
];

if (isset($GLOBALS["gameRequest"]) && $GLOBALS["gameRequest"][0] != "instruction" && $GLOBALS["gameRequest"][0] != "funcret"
        && !empty($GLOBALS["HERIKA_NAME"])) {
    // fertility_family normally loads in context_pre, which runs after this
    // file - pull it in ourselves (require_once, so no double load).
    require_once __DIR__ . "/nsfw_fertility_family.php";
    $_fmrngTrainLineage = function_exists('aiagentNsfwLineageData') ? aiagentNsfwLineageData() : null;
    if (is_array($_fmrngTrainLineage)) {
        foreach (($_fmrngTrainLineage['children'] ?? []) as $_fmrngTrainChild) {
            // Trainable = awaiting assignment (0) OR adopted and living at home
            // (3 - the 2026-07-16 un-adopt: the game unwinds the Hearthfire
            // family state before the send-off). In training (1) / trained (2)
            // never get the action.
            $_fmrngTrainStatus = (int)($_fmrngTrainChild['trainingStatus'] ?? -1);
            if (($_fmrngTrainStatus === 0 || $_fmrngTrainStatus === 3)
                    && strcasecmp(trim((string)($_fmrngTrainChild['name'] ?? '')), trim((string)$GLOBALS["HERIKA_NAME"])) === 0) {
                $GLOBALS["ENABLED_FUNCTIONS"][] = "ExtCmdFMRNGBridge_GoTraining";
                error_log("[AIAGENTNSFW] FMR NG: {$GLOBALS["HERIKA_NAME"]} is a trainable child (status {$_fmrngTrainStatus}) - AgreeToGoTraining offered");
                break;
            }
        }
    }
}

// Restore standard behaviour
if ($GLOBALS["HERIKA_NAME"]=="Character") {
    $GLOBALS["HERIKA_NAME"]="The Narrator";
}

?>
