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


$GLOBALS["FUNCRET"]["ExtCmdHug"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
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
            $intimacyStatus["sex_disposal"]+=15;
        }
        //$intimacyStatus["level"]=0;
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

$GLOBALS["FUNCRET"]["ExtCmdRemoveClothes"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdRemoveClothes FUNCRET");
    
    $intimacyStatus=getIntimacyForActor($GLOBALS["HERIKA_NAME"]);
    if (isSexDisposalEnabled()) {
        $intimacyStatus["sex_disposal"]+=10;
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


$GLOBALS["FUNCRET"]["ExtCmdStartSex"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdStartSex FUNCRET");

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

        // Prostitutes and slaves bypass affinity check
        // Regular NPCs need Fond (56+) affinity to initiate sex
        if (!$isProstitute && !$isSlave && $affinity < 56) {
            error_log("[StartSex] {$npcName} affinity {$affinity} with {$primaryTarget} is below Fond (56+) - blocking");
            $GLOBALS["AVOID_LLM_CALL"] = false;
            return;
        }
        error_log("[StartSex] {$npcName} affinity check passed: {$affinity} with {$primaryTarget}");
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
            $intimacyStatus["sex_disposal"] += 15;
        }
        $intimacyStatus["level"] = 1;
        updateIntimacyForActor($actorName, $intimacyStatus);
    }

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


$GLOBALS["FUNCRET"]["ExtCmdStartBlowJob"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdStartBlowJob FUNCRET");
  
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
            $intimacyStatus["sex_disposal"]+=15;
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


$GLOBALS["FUNCRET"]["ExtCmdStartAnalSex"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdStartAnalSex FUNCRET");
  
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
            $intimacyStatus["sex_disposal"]+=15;
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


$GLOBALS["FUNCRET"]["ExtCmdStartMassage"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdStartMassage FUNCRET");
   
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
            $intimacyStatus["sex_disposal"]+=15;
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


$GLOBALS["FUNCRET"]["ExtCmdStartThreesome"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdStartThreesome FUNCRET, status {$gameRequest[3]}");

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
            $intimacyStatus["sex_disposal"]+=15;
        }
        $intimacyStatus["level"]=1;
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


$GLOBALS["FUNCRET"]["ExtCmdStartHandJobSex"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdStartHandJobSex FUNCRET");

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
            $intimacyStatus["sex_disposal"]+=15;
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


$GLOBALS["FUNCRET"]["ExtCmdStartTitfuck"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdStartTitfuck FUNCRET");

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
            $intimacyStatus["sex_disposal"]+=15;
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
                "description" => "Target NPC, Actor, or being",
            ]
        ],
        "required" => ["target"],
    ]
]
;


$GLOBALS["FUNCRET"]["ExtCmdStartSelfMasturbation"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdStartSelfMasturbation FUNCRET");
   
    $intimacyStatus=getIntimacyForActor($GLOBALS["HERIKA_NAME"]);
    if (isSexDisposalEnabled()) {
        $intimacyStatus["sex_disposal"]+=5;
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


$GLOBALS["FUNCRET"]["ExtCmdKiss"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdKiss FUNCRET");
   
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
            $intimacyStatus["sex_disposal"]+=5;
        }
        $intimacyStatus["level"]=0;
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


$GLOBALS["FUNCRET"]["ExtCmdPutOnClothes"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdPutOnClothes FUNCRET");
   
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

$GLOBALS["FUNCRET"]["ExtCmdAcceptSex"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdAcceptSex FUNCRET");

    $functionCallRet = explode("@", $gameRequest[3]);
    $targetName = $functionCallRet[1] ?? $GLOBALS["PLAYER_NAME"];
    $npcName = $GLOBALS["HERIKA_NAME"];

    $affinity = getNpcAffinity($npcName, $targetName);
    $intimacyStatus = getIntimacyForActor($npcName);

    // ============================================
    // PROSTITUTE CHECK: Transaction vs Personal
    // ============================================
    // If NPC is a prostitute, this is a BUSINESS TRANSACTION
    // Uses prostitute prompts, negotiation phase, no personal kinks
    // ============================================
    if (isProstitute($npcName)) {
        error_log("[AcceptSex] {$npcName} is prostitute - handling as TRANSACTION");

        if (isSexDisposalEnabled()) {
            $intimacyStatus["sex_disposal"] = ($intimacyStatus["sex_disposal"] ?? 0) + 10; // Less arousal boost for transactions
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
            $intimacyStatus["sex_disposal"] = ($intimacyStatus["sex_disposal"] ?? 0) + 20;
        }
        $intimacyStatus["accepted_sex"] = true;
        $intimacyStatus["sex_partner"] = $targetName;
        $intimacyStatus["scene_type"] = "personal";

        // Kink unlocks based on affinity
        // Normal kinks: Fond 56+
        // Secret kinks: Devoted 76+
        $intimacyStatus["show_normal_kinks"] = ($affinity >= 56);
        $intimacyStatus["show_secret_kinks"] = ($affinity >= 76);

        updateIntimacyForActor($npcName, $intimacyStatus);

        error_log("[AcceptSex] {$npcName} accepted personal sex with {$targetName}. Affinity: {$affinity}, Normal kinks: " . ($intimacyStatus["show_normal_kinks"] ? 'yes' : 'no') . ", Secret kinks: " . ($intimacyStatus["show_secret_kinks"] ? 'yes' : 'no'));
    }

    $GLOBALS["AVOID_LLM_CALL"] = false;
};


/******
ExtCmdRefuseSex - NPC refuses sexual advances (always available)
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

$GLOBALS["FUNCRET"]["ExtCmdRefuseSex"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdRefuseSex FUNCRET");

    // Reset intimacy flags and SET REJECTED PHASE
    // This tells prerequest.php to skip ALL sex prompt injection
    $intimacyStatus = getIntimacyForActor($GLOBALS["HERIKA_NAME"]);
    $intimacyStatus["level"] = 0;
    $intimacyStatus["scene_phase"] = "rejected";  // CRITICAL: Block all sex prompts
    $intimacyStatus["accepted_sex"] = false;
    $intimacyStatus["sex_partner"] = null;
    $intimacyStatus["show_normal_kinks"] = false;
    $intimacyStatus["show_secret_kinks"] = false;
    $intimacyStatus["is_transaction"] = false;
    $intimacyStatus["sex_started"] = false;  // Ensure sex prompts don't fire
    if (isSexDisposalEnabled()) {
        $intimacyStatus["sex_disposal"] = max(0, ($intimacyStatus["sex_disposal"] ?? 0) - 15);
    }
    // Clear NPC scene data if present
    $intimacyStatus["npc_scene_partner"] = null;
    $intimacyStatus["npc_scene_thread_id"] = null;
    // Clear scene actors so this NPC is no longer "in scene"
    $intimacyStatus["scene_actors"] = null;
    updateIntimacyForActor($GLOBALS["HERIKA_NAME"], $intimacyStatus);

    error_log("[AIAGENTNSFW] RefuseSex called - set scene_phase=rejected for " . $GLOBALS["HERIKA_NAME"]);

    // This also triggers ExtCmdStopScene behavior if in a scene
    $GLOBALS["AVOID_LLM_CALL"] = false;
};


/******
ExtCmdStopScene - Ends an active OStim scene early
******/

$GLOBALS["F_NAMES"]["ExtCmdStopScene"]="EndSceneEarly";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdStopScene"]="{$GLOBALS["HERIKA_NAME"]} ends the current intimate scene early";
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


$GLOBALS["FUNCRET"]["ExtCmdStopScene"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdStopScene FUNCRET");

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

    updateIntimacyForActor($npcName, $intimacyStatus);

    $GLOBALS["AVOID_LLM_CALL"] = true;
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


$GLOBALS["FUNCRET"]["ExtCmdSexCommand"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdSexCommand FUNCRET");
   
    $functionCallRet = explode("@", $gameRequest[3]); // Function returns here
    // Update intimacy status for al lparticipants

    

    
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


$GLOBALS["FUNCRET"]["ExtCmdConsumeSoul"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdConsumeSoul FUNCRET");


    $GLOBALS["AIAGENTNSFW_FORCE_STOP"]=false;



};


/******
ExtCmdCollectPayment - Prostitute collects payment directly from player
Uses PaymentHandler to transfer gold via Papyrus (bypasses consent for negotiated transactions)
Sets payment_confirmed=true to unlock scene progression
******/

$GLOBALS["F_NAMES"]["ExtCmdCollectPayment"]="CollectPayment";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdCollectPayment"]="{$GLOBALS["HERIKA_NAME"]} collects the agreed payment from {$GLOBALS["PLAYER_NAME"]}";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdCollectPayment"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdCollectPayment"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "amount" => [
                "type" => "integer",
                "description" => "Gold amount to collect (e.g., 50, 100, 250)",
            ],
            "service" => [
                "type" => "string",
                "description" => "Brief description of services (e.g., 'massage', 'oral', 'full service')",
            ]
        ],
        "required" => ["amount"]
    ],
]
;

$GLOBALS["FUNCRET"]["ExtCmdCollectPayment"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdCollectPayment FUNCRET");

    $npcName = $GLOBALS["HERIKA_NAME"];

    // Check if NPC is actually a prostitute
    if (!isProstitute($npcName)) {
        error_log("[CollectPayment] {$npcName} is not marked as prostitute - ignoring");
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    }

    // Parse amount from function call (format: CollectPayment@amount@service)
    $functionCallRet = explode("@", $gameRequest[3]);
    $paymentAmount = isset($functionCallRet[1]) ? intval($functionCallRet[1]) : 0;
    $serviceDesc = $functionCallRet[2] ?? "services";

    if ($paymentAmount <= 0) {
        error_log("[CollectPayment] Invalid amount: {$functionCallRet[1]} - ignoring");
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    }

    // Use PaymentHandler to directly transfer gold (bypasses consent - negotiated transaction)
    require_once(__DIR__ . '/payment_handler.php');
    $paymentHandler = new PaymentHandler();
    $result = $paymentHandler->processPayment($npcName, $paymentAmount, 'gold', $serviceDesc);

    $intimacyStatus = getIntimacyForActor($npcName);

    if ($result['success']) {
        // Payment successful - set confirmed and unlock scene progression
        $intimacyStatus["payment_confirmed"] = true;
        $intimacyStatus["payment_confirmed_amount"] = $paymentAmount;
        $intimacyStatus["payment_confirmed_time"] = time();
        $intimacyStatus["payment_service"] = $serviceDesc;
        $intimacyStatus["negotiation_phase"] = false;
        $intimacyStatus["ready_for_service"] = true;

        error_log("[CollectPayment] {$npcName} collected {$paymentAmount} gold for: {$serviceDesc}. Ready for service.");

        // Inject context so NPC knows payment was received
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n<payment_received>You just collected {$paymentAmount} gold from {$GLOBALS['PLAYER_NAME']} for {$serviceDesc}. You can now proceed with the agreed services.</payment_received>";
    } else {
        // Payment failed (player doesn't have enough gold?)
        error_log("[CollectPayment] Payment failed for {$npcName}: " . ($result['error'] ?? 'unknown error'));

        // Inject context so NPC knows payment failed
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n<payment_failed>{$GLOBALS['PLAYER_NAME']} doesn't have enough gold. You need to insist on payment or refuse service.</payment_failed>";
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
$GLOBALS["COOLDOWNMAP"]["ExtCdmHug"]=300/0.00864;
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


// Helper function to check if affinity gating is enabled
function isAffinityGatingEnabled() {
    static $cached = null;
    if ($cached !== null) return $cached;

    try {
        $settingsRow = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_settings'");
        if ($settingsRow && !empty($settingsRow['value'])) {
            $settings = json_decode($settingsRow['value'], true);
            if (is_array($settings) && isset($settings['ENABLE_AFFINITY_GATING'])) {
                $cached = (bool)$settings['ENABLE_AFFINITY_GATING'];
                return $cached;
            }
        }
    } catch (Exception $e) {
        error_log("[AIAGENTNSFW] Error checking ENABLE_AFFINITY_GATING: " . $e->getMessage());
    }
    $cached = true;  // Default to enabled
    return $cached;
}


// DEBUG: Log what gameRequest looks like
$gameReqSet = isset($GLOBALS["gameRequest"]) ? "SET" : "NOT_SET";
$gameReq0 = $GLOBALS["gameRequest"][0] ?? "NULL";
error_log("[AIAGENTNSFW-FUNCS] gameRequest={$gameReqSet} gameRequest[0]={$gameReq0} HERIKA_NAME=" . ($GLOBALS["HERIKA_NAME"] ?? "NULL"));

if (isset($GLOBALS["gameRequest"]) && $GLOBALS["gameRequest"][0]!="instruction" && $GLOBALS["gameRequest"][0]!="funcret") {
    $intimacyStatus=getIntimacyForActor($GLOBALS["HERIKA_NAME"]);
    $sexDisposalEnabled = isSexDisposalEnabled();
    $affinityGatingEnabled = isAffinityGatingEnabled();
    $npcName = $GLOBALS["HERIKA_NAME"];
    $isProstitute = isProstitute($npcName);
    $isSlave = isNpcSlave($npcName);
    $affinity = getNpcAffinity($npcName);

// Only offer this action if sex disposal is >20 for this actor (when gating enabled)
    if (isset($intimacyStatus["level"])&&$intimacyStatus["level"]==0) {

        if ($sexDisposalEnabled) {
            // Arousal gating enabled - progressively unlock functions based on sex_disposal
            $currentArousal = $intimacyStatus["sex_disposal"] ?? 0;
            $isNaked = $intimacyStatus["is_naked"] ?? 0;

            if ($currentArousal >= 1) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdKiss";
            }
            if ($currentArousal >= 5) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRemoveClothes";
            }
            if ($currentArousal >= 10) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartMassage";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartSelfMasturbation";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartHandJobSex";

            }
            if ($currentArousal >= 20) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartBlowJob";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartSex";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartThreesome";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartTitfuck";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartAnalSex";

            }
            error_log("[AIAGENTNSFW] sex_disposal ENABLED - arousal={$currentArousal}, isNaked={$isNaked} for " . $npcName);
        } else {
            // Arousal gating disabled - all intimate functions available immediately
            error_log("[AIAGENTNSFW] sex_disposal DISABLED - adding all intimate functions for " . $npcName);
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdKiss";
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRemoveClothes";
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartMassage";
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartSelfMasturbation";
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartHandJobSex";
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartBlowJob";
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartSex";
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartThreesome";
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartTitfuck";
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartAnalSex";
            error_log("[AIAGENTNSFW] Arousal gating disabled - all functions available");
        }

        // Always available
        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdHug";
        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdPutOnClothes";
        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdConsumeSoul";

        // ===== AFFINITY-GATED FUNCTIONS =====
        // RefuseSex available for everyone EXCEPT slaves (slaves must comply)
        if (!$isSlave) {
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRefuseSex";
        }

        if ($affinityGatingEnabled) {
            // Affinity gating enabled - tier prompts guide LLM behavior
            // All functions available, LLM decides based on tier prompts

            // === REGULAR NPC FUNCTIONS ===
            if (!$isProstitute) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdAcceptSex";
            }

            // === PROSTITUTE-SPECIFIC FUNCTIONS ===
            // AcceptSex auto-detects prostitute and handles as transaction
            // CollectPayment directly takes gold via PaymentHandler (bypasses consent)
            if ($isProstitute) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdAcceptSex";  // Prostitutes use AcceptSex for transactions
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdCollectPayment";  // Collect payment directly
            }

        } else {
            // Affinity gating disabled - all affinity functions available
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdAcceptSex";
            if ($isProstitute) {
                // CollectPayment directly takes gold via PaymentHandler
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdCollectPayment";
            }
            error_log("[AIAGENTNSFW] Affinity gating disabled - all affinity functions available");
        }

    } else if (isset($intimacyStatus["level"])&&$intimacyStatus["level"]>=1) {
        unset($GLOBALS["ENABLED_FUNCTIONS"]);
        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdSexCommand";
        $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStopScene";  // Allow ending the scene early
        // RefuseSex mid-scene - slaves cannot refuse
        if (!$isSlave) {
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRefuseSex";
        }

        // Prostitutes can still collect payment mid-scene if needed
        if ($isProstitute) {
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdCollectPayment";
        }

        $GLOBALS["HOOKS"]["JSON_TEMPLATE"][]=function() {
            $GLOBALS["responseTemplate"]["target"]="blowjob,boobjob,analsex,vaginalsex,handjob,frenchkissing,cunnilingus";
            $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"]["target"]["description"]="blowjob,boobjob,analsex,vaginalsex,handjob,frenchkissing,cunnilingus";
        };


    }
} else {

    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdKiss";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRemoveClothes";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartMassage";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartSelfMasturbation";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartBlowJob";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartSex";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartAnalSex";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartThreesome";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartTitfuck";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdHug";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdPutOnClothes";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdConsumeSoul";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartHandJobSex";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStopScene";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdSexCommand";
    // Affinity-gated functions
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdAcceptSex";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRefuseSex";
    // Prostitute payment function
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdCollectPayment";

    error_log("[AIAGENTNSFW] All functions available");
}



// Add this function to enabled array
$GLOBALS["IS_NPC"]=isset($GLOBALS["IS_NPC"])?$GLOBALS["IS_NPC"]:false;
if  (!$GLOBALS["IS_NPC"]) {
   
}

// Restore standard behaviour
if ($GLOBALS["HERIKA_NAME"]=="Character") {
    $GLOBALS["HERIKA_NAME"]="The Narrator";
}

?>