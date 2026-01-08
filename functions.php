<?php

require_once(__DIR__."/common.php");

// Load NSFW prompts AFTER core prompts (TEMPLATE_DIALOG is now available)
require_once(__DIR__."/prompts.php");

// Helper function to check if sex_disposal arousal gating is enabled
function isSexDisposalEnabled() {
    static $cached = null;
    if ($cached !== null) return $cached;

    try {
        $settingsRow = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_settings'");
        if ($settingsRow && !empty($settingsRow['value'])) {
            $settings = json_decode($settingsRow['value'], true);
            if (is_array($settings) && isset($settings['ENABLE_SEX_DISPOSAL'])) {
                $cached = (bool)$settings['ENABLE_SEX_DISPOSAL'];
                return $cached;
            }
        }
    } catch (Exception $e) {
        error_log("[AIAGENTNSFW] Error checking ENABLE_SEX_DISPOSAL: " . $e->getMessage());
    }
    $cached = true;  // Default to enabled
    return $cached;
}

// Helper function to check if fertility tracking is enabled
function isFertilityTrackingEnabled() {
    static $cached = null;
    if ($cached !== null) return $cached;

    try {
        $settingsRow = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_settings'");
        if ($settingsRow && !empty($settingsRow['value'])) {
            $settings = json_decode($settingsRow['value'], true);
            if (is_array($settings) && isset($settings['TRACK_FERTILITY_INFO'])) {
                $cached = (bool)$settings['TRACK_FERTILITY_INFO'];
                return $cached;
            }
        }
    } catch (Exception $e) {
        error_log("[AIAGENTNSFW] Error checking TRACK_FERTILITY_INFO: " . $e->getMessage());
    }
    $cached = false;  // Default to disabled
    return $cached;
}

// Helper function to get NPC sex cooldown in game hours (0 = disabled)
function getNpcSexCooldownHours() {
    static $cached = null;
    if ($cached !== null) return $cached;

    try {
        $settingsRow = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_settings'");
        if ($settingsRow && !empty($settingsRow['value'])) {
            $settings = json_decode($settingsRow['value'], true);
            if (is_array($settings) && isset($settings['NPC_SEX_COOLDOWN_HOURS'])) {
                $cached = intval($settings['NPC_SEX_COOLDOWN_HOURS']);
                return $cached;
            }
        }
    } catch (Exception $e) {
        error_log("[AIAGENTNSFW] Error checking NPC_SEX_COOLDOWN_HOURS: " . $e->getMessage());
    }
    $cached = 9;  // Default to 9 hours
    return $cached;
}

// Convert game hours to COOLDOWNMAP units (seconds / 0.00864)
// 1 game hour = 200 real seconds (Skyrim default timescale of 20)
// For COOLDOWNMAP: value / 0.00864 converts to game time units
function gameHoursToCooldownUnits($gameHours) {
    if ($gameHours <= 0) return 0;  // Disabled
    // 1 game hour = 3600 game seconds = 180 real seconds at timescale 20
    // COOLDOWNMAP uses: real_seconds / 0.00864
    // So for 1 game hour: 180 / 0.00864 = 20833 units
    // Actually the formula seems to be: action_seconds / 0.00864 where 0.00864 = 1/115.74 game days per second
    // Let's use the pattern from existing cooldowns: 300/0.00864 = ~5 min = ~9 game hours
    // So to get X game hours: (X * 300 / 9) / 0.00864 = X * 33.33 / 0.00864
    $realSeconds = $gameHours * 33.33;  // Scale relative to the 9hr = 300sec pattern
    return $realSeconds / 0.00864;
}

// Chnage default actors name, so descriptions can use NPC name.
if ($GLOBALS["HERIKA_NAME"]=="The Narrator") {
    $GLOBALS["HERIKA_NAME"]="Character";
}

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
        $intimacyStatus["sex_disposal"]+=15;
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

$GLOBALS["FUNCRET"]["ExtCmdRemoveClothes"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdRemoveClothes FUNCRET");
    
    $intimacyStatus=getIntimacyForActor($GLOBALS["HERIKA_NAME"]);
    $intimacyStatus["sex_disposal"]+=10;
    $intimacyStatus["is_naked"]=1;
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
    // Probably we want to execute something, and put return value in $returnFunction[3] and $gameRequest[3];
    // We could overwrite also $request.
    error_log("Running ExtCmdStartSex FUNCRET");

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
         $intimacyStatus["sex_disposal"]+=15;
         $intimacyStatus["level"]=1;
         updateIntimacyForActor($actorName,$intimacyStatus);
 
     }

     $GLOBALS["AVOID_LLM_CALL"]=true;


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
        $intimacyStatus["sex_disposal"]+=15;
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
        $intimacyStatus["sex_disposal"]+=15;
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
        $intimacyStatus["sex_disposal"]+=15;
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
        $intimacyStatus["sex_disposal"]+=15;
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
        $intimacyStatus["sex_disposal"]+=15;
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
        $intimacyStatus["sex_disposal"]+=15;
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
    $intimacyStatus["sex_disposal"]+=5;
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
        $intimacyStatus["sex_disposal"]+=5;
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
    $intimacyStatus["sex_disposal"]-=10;
    $intimacyStatus["level"]=0;
    $intimacyStatus["is_naked"]=0;
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

    // Update intimacy status
    $intimacyStatus = getIntimacyForActor($npcName);
    $intimacyStatus["sex_disposal"] = ($intimacyStatus["sex_disposal"] ?? 0) + 20;
    $intimacyStatus["accepted_sex"] = true;
    $intimacyStatus["sex_partner"] = $targetName;
    $intimacyStatus["scene_type"] = "personal";

    // Kink unlocks are now based purely on affinity, checked during prompt injection
    // Normal kinks: Fond 56+
    // Secret kinks: Devoted 76+
    // Prostitutes don't get kinks revealed (they use prostitute prompts)
    $intimacyStatus["show_normal_kinks"] = ($affinity >= 56);
    $intimacyStatus["show_secret_kinks"] = ($affinity >= 76);

    updateIntimacyForActor($npcName, $intimacyStatus);

    error_log("[AcceptSex] {$npcName} accepted personal sex with {$targetName}. Affinity: {$affinity}, Normal kinks: " . ($intimacyStatus["show_normal_kinks"] ? 'yes' : 'no') . ", Secret kinks: " . ($intimacyStatus["show_secret_kinks"] ? 'yes' : 'no'));

    $GLOBALS["AVOID_LLM_CALL"] = false;
};


/******
ExtCmdAcceptTransaction - Prostitute accepts a client for services
LLM decides whether to accept based on tier prompts (tier_prost_*)
Pricing modifiers by tier are applied via buildProstituteNegotiationContext()
******/

$GLOBALS["F_NAMES"]["ExtCmdAcceptTransaction"]="AcceptTransaction";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdAcceptTransaction"]="{$GLOBALS["HERIKA_NAME"]} agrees to provide services to the client (business transaction)";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdAcceptTransaction"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdAcceptTransaction"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "target" => [
                "type" => "string",
                "description" => "The client being accepted",
            ]
        ],
        "required" => ["target"],
    ],
]
;

$GLOBALS["FUNCRET"]["ExtCmdAcceptTransaction"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdAcceptTransaction FUNCRET");

    $functionCallRet = explode("@", $gameRequest[3]);
    $targetName = $functionCallRet[1] ?? $GLOBALS["PLAYER_NAME"];
    $npcName = $GLOBALS["HERIKA_NAME"];

    // Verify prostitute status
    if (!isProstitute($npcName)) {
        error_log("[AcceptTransaction] {$npcName} is not a prostitute - use AcceptSex instead");
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    }

    $affinity = getNpcAffinity($npcName, $targetName);

    // No hardcoded refusal - LLM decides based on tier prompts
    // The tier_prost_* prompts guide behavior (hostile/hateful prompts say to refuse)
    error_log("[AcceptTransaction] {$npcName} accepting transaction with {$targetName} (affinity: {$affinity})");

    // Update intimacy status - this is a TRANSACTION not personal intimacy
    $intimacyStatus = getIntimacyForActor($npcName);
    $intimacyStatus["sex_disposal"] = ($intimacyStatus["sex_disposal"] ?? 0) + 10; // Less arousal boost for transactions
    $intimacyStatus["is_transaction"] = true;  // KEY FLAG: Uses prostitute prompt, not personal sex prompt
    $intimacyStatus["transaction_client"] = $targetName;
    $intimacyStatus["accepted_sex"] = false;   // NOT personal acceptance
    $intimacyStatus["negotiation_phase"] = true;  // NEW: In negotiation phase

    // Transactions: NO personal kinks are revealed - business only
    // Prostitutes use professional prompts, not personal sex personality
    $intimacyStatus["show_normal_kinks"] = false;
    $intimacyStatus["show_secret_kinks"] = false;

    // Post-scene handling: Transactions get "post_client" prompt, not "pillow_talk"
    $intimacyStatus["scene_type"] = "transaction";

    updateIntimacyForActor($npcName, $intimacyStatus);

    // ============================================
    // INJECT NEGOTIATION CONTEXT
    // ============================================
    // Build services menu with tier-based pricing and inject into personality
    // Prostitute will discuss services, quote prices, then use RequestPayment
    // ============================================
    $negotiationContext = buildProstituteNegotiationContext($npcName, $targetName, $affinity);
    if (!empty($negotiationContext)) {
        $GLOBALS["HERIKA_PERSONALITY"] .= $negotiationContext;
        error_log("[AcceptTransaction] Injected negotiation context for {$npcName}");
    }

    error_log("[AcceptTransaction] {$npcName} accepted TRANSACTION with {$targetName}. Affinity: {$affinity}. Now in NEGOTIATION phase.");

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

    // Reset intimacy flags
    $intimacyStatus = getIntimacyForActor($GLOBALS["HERIKA_NAME"]);
    $intimacyStatus["level"] = 0;
    $intimacyStatus["accepted_sex"] = false;
    $intimacyStatus["sex_partner"] = null;
    $intimacyStatus["show_normal_kinks"] = false;
    $intimacyStatus["show_secret_kinks"] = false;
    $intimacyStatus["is_transaction"] = false;
    $intimacyStatus["sex_disposal"] = max(0, ($intimacyStatus["sex_disposal"] ?? 0) - 15);
    // Clear NPC scene data if present
    $intimacyStatus["npc_scene_partner"] = null;
    $intimacyStatus["npc_scene_thread_id"] = null;
    updateIntimacyForActor($GLOBALS["HERIKA_NAME"], $intimacyStatus);

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

    // Reset intimacy level to indicate scene ended
    $intimacyStatus=getIntimacyForActor($GLOBALS["HERIKA_NAME"]);
    $intimacyStatus["level"]=0;  // No longer in a scene
    $intimacyStatus["sex_disposal"]=max(0, ($intimacyStatus["sex_disposal"] ?? 0) - 10);  // Reduce arousal
    // Clear NPC scene data if present
    $intimacyStatus["npc_scene_partner"] = null;
    $intimacyStatus["npc_scene_thread_id"] = null;
    updateIntimacyForActor($GLOBALS["HERIKA_NAME"],$intimacyStatus);

    $GLOBALS["AVOID_LLM_CALL"]=true;
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
ExtCmdRequestPayment - Prostitute requests payment from client
Stores the requested amount. When player agrees, TakeGoldFromPlayer is called automatically.
******/

$GLOBALS["F_NAMES"]["ExtCmdRequestPayment"]="RequestPayment";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdRequestPayment"]="{$GLOBALS["HERIKA_NAME"]} requests a specific gold amount for services. When player agrees, gold is automatically taken.";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdRequestPayment"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdRequestPayment"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "amount" => [
                "type" => "integer",
                "description" => "Gold amount to charge (e.g., 50, 100, 250)",
            ],
            "service" => [
                "type" => "string",
                "description" => "Brief description of services (e.g., 'oral', 'full service', '1 hour')",
            ]
        ],
        "required" => ["amount"]
    ],
]
;

$GLOBALS["FUNCRET"]["ExtCmdRequestPayment"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdRequestPayment FUNCRET");

    $npcName = $GLOBALS["HERIKA_NAME"];

    // Check if NPC is actually a prostitute
    if (!isProstitute($npcName)) {
        error_log("[RequestPayment] {$npcName} is not marked as prostitute - ignoring");
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    }

    // Parse requested amount from function call (format: RequestPayment@amount@service)
    $functionCallRet = explode("@", $gameRequest[3]);
    $requestedAmount = isset($functionCallRet[1]) ? intval($functionCallRet[1]) : 0;
    $serviceDesc = $functionCallRet[2] ?? "services";

    if ($requestedAmount <= 0) {
        error_log("[RequestPayment] Invalid amount: {$functionCallRet[1]} - defaulting to 50");
        $requestedAmount = 50;
    }

    // Store payment request in intimacy data
    $intimacyStatus = getIntimacyForActor($npcName);
    $intimacyStatus["payment_requested"] = true;
    $intimacyStatus["payment_amount"] = $requestedAmount;
    $intimacyStatus["payment_service"] = $serviceDesc;
    $intimacyStatus["payment_timestamp"] = time();
    $intimacyStatus["awaiting_agreement"] = true;  // Flag for prerequest.php to detect player agreement
    updateIntimacyForActor($npcName, $intimacyStatus);

    error_log("[RequestPayment] {$npcName} requested {$requestedAmount} gold for: {$serviceDesc}");

    // NPC speaks the request - player must agree for gold to be taken
    $GLOBALS["AVOID_LLM_CALL"] = false;
};


/******
ExtCmdWaivePayment - Prostitute waives payment for devoted client (Devoted 76+ required)
Behavioral marker - triggers prostitute sex prompt at high affinity without payment
******/

$GLOBALS["F_NAMES"]["ExtCmdWaivePayment"]="WaivePayment";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdWaivePayment"]="{$GLOBALS["HERIKA_NAME"]} waives payment due to deep affection for the client";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdWaivePayment"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdWaivePayment"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "reason" => [
                "type" => "string",
                "description" => "Reason for waiving (e.g., 'I care about you', 'This one's on me')",
            ]
        ],
        "required" => []
    ],
]
;

$GLOBALS["FUNCRET"]["ExtCmdWaivePayment"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdWaivePayment FUNCRET");

    $npcName = $GLOBALS["HERIKA_NAME"];

    // Verify prostitute status and affinity threshold
    if (!isProstitute($npcName)) {
        error_log("[WaivePayment] {$npcName} is not a prostitute - ignoring");
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    }

    $affinity = getNpcAffinity($npcName);
    if ($affinity < 76) {
        error_log("[WaivePayment] {$npcName} affinity {$affinity} is below Devoted (76+) - should not have been offered");
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    }

    // Mark payment as waived
    $intimacyStatus = getIntimacyForActor($npcName);
    $intimacyStatus["payment_waived"] = true;
    $intimacyStatus["payment_requested"] = false;
    $intimacyStatus["waive_reason"] = "devoted";
    updateIntimacyForActor($npcName, $intimacyStatus);

    error_log("[WaivePayment] {$npcName} waived payment (affinity: {$affinity})");

    $GLOBALS["AVOID_LLM_CALL"] = false;
};


/******
ExtCmdQuitProstitution - NPC can be talked into quitting the trade (Bonded 91+ required)
Clears the is_prostitute flag permanently
******/

$GLOBALS["F_NAMES"]["ExtCmdQuitProstitution"]="QuitProstitution";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdQuitProstitution"]="{$GLOBALS["HERIKA_NAME"]} decides to quit the prostitution trade";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdQuitProstitution"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdQuitProstitution"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "reason" => [
                "type" => "string",
                "description" => "Why they're quitting (e.g., 'found someone special', 'new life', 'love')",
            ]
        ],
        "required" => []
    ],
]
;

$GLOBALS["FUNCRET"]["ExtCmdQuitProstitution"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdQuitProstitution FUNCRET");

    $npcName = $GLOBALS["HERIKA_NAME"];

    // Verify prostitute status and affinity threshold
    if (!isProstitute($npcName)) {
        error_log("[QuitProstitution] {$npcName} is not a prostitute - ignoring");
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    }

    $affinity = getNpcAffinity($npcName);
    if ($affinity < 91) {
        error_log("[QuitProstitution] {$npcName} affinity {$affinity} is below Bonded (91+) - should not have been offered");
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    }

    // Parse reason
    $functionCallRet = explode("@", $gameRequest[3]);
    $reason = $functionCallRet[1] ?? "found true love";

    // Clear prostitute status
    setNpcProstituteStatus($npcName, false);

    // Clear payment-related flags
    $intimacyStatus = getIntimacyForActor($npcName);
    $intimacyStatus["payment_requested"] = false;
    $intimacyStatus["payment_waived"] = false;
    $intimacyStatus["quit_prostitution_reason"] = $reason;
    $intimacyStatus["quit_prostitution_date"] = date('Y-m-d H:i:s');
    updateIntimacyForActor($npcName, $intimacyStatus);

    error_log("[QuitProstitution] {$npcName} quit prostitution (affinity: {$affinity}, reason: {$reason})");

    $GLOBALS["AVOID_LLM_CALL"] = false;
};


/******
ExtCmdCheckOwnGold - Prostitute checks own gold to verify payment received
Returns their current gold count so they can verify they got paid
******/

$GLOBALS["F_NAMES"]["ExtCmdCheckOwnGold"]="CheckMyGold";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdCheckOwnGold"]="{$GLOBALS["HERIKA_NAME"]} checks how much gold they have";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdCheckOwnGold"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdCheckOwnGold"],
    "parameters" => [
        "type" => "object",
        "properties" => [],
        "required" => []
    ],
]
;

$GLOBALS["FUNCRET"]["ExtCmdCheckOwnGold"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdCheckOwnGold FUNCRET");

    $npcName = $GLOBALS["HERIKA_NAME"];

    // Get the NPC's gold count from their inventory (shown in <inventory> tag)
    // We'll parse the gameRequest for inventory info or check extended data
    $intimacyStatus = getIntimacyForActor($npcName);

    // Store that they checked - useful for tracking payment verification
    $intimacyStatus["last_gold_check"] = time();
    updateIntimacyForActor($npcName, $intimacyStatus);

    error_log("[CheckOwnGold] {$npcName} checked their gold");

    // The actual gold count comes from the game via <inventory> tag
    // This function is more about marking intent and letting the NPC reference their inventory
    $GLOBALS["AVOID_LLM_CALL"] = false;
};


/******
ExtCmdConfirmPayment - Prostitute confirms payment was received
Clears the payment_requested flag and marks the transaction as complete
******/

$GLOBALS["F_NAMES"]["ExtCmdConfirmPayment"]="ConfirmPayment";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdConfirmPayment"]="{$GLOBALS["HERIKA_NAME"]} confirms they received payment for services";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdConfirmPayment"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdConfirmPayment"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "amount" => [
                "type" => "string",
                "description" => "Amount received (e.g., '200 gold', 'agreed upon price')",
            ]
        ],
        "required" => []
    ],
]
;

$GLOBALS["FUNCRET"]["ExtCmdConfirmPayment"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdConfirmPayment FUNCRET");

    $npcName = $GLOBALS["HERIKA_NAME"];

    // Verify prostitute status
    if (!isProstitute($npcName)) {
        error_log("[ConfirmPayment] {$npcName} is not a prostitute - ignoring");
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    }

    // Parse amount
    $functionCallRet = explode("@", $gameRequest[3]);
    $amount = $functionCallRet[1] ?? "agreed price";

    // Clear payment flags and mark as paid
    $intimacyStatus = getIntimacyForActor($npcName);
    $intimacyStatus["payment_requested"] = false;
    $intimacyStatus["payment_confirmed"] = true;
    $intimacyStatus["payment_confirmed_amount"] = $amount;
    $intimacyStatus["payment_confirmed_time"] = time();

    // End negotiation phase - prostitute is now ready for service
    // The actual scene starts when OStim/SexLab triggers ext_nsfw_sexcene
    $intimacyStatus["negotiation_phase"] = false;
    $intimacyStatus["ready_for_service"] = true;

    updateIntimacyForActor($npcName, $intimacyStatus);

    error_log("[ConfirmPayment] {$npcName} confirmed payment: {$amount}. Ready for service.");

    $GLOBALS["AVOID_LLM_CALL"] = false;
};


/******
ExtCmdDemandMorePayment - Prostitute demands additional payment during service
For when the client requests extra services not initially agreed upon
******/

$GLOBALS["F_NAMES"]["ExtCmdDemandMorePayment"]="DemandMorePayment";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdDemandMorePayment"]="{$GLOBALS["HERIKA_NAME"]} demands additional payment for extra services";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdDemandMorePayment"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdDemandMorePayment"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "reason" => [
                "type" => "string",
                "description" => "Why more payment is needed (e.g., 'extra services', 'longer session', 'that costs extra')",
            ],
            "amount" => [
                "type" => "string",
                "description" => "Additional amount requested",
            ]
        ],
        "required" => []
    ],
]
;

$GLOBALS["FUNCRET"]["ExtCmdDemandMorePayment"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdDemandMorePayment FUNCRET");

    $npcName = $GLOBALS["HERIKA_NAME"];

    // Verify prostitute status
    if (!isProstitute($npcName)) {
        error_log("[DemandMorePayment] {$npcName} is not a prostitute - ignoring");
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    }

    // Parse parameters
    $functionCallRet = explode("@", $gameRequest[3]);
    $params = $functionCallRet[1] ?? "";

    // Store demand info
    $intimacyStatus = getIntimacyForActor($npcName);
    $intimacyStatus["additional_payment_demanded"] = true;
    $intimacyStatus["additional_payment_reason"] = $params;
    $intimacyStatus["additional_payment_time"] = time();
    updateIntimacyForActor($npcName, $intimacyStatus);

    error_log("[DemandMorePayment] {$npcName} demanded more: {$params}");

    $GLOBALS["AVOID_LLM_CALL"] = false;
};


/******
ExtCmdEndService - Prostitute ends the service session
Different from StopScene - this is the business end, triggers post-service talk
******/

$GLOBALS["F_NAMES"]["ExtCmdEndService"]="EndService";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdEndService"]="{$GLOBALS["HERIKA_NAME"]} declares the service session complete";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdEndService"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdEndService"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "satisfaction" => [
                "type" => "string",
                "description" => "How the session went (e.g., 'good client', 'never again', 'come back anytime')",
            ]
        ],
        "required" => []
    ],
]
;

$GLOBALS["FUNCRET"]["ExtCmdEndService"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdEndService FUNCRET");

    $npcName = $GLOBALS["HERIKA_NAME"];

    // Verify prostitute status
    if (!isProstitute($npcName)) {
        error_log("[EndService] {$npcName} is not a prostitute - ignoring");
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    }

    // Parse satisfaction
    $functionCallRet = explode("@", $gameRequest[3]);
    $satisfaction = $functionCallRet[1] ?? "completed";

    // Update intimacy status - service is complete
    $intimacyStatus = getIntimacyForActor($npcName);
    $intimacyStatus["service_completed"] = true;
    $intimacyStatus["service_satisfaction"] = $satisfaction;
    $intimacyStatus["service_end_time"] = time();

    // Calculate service duration if we have start time
    if (isset($intimacyStatus["scene_start_time"])) {
        $intimacyStatus["service_duration"] = time() - $intimacyStatus["scene_start_time"];
    }

    // Track total services for this NPC
    $intimacyStatus["total_services"] = ($intimacyStatus["total_services"] ?? 0) + 1;

    updateIntimacyForActor($npcName, $intimacyStatus);

    error_log("[EndService] {$npcName} ended service (satisfaction: {$satisfaction})");

    $GLOBALS["AVOID_LLM_CALL"] = false;
};


/******
ExtCmdInitiateSex - NPC initiates a sex scene with target (Fond 56+ required)
This is an affinity-gated version of MakeLove for NPC-initiated scenes
******/

$GLOBALS["F_NAMES"]["ExtCmdInitiateSex"]="InitiateSex";
$GLOBALS["F_TRANSLATIONS"]["ExtCmdInitiateSex"]="{$GLOBALS["HERIKA_NAME"]} initiates an intimate encounter with the target";
$GLOBALS["FUNCTIONS"][] =
[
    "name" => $GLOBALS["F_NAMES"]["ExtCmdInitiateSex"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdInitiateSex"],
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

$GLOBALS["FUNCRET"]["ExtCmdInitiateSex"]=function() {
    global $gameRequest,$returnFunction,$db,$request;
    error_log("Running ExtCmdInitiateSex FUNCRET");

    $functionCallRet = explode("@", $gameRequest[3]);
    $targetName = $functionCallRet[1] ?? $GLOBALS["PLAYER_NAME"];
    $npcName = $GLOBALS["HERIKA_NAME"];

    // Verify affinity threshold
    $affinity = getNpcAffinity($npcName, $targetName);
    if ($affinity < 56) {
        error_log("[InitiateSex] {$npcName} affinity {$affinity} with {$targetName} is below Fond (56+) - should not have been offered");
        $GLOBALS["AVOID_LLM_CALL"] = false;
        return;
    }

    // Update intimacy status (same as ExtCmdStartSex)
    $actors = [$npcName];
    $actorList = explode(",", $targetName);
    foreach ($actorList as $actor) {
        if (trim($actor) != $GLOBALS["PLAYER_NAME"]) {
            $actors[] = trim($actor);
        }
    }

    foreach ($actors as $actorName) {
        $intimacyStatus = getIntimacyForActor($actorName);
        $intimacyStatus["sex_disposal"] = ($intimacyStatus["sex_disposal"] ?? 0) + 15;
        $intimacyStatus["level"] = 1;
        updateIntimacyForActor($actorName, $intimacyStatus);
    }

    error_log("[InitiateSex] {$npcName} initiated sex with {$targetName} (affinity: {$affinity})");

    $GLOBALS["AVOID_LLM_CALL"] = true;
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
    $GLOBALS["COOLDOWNMAP"]["ExtCmdInitiateSex"]=$_npcSexCooldown;
}
// Note: When cooldown is 0/disabled, these commands have no cooldown entry (unlimited use)

// Scene control cooldowns (shorter, not affected by slider)
$GLOBALS["COOLDOWNMAP"]["ExtCmdSexCommand"]=15/0.00864;

// Affinity-gated functions (shorter cooldowns for decision-making)
$GLOBALS["COOLDOWNMAP"]["ExtCmdAcceptSex"]=60/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdAcceptTransaction"]=60/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdRefuseSex"]=30/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdRequestPayment"]=60/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdWaivePayment"]=300/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdQuitProstitution"]=600/0.00864;  // Long cooldown - major decision

// Prostitute service functions
$GLOBALS["COOLDOWNMAP"]["ExtCmdCheckOwnGold"]=30/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdConfirmPayment"]=60/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdDemandMorePayment"]=120/0.00864;
$GLOBALS["COOLDOWNMAP"]["ExtCmdEndService"]=60/0.00864;


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

        if ($sexDisposalEnabled && isset($intimacyStatus["sex_disposal"])) {
            // Arousal gating enabled - progressively unlock functions based on sex_disposal
            if ($intimacyStatus["sex_disposal"]>=1) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdKiss";
            }
            if ($intimacyStatus["sex_disposal"]>=5 && $intimacyStatus["is_naked"]<1) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRemoveClothes";
            }
            if ($intimacyStatus["sex_disposal"]>=10) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartMassage";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartSelfMasturbation";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartHandJobSex";

            }
            if ($intimacyStatus["sex_disposal"]>=20) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartBlowJob";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartSex";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartThreesome";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartTitfuck";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdStartAnalSex";

            }
            /*if (isset($intimacyStatus["is_naked"]) && $intimacyStatus["is_naked"]>1  ) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdPutOnClothes";
            }*/
        } else if (!$sexDisposalEnabled) {
            // Arousal gating disabled - all intimate functions available immediately
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
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdInitiateSex";
            }

            // === PROSTITUTE-SPECIFIC FUNCTIONS ===
            // All functions available - LLM decides based on tier prompts
            if ($isProstitute) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdAcceptTransaction";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRequestPayment";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdCheckOwnGold";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdConfirmPayment";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdWaivePayment";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdQuitProstitution";
            }

        } else {
            // Affinity gating disabled - all affinity functions available
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdAcceptSex";
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdInitiateSex";
            if ($isProstitute) {
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdAcceptTransaction";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRequestPayment";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdWaivePayment";
                $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdQuitProstitution";
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

        // === PROSTITUTE DURING-SCENE FUNCTIONS ===
        if ($isProstitute) {
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdDemandMorePayment";  // Extra services cost extra
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdEndService";         // Business end of session
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdCheckOwnGold";       // Verify payment received
            $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdConfirmPayment";     // Acknowledge payment
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
    // New affinity-gated functions
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdAcceptSex";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRefuseSex";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdInitiateSex";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdAcceptTransaction";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdRequestPayment";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdWaivePayment";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdQuitProstitution";
    // New prostitute service functions
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdCheckOwnGold";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdConfirmPayment";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdDemandMorePayment";
    $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdEndService";

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