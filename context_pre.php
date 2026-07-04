<?php

// Runs BEFORE the system prompt is built (main.php:2342). The drunk-state instruction is injected here,
// into the <character> block, so the model treats "you are drunk" as part of WHO SHE IS this turn and
// stays drunk on EVERY line until it wears off. (context.php runs after the system prompt is frozen, so
// appending there only reaches contextDataFull[0] = buried history, which a small model underweights -
// that's why she only acted drunk around the toast.)

require_once __DIR__ . "/common.php";

$actorName = $GLOBALS["HERIKA_NAME"] ?? "";

// ============================================================
// REL-TYPE SEX-TOOL STRIP (deterministic gate - the real enforcement)
// ------------------------------------------------------------
// If the NPC's relationship TYPE with the player is NOT one of the UI-selected sex-eligible types, remove EVERY
// sex-initiation tool so the model literally cannot choose one for her (a professional/platonic/etc. NPC can flirt,
// hug, kiss - but cannot start/accept sex). The old strip lived in functions.php, which runs BEFORE the LLM tool
// payload is built - by the time the model saw the tools they were back. This runs in context_pre (main.php ~2352),
// AFTER json_response.php baked FUNC_LIST / responseTemplate["action"] / structuredOutputTemplate.action.enum and
// with the REAL speaking NPC (HERIKA_NAME), and BEFORE the connector reads those templates live at the LLM call - so
// the strip actually reaches the model. Slaves (forced), prostitutes (payment gate), skooma-L3 bargain and NPC<->NPC
// scenes bypass. RefuseSex is offered so she can decline in character. Affection (Kiss/Hug/HoldHands) is untouched.
if ($actorName !== "" && isset($GLOBALS["ENABLED_FUNCTIONS"]) && is_array($GLOBALS["ENABLED_FUNCTIONS"])
    && function_exists('aiagentNsfwRelTypeSexEligible') && strcasecmp($actorName, "The Narrator") !== 0
    && strcasecmp($actorName, "(actor)") !== 0) {
    $rtStripIntimacy   = function_exists('getIntimacyForActor') ? getIntimacyForActor($actorName) : [];
    $rtStripIsNpcScene = is_array($rtStripIntimacy) && !empty($rtStripIntimacy["is_npc_scene"]);
    $rtStripSlave      = function_exists('isNpcSlave') && isNpcSlave($actorName);
    $rtStripProstitute = function_exists('isProstitute') && isProstitute($actorName);
    $rtStripSkooma     = !$rtStripSlave && function_exists('getDrugStageForActor') && (int)getDrugStageForActor($actorName, 'skooma') >= 3;
    if (!$rtStripIsNpcScene && !$rtStripSlave && !$rtStripProstitute && !$rtStripSkooma
        && !aiagentNsfwRelTypeSexEligible($actorName)) {
        $rtStrip = ["ExtCmdStartSex","ExtCmdStartBlowJob","ExtCmdStartAnalSex","ExtCmdStartMassage","ExtCmdStartThreesome","ExtCmdStartHandJobSex","ExtCmdStartTitfuck","ExtCmdStartSelfMasturbation","ExtCmdSexCommand","ExtCmdAcceptSex","ExtCmdQuickenPace","ExtCmdSlowPace","ExtCmdRemoveClothes","ExtCmdDrinkBloodSex"];

        // 1) ENABLED_FUNCTIONS (code names) - keep every downstream ENABLED_FUNCTIONS reader consistent.
        $GLOBALS["ENABLED_FUNCTIONS"] = array_values(array_filter((array)$GLOBALS["ENABLED_FUNCTIONS"], function ($f) use ($rtStrip) { return !in_array($f, $rtStrip, true); }));
        if (!in_array("ExtCmdRefuseSex", $GLOBALS["ENABLED_FUNCTIONS"], true)) { $GLOBALS["ENABLED_FUNCTIONS"][] = "ExtCmdRefuseSex"; }

        // 2) FUNC_LIST (display names) is the source the model's action pipe-list + structured-output enum are built
        //    from - strip any whose CODE name is gated, then rebuild both so the model can't select a stripped act.
        if (isset($GLOBALS["FUNC_LIST"]) && is_array($GLOBALS["FUNC_LIST"]) && function_exists('getFunctionCodeName')) {
            $GLOBALS["FUNC_LIST"] = array_values(array_filter($GLOBALS["FUNC_LIST"], function ($n) use ($rtStrip) { return !in_array(getFunctionCodeName($n), $rtStrip, true); }));
            if (isset($GLOBALS["responseTemplate"]["action"])) {
                $GLOBALS["responseTemplate"]["action"] = implode("|", $GLOBALS["FUNC_LIST"]);
            }
            if (isset($GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"]["action"]["enum"])) {
                $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"]["action"]["enum"] = array_values($GLOBALS["FUNC_LIST"]);
            }
        }
        error_log("[AIAGENTNSFW] REL-TYPE TOOL STRIP (context_pre): {$actorName} type NOT sex-eligible - sex initiators removed from ENABLED_FUNCTIONS + FUNC_LIST + action enum; RefuseSex offered");
    }
}

// ============================================================
// COMBAT BLOCK (user directive 2026-07-04): live conflict around the player - no affection or
// scene-starting toward the PLAYER until the fighting stops (tester died mid hand-hold while
// charging bandits). Player lanes only: NPC<->NPC scene routes are untouched. Exits (RefuseSex/
// EndSexScene) and redressing stay available. Same triple strip as above or the model can still
// pick the tool from the enum.
if ($actorName !== "" && isset($GLOBALS["ENABLED_FUNCTIONS"]) && is_array($GLOBALS["ENABLED_FUNCTIONS"])
    && strcasecmp($actorName, "The Narrator") !== 0 && strcasecmp($actorName, "(actor)") !== 0
    && function_exists('aiagentNsfwPlayerConflictActive') && aiagentNsfwPlayerConflictActive()) {
    $cbIntimacy = function_exists('getIntimacyForActor') ? getIntimacyForActor($actorName) : [];
    if (empty($cbIntimacy["is_npc_scene"])) {
        $cbStrip = ["ExtCmdHug","ExtCmdHoldHands","ExtCmdKiss","ExtCmdStartSex","ExtCmdStartBlowJob","ExtCmdStartAnalSex","ExtCmdStartMassage","ExtCmdStartThreesome","ExtCmdStartHandJobSex","ExtCmdStartTitfuck","ExtCmdStartSelfMasturbation","ExtCmdSexCommand","ExtCmdAcceptSex","ExtCmdRemoveClothes","ExtCmdDrinkBloodSex"];
        $GLOBALS["ENABLED_FUNCTIONS"] = array_values(array_filter((array)$GLOBALS["ENABLED_FUNCTIONS"], function ($f) use ($cbStrip) { return !in_array($f, $cbStrip, true); }));
        if (isset($GLOBALS["FUNC_LIST"]) && is_array($GLOBALS["FUNC_LIST"]) && function_exists('getFunctionCodeName')) {
            $GLOBALS["FUNC_LIST"] = array_values(array_filter($GLOBALS["FUNC_LIST"], function ($n) use ($cbStrip) { return !in_array(getFunctionCodeName($n), $cbStrip, true); }));
            if (isset($GLOBALS["responseTemplate"]["action"])) {
                $GLOBALS["responseTemplate"]["action"] = implode("|", $GLOBALS["FUNC_LIST"]);
            }
            if (isset($GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"]["action"]["enum"])) {
                $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"]["action"]["enum"] = array_values($GLOBALS["FUNC_LIST"]);
            }
        }
        error_log("[AIAGENTNSFW] COMBAT BLOCK (context_pre): live conflict near player - affection/scene tools stripped for {$actorName}");
    }
}

// ============================================================
// CHILD PROTECTION (Phase 1): for any NPC flagged as a child by HARD signals (is_child flag, child
// race, vanilla child-name blocklist), prepend an in-world "you are a child" frame to the character
// block so the model never reads an adult's attention, gifts, or kindness as romance. Default-on;
// text overridable via the child_protection_frame UI prompt. Runs BEFORE the drunk early-return so it
// applies on every turn for the child NPC, independent of intoxication state.
// ============================================================
if ($actorName !== "" && isset($GLOBALS["HERIKA_PERS"])
    && function_exists('aiagentNsfwIsChildNpc') && aiagentNsfwIsChildNpc($actorName)) {
    $__childFrame = function_exists('_getNsfwSetting') ? (string)_getNsfwSetting('CHILD_PROTECTION_FRAME', '') : '';
    if (empty($__childFrame)) {
        $__childFrame = "You are a child, not an adult. The people around you are grown-ups - some strangers, some not. You react like a kid would: curious, playful, blunt, sometimes shy or bratty. You do NOT flirt, and you never read an adult's attention, gifts, or kindness as romantic - children do not experience the world that way.";
    }
    $__childFrame = str_replace(['#NPC_NAME#', '#PLAYER_NAME#'], [$actorName, $GLOBALS['PLAYER_NAME'] ?? 'the adult'], $__childFrame);
    $GLOBALS["HERIKA_PERS"] = "#CHILD CHARACTER\n" . $__childFrame . "\n\n" . $GLOBALS["HERIKA_PERS"];
    $GLOBALS["AIAGENTNSFW_IS_CHILD"] = true;
}

if ($actorName === "" || !function_exists('getDrunkStageForActor')) { return; }

// INITIATION NUDGE (OStim audit fix 1, 2026-07-02): the Fond+/eligible autonomy lane granted the sex toolset
// SILENTLY - no prompt ever told the NPC she may initiate, so models never called the tools spontaneously.
// Injected into the character block on NORMAL turns only (never mid-scene/decision). UI-overridable.
if (!empty($GLOBALS['AIAGENTNSFW_INITIATION_AUTONOMY']) && isset($GLOBALS['HERIKA_PERS'])) {
    $__inx = function_exists('getIntimacyForActor') ? getIntimacyForActor($actorName) : [];
    if ((int)($__inx['level'] ?? 0) < 1 && empty($__inx['sex_started']) && (int)($__inx['intensity_tier'] ?? 0) < 3) {
        $__nudge = function_exists('getGlobalPrompt') ? trim((string)getGlobalPrompt('intimacy_autonomy_nudge')) : '';
        if ($__nudge === '') {
            $__pn = trim((string)($GLOBALS['PLAYER_NAME'] ?? 'your partner'));
            $__nudge = "Your bond with {$__pn} allows intimacy, and you have your own desires and initiative. "
                . "When the moment feels right to YOU - privacy, affection, flirtation - you may initiate: "
                . "start with affection actions (Give_Hug, Kiss, Hold_Hands) or begin intimacy yourself with the "
                . "scene actions (Make_Love and the others you hold). Initiating is your choice as much as theirs; "
                . "do not always wait to be asked.";
        }
        $__nudge = str_replace(['#PLAYER_NAME#', '#NPC_NAME#'], [$GLOBALS['PLAYER_NAME'] ?? 'your partner', $actorName], $__nudge);
        $GLOBALS['HERIKA_PERS'] .= "\n\n<intimacy_autonomy>\n" . $__nudge . "\n</intimacy_autonomy>";
        error_log("[AIAGENTNSFW] INITIATION NUDGE injected for {$actorName} (Fond+/eligible, normal turn)");
    }
}

// COURTSHIP NUDGE: Fond+ but rel type not eligible - she may express affection (which can flip
// her type to crush), nothing more. Without this the affection toolset was granted silently.
if (!empty($GLOBALS['AIAGENTNSFW_AFFECTION_AUTONOMY']) && isset($GLOBALS['HERIKA_PERS'])) {
    $__cnx = function_exists('getIntimacyForActor') ? getIntimacyForActor($actorName) : [];
    if ((int)($__cnx['level'] ?? 0) < 1 && empty($__cnx['sex_started']) && (int)($__cnx['intensity_tier'] ?? 0) < 3) {
        $__cnudge = function_exists('getGlobalPrompt') ? trim((string)getGlobalPrompt('affection_autonomy_nudge')) : '';
        if ($__cnudge === '') {
            $__cpn = trim((string)($GLOBALS['PLAYER_NAME'] ?? 'your companion'));
            $__cnudge = "You have grown genuinely fond of {$__cpn}. When a moment feels right to YOU - warmth, gratitude, quiet closeness - you may express affection on your own: a hug, a kiss, holding hands (Give_Hug, Kiss, Hold_Hands). Nothing beyond affection is on the table or expected; let whatever this is grow naturally.";
        }
        $__cnudge = str_replace(['#PLAYER_NAME#', '#NPC_NAME#'], [$GLOBALS['PLAYER_NAME'] ?? 'your companion', $actorName], $__cnudge);
        $GLOBALS['HERIKA_PERS'] .= "\n\n<affection_autonomy>\n" . $__cnudge . "\n</affection_autonomy>";
        error_log("[AIAGENTNSFW] COURTSHIP NUDGE injected for {$actorName} (Fond+/type not eligible, normal turn)");
    }
}

// AROUSAL RECEPTIVENESS (arousal enabled only): colors HOW arousal builds in conversation by relationship
// depth - flavor only, gates unchanged. Fond/Devoted/Bonded by affinity; courtship variant when the type
// is not yet sex-eligible. Slaves/prostitutes excluded (their lanes don't run on arousal). Normal turns only.
if (function_exists('isSexDisposalEnabled') && isSexDisposalEnabled() && isset($GLOBALS['HERIKA_PERS'])
    && function_exists('getNpcAffinity') && function_exists('getIntimacyForActor')
    && (!function_exists('isNpcSlave') || !isNpcSlave($actorName))
    && (!function_exists('isProstitute') || !isProstitute($actorName))) {
    $__arx = getIntimacyForActor($actorName);
    if ((int)($__arx['level'] ?? 0) < 1 && empty($__arx['sex_started']) && (int)($__arx['intensity_tier'] ?? 0) < 3) {
        $__arAff = (int)getNpcAffinity($actorName);
        $__arKey = '';
        if ($__arAff >= 56) {
            if (function_exists('aiagentNsfwRelTypeSexEligible') && !aiagentNsfwRelTypeSexEligible($actorName)) { $__arKey = 'arousal_recep_courtship'; }
            elseif ($__arAff >= 91) { $__arKey = 'arousal_recep_bonded'; }
            elseif ($__arAff >= 76) { $__arKey = 'arousal_recep_devoted'; }
            else { $__arKey = 'arousal_recep_fond'; }
        }
        if ($__arKey !== '') {
            $__arP = function_exists('getGlobalPrompt') ? trim((string)getGlobalPrompt($__arKey)) : '';
            if ($__arP === '') {
                $__arDefaults = [
                    'arousal_recep_fond' => "You are fond of #PLAYER_NAME#, and your body has started to notice them. Warmth builds slowly in you: genuine compliments, closeness, a lingering touch each stir you a little. You are receptive but not eager - you enjoy being warmed up, and you show it in small tells, not declarations.",
                    'arousal_recep_devoted' => "You are devoted to #PLAYER_NAME#, and desire comes readily around them. Flirtation, affection, and private moments warm you quickly, and you let them see it - leaning in, lingering, answering warmth with warmth. You still savor the build; being wanted is half the pleasure.",
                    'arousal_recep_bonded' => "You and #PLAYER_NAME# are bonded - your desire for them lives close to the surface. A look, a touch, a low word can light you up, and you are open about wanting them. You warm fast and you make it known, in your own voice, without waiting to be coaxed.",
                    'arousal_recep_courtship' => "You have grown fond of #PLAYER_NAME#, and there is a flutter you have not named yet. Their warmth affects you more than you let on - you might blush, linger, or lose your words a little. Nothing beyond affection is on the table; let the feeling build at its own pace.",
                ];
                $__arP = $__arDefaults[$__arKey] ?? '';
            }
            if ($__arP !== '') {
                $__arP = str_replace(['#PLAYER_NAME#', '#NPC_NAME#', '#AROUSAL#'],
                    [$GLOBALS['PLAYER_NAME'] ?? 'your companion', $actorName, (int)($__arx['sex_disposal'] ?? 0)], $__arP);
                $GLOBALS['HERIKA_PERS'] .= "\n\n<arousal_receptiveness>\n" . $__arP . "\n</arousal_receptiveness>";
                error_log("[AIAGENTNSFW] AROUSAL RECEPTIVENESS injected ({$__arKey}) for {$actorName}");
            }
        }
    }
}

// REDRESS NUDGE (tester report: NPCs never call PutOnClothes post-scene - same silent-toolset disease
// as the initiation bug). While she is flagged naked with no scene running, remind her the Put_On_Clothes
// action exists. Persists every normal turn until she redresses (PutOnClothes clears is_naked).
if (isset($GLOBALS['HERIKA_PERS']) && function_exists('getIntimacyForActor')) {
    $__rdx = getIntimacyForActor($actorName);
    if (!empty($__rdx['is_naked']) && (int)($__rdx['level'] ?? 0) < 1 && empty($__rdx['sex_started'])
        && (int)($__rdx['intensity_tier'] ?? 0) < 3
        && (!function_exists('isNpcSlave') || !isNpcSlave($actorName))) {
        $__rdP = function_exists('getGlobalPrompt') ? trim((string)getGlobalPrompt('redress_nudge')) : '';
        if ($__rdP === '') {
            $__rdP = "You are still undressed and the intimate moment has passed. When it feels natural - the talk winds down, you move to leave, someone could walk in - get dressed again by calling the Put_On_Clothes action. Do not stay naked through ordinary conversation unless you have a reason to.";
        }
        $__rdP = str_replace(['#PLAYER_NAME#', '#NPC_NAME#'], [$GLOBALS['PLAYER_NAME'] ?? 'your companion', $actorName], $__rdP);
        $GLOBALS['HERIKA_PERS'] .= "\n\n<redress_reminder>\n" . $__rdP . "\n</redress_reminder>";
        error_log("[AIAGENTNSFW] REDRESS NUDGE injected for {$actorName} (naked, no scene running)");
    }
}

if (function_exists('aiagentNsfwReconcileActorRuntimeAfterRollback')) {
    aiagentNsfwReconcileActorRuntimeAfterRollback($actorName, $GLOBALS["gameRequest"][2] ?? null);
}

$drunkStage = getDrunkStageForActor($actorName);
// Mood floor only tops up REAL drinking (stage>=1 from tracked Drink/Consume/Toast actions).
// A drunk mood with zero tracked drinks must not create intoxication: drunk-flavored speech
// re-arms the mood every line, which made word-drunk permanent (reported permadrunk loop).
$_lastMood = getLastIssuedMood($actorName, ($GLOBALS["gameRequest"][2] ?? time()));
if ($drunkStage >= 1) {
    if ($_lastMood === "drunk" && $drunkStage < 4) { $drunkStage = 4; }
    elseif ($_lastMood === "tipsy" && $drunkStage < 2) { $drunkStage = 2; }
}
$GLOBALS["AIAGENTNSFW_DRUNK_STAGE"] = $drunkStage; // context.php reuses this so both agree

$__promptState = NsfwNpcData::get($actorName);
if (!is_array($__promptState)) { $__promptState = []; }
$__promptStateChanged = false;
$__lastPromptedDrunk = (int)($__promptState["aiagent_nsfw_prompt_drunk_stage"] ?? 0);
$__lastPromptedSkooma = (int)($__promptState["aiagent_nsfw_prompt_skooma_level"] ?? 0);
$__lastPromptedSap = (int)($__promptState["aiagent_nsfw_prompt_sap_level"] ?? 0);
$__finalStateLock = "";

$_sceneActiveFile = sys_get_temp_dir() . "/nsfw_scene_active.txt";
$_sceneEndedFile  = sys_get_temp_dir() . "/nsfw_scene_ended.txt";
$_sceneActiveTs = is_file($_sceneActiveFile) ? (int)(file_get_contents($_sceneActiveFile) ?: 0) : 0;
$_sceneEndedTs  = is_file($_sceneEndedFile) ? (int)(file_get_contents($_sceneEndedFile) ?: 0) : 0;
$_inSexScene = in_array($GLOBALS["gameRequest"][0] ?? '', ["chatnf_sl","chatnf_sl_moan","chatnf_sl_climax","chatnf_sl_end","ext_nsfw_action","ext_nsfw_sexcene","ext_nsfw_orgasm","ext_nsfw_npc_orgasm"], true)
    || ($_sceneActiveTs > 0 && (time() - $_sceneActiveTs) < 600 && $_sceneActiveTs >= $_sceneEndedTs);

$_wdWindowActive = function_exists('aiagentNsfwWhiskeyDickActive') && aiagentNsfwWhiskeyDickActive();
// Inject the whiskey context on EVERY turn while the window is active (fix 2026-07-01) - not only at the
// in-scene trigger moment. Otherwise the impotence window is INVISIBLE: the NPC just silently loses her
// scene-call tools and the player thinks scene routing is broken (log-proven confusion tonight 19:40-19:50).
if (isset($GLOBALS["HERIKA_PERS"]) && ($_wdWindowActive || ($_inSexScene && function_exists('aiagentNsfwMaybeTriggerWhiskeyDick') && aiagentNsfwMaybeTriggerWhiskeyDick($actorName, $_inSexScene)))) {
    $_wdPrompt = getGlobalPrompt('whiskey_dick');
    if (!empty($_wdPrompt)) {
        $_wdPrompt = str_replace('#NPC_NAME#', $actorName, $_wdPrompt);
        $_wdPrompt = str_replace('#PLAYER_NAME#', $GLOBALS['PLAYER_NAME'] ?? 'Player', $_wdPrompt);
        if (_getNsfwSetting('WHISKEY_DICK_BULLYING_ENABLED', false)) {
            $_wdPrompt .= "\nTeasing is allowed if it fits your personality and relationship with #PLAYER_NAME#.";
            $_wdPrompt = str_replace('#PLAYER_NAME#', $GLOBALS['PLAYER_NAME'] ?? 'Player', $_wdPrompt);
        }
        $GLOBALS["HERIKA_PERS"] .= "\n\n#WHISKEY DICK SCENE INTERRUPTION\n{$_wdPrompt}";
    }
} elseif (!$_inSexScene && !empty($__promptState["aiagent_nsfw_intimacy_data"]["whiskey_dick_fired"])) {
    $__promptState["aiagent_nsfw_intimacy_data"]["whiskey_dick_fired"] = false;
    $__promptStateChanged = true;
}

// Persistent SHARMAT relationship/role overhead. These are injected before scene/tier/intoxication prompts so
// current relationship and checked slave/prostitute status color every model turn without changing gates/tools.
if (isset($GLOBALS["HERIKA_PERS"]) && class_exists('NsfwRelationship')) {
    $__roleOverheadBlocks = [];
    if (method_exists('NsfwRelationship', 'getRelationshipOverhead')) {
        $__relationshipOverhead = NsfwRelationship::getRelationshipOverhead($actorName, $GLOBALS['PLAYER_NAME'] ?? 'Player');
        if ($__relationshipOverhead !== '') {
            $__roleOverheadBlocks[] = $__relationshipOverhead;
        }
    }
    if (method_exists('NsfwRelationship', 'getRoleStatusOverhead')) {
        $__roleStatusOverhead = NsfwRelationship::getRoleStatusOverhead($actorName, $GLOBALS['PLAYER_NAME'] ?? 'Player');
        if ($__roleStatusOverhead !== '') {
            $__roleOverheadBlocks[] = $__roleStatusOverhead;
        }
    }
    if (method_exists('NsfwRelationship', 'getRoleContextOverhead')) {
        $__roleContextOverhead = NsfwRelationship::getRoleContextOverhead($actorName, $GLOBALS['PLAYER_NAME'] ?? 'Player');
        if ($__roleContextOverhead !== '') {
            $__roleOverheadBlocks[] = $__roleContextOverhead;
        }
    }
    if (!empty($__roleOverheadBlocks)) {
        $GLOBALS["HERIKA_PERS"] = implode("\n\n", $__roleOverheadBlocks) . "\n\n" . $GLOBALS["HERIKA_PERS"];
    }
}

// Real-drinks-only mode: the model must know Drink/Toast are flavor, or it keeps "drinking" in
// dialogue and wonders why nothing happens.
if (isset($GLOBALS["HERIKA_PERS"]) && _getNsfwSetting('DRUNK_REQUIRE_CONSUME_ACTION', true)) {
    $GLOBALS["HERIKA_PERS"] .= "\n\n#Alcohol action rule: If you actually drink alcohol, use the Consume action with the exact inventory item name (e.g. 'Nord Mead'). The Drink and Toast actions are social flavor only and never intoxicate.";
}

// Drugs (skooma 3-level stimulant + sleeping tree sap 1-hit) - injected like the alcohol level, BEFORE the
// drunk early-return so they fire even when she's sober from alcohol. Per-level text comes from the UI
// (skooma_level_1..3 / sleeping_tree_sap in aiagent_nsfw_prompts); empty = skip, so this is safe pre-UI.
if (function_exists('getDrugStageForActor') && isset($GLOBALS["HERIKA_PERS"]) && _getNsfwSetting('DRUGS_ENABLED', true)) {
    if (_getNsfwSetting('DRUG_REQUIRE_CONSUME_ACTION', true)) {
        $GLOBALS["HERIKA_PERS"] .= "\n\n#Drug action rule: If you independently decide to take skooma, redwater skooma, Balmora Blue, or Sleeping Tree Sap, use the Consume action with the exact inventory item name. Do not use Drink for hard drugs; Drink is only for ordinary beverage flavor and will not count as a drug dose.";
    }
    $_skoomaLvl = getDrugStageForActor($actorName, 'skooma');
    $_sapLvl    = getDrugStageForActor($actorName, 'sleeping_tree_sap');
    $GLOBALS["AIAGENTNSFW_SKOOMA_LEVEL"] = $_skoomaLvl; // context.php reuses these so both agree
    $GLOBALS["AIAGENTNSFW_SAP_LEVEL"]    = $_sapLvl;
    if ($_skoomaLvl >= 1) {
        $_skTxt = getGlobalPrompt("skooma_level_{$_skoomaLvl}");
        if (!empty($_skTxt)) {
            $GLOBALS["HERIKA_PERS"] .= "\n\n#CURRENT SKOOMA STATE: LEVEL {$_skoomaLvl}/3. This is the ONLY active skooma level. Use the UI prompt below for this level only; do not combine it with older/lower/higher skooma behavior from chat history."
                . "\n#SKOOMA LEVEL {$_skoomaLvl}/3 - {$_skTxt}"
                . "\nStay consistent with EXACTLY this current level throughout your entire reply and every line until it wears off.";
            $__finalStateLock .= "\n\n#FINAL CURRENT SKOOMA STATE LOCK\nCurrent skooma state is LEVEL {$_skoomaLvl}/3. This overrides relationship summaries, memories, and chat history. Use ONLY the UI prompt for this current level:\n{$_skTxt}\nDo not borrow behavior from old skooma levels, sap, alcohol, or previous intoxicated lines.";
        }
    } elseif ($__lastPromptedSkooma > 0) {
        $GLOBALS["HERIKA_PERS"] .= "\n\n#" . (getGlobalPrompt('skooma_worn_off') ?: "SKOOMA HAS WORN OFF. You are not currently on skooma and are not currently in skooma withdrawal. Stop using skooma speech, cravings, speed, jitter, euphoria, or crash behavior unless a new CURRENT SKOOMA STATE prompt appears.");
        $__finalStateLock .= "\n\n#FINAL CURRENT SKOOMA STATE LOCK\nSkooma has worn off. You are not currently on skooma and are not currently in skooma withdrawal. Ignore older skooma behavior in memories, relationship summaries, and chat history.";
    }
    if ($_sapLvl >= 1) {
        $_sapTxt = getGlobalPrompt("sleeping_tree_sap");
        if (!empty($_sapTxt)) {
            $GLOBALS["HERIKA_PERS"] .= "\n\n#CURRENT SLEEPING TREE SAP STATE. This is the ONLY active sap state. Use the UI prompt below; do not combine it with older skooma or alcohol behavior from chat history."
                . "\n#SLEEPING TREE SAP - {$_sapTxt}"
                . "\nStay consistent with this current sap state throughout your entire reply and every line until it wears off.";
            $__finalStateLock .= "\n\n#FINAL CURRENT SLEEPING TREE SAP STATE LOCK\nCurrent sap state is active. This overrides relationship summaries, memories, and chat history. Use ONLY the UI prompt for this current sap state:\n{$_sapTxt}\nDo not borrow behavior from skooma, alcohol, or previous intoxicated lines.";
        }
    } elseif ($__lastPromptedSap > 0) {
        $GLOBALS["HERIKA_PERS"] .= "\n\n#" . (getGlobalPrompt('sap_worn_off') ?: "SLEEPING TREE SAP HAS WORN OFF. You are no longer dazed, dreamy, or paralyzed by sap. Stop using sap speech or sap body-state behavior unless a new CURRENT SLEEPING TREE SAP STATE prompt appears.");
        $__finalStateLock .= "\n\n#FINAL CURRENT SLEEPING TREE SAP STATE LOCK\nSleeping Tree Sap has worn off. Ignore older sap behavior in memories, relationship summaries, and chat history.";
    }

    if ($_skoomaLvl !== $__lastPromptedSkooma) {
        $__promptState["aiagent_nsfw_prompt_skooma_level"] = $_skoomaLvl;
        $__promptStateChanged = true;
    }
    if ($_sapLvl !== $__lastPromptedSap) {
        $__promptState["aiagent_nsfw_prompt_sap_level"] = $_sapLvl;
        $__promptStateChanged = true;
    }
}

// SOBER REINFORCEMENT: the worn-off cue used to fire ONCE on the transition turn, but the model
// keeps slurring off its own drunk chat history ("I talk like a drunk, so I am one"). Persist the
// sobriety override for several turns after sobering so the history momentum breaks.
$__soberLeft = (int)($__promptState["aiagent_nsfw_sober_reinforce"] ?? 0);
if (isset($GLOBALS["HERIKA_PERS"]) && $drunkStage < 1 && ($__lastPromptedDrunk > 0 || $__soberLeft > 0)) {
    $GLOBALS["HERIKA_PERS"] .= "\n\n#" . (getGlobalPrompt('alcohol_worn_off') ?: "ALCOHOL HAS WORN OFF. You are fully sober RIGHT NOW. Speak in your normal voice: no slurring, no hiccups, no drunken word contractions, no drunk behavior of any kind. Recent drunk-sounding lines in your chat history are from EARLIER, while you were drunk - they do not describe how you speak now. Only a new CURRENT ALCOHOL LEVEL prompt can make you drunk again.");
    $__finalStateLock .= "\n\n#FINAL CURRENT ALCOHOL STATE LOCK\nAlcohol has worn off. You are fully sober. Ignore drunk speech in memories, relationship summaries, and chat history - it is in the past.";
    $__newSoberLeft = ($__lastPromptedDrunk > 0)
        ? max(1, (int)_getNsfwSetting('SOBER_REINFORCE_TURNS', 4))
        : max(0, $__soberLeft - 1);
    if ($__newSoberLeft !== $__soberLeft) {
        $__promptState["aiagent_nsfw_sober_reinforce"] = $__newSoberLeft;
        $__promptStateChanged = true;
    }
} elseif ($drunkStage >= 1 && $__soberLeft > 0) {
    $__promptState["aiagent_nsfw_sober_reinforce"] = 0; // drinking again cancels the sober override
    $__promptStateChanged = true;
}

if ($drunkStage !== $__lastPromptedDrunk) {
    $__promptState["aiagent_nsfw_prompt_drunk_stage"] = $drunkStage;
    $__promptStateChanged = true;
}

if ($__promptStateChanged) {
    NsfwNpcData::save($actorName, $__promptState);
}

if (!empty($__finalStateLock)) {
    if (isset($dynamicBiography)) {
        $dynamicBiography .= $__finalStateLock;
    } elseif (isset($GLOBALS["HERIKA_PERS"])) {
        $GLOBALS["HERIKA_PERS"] .= $__finalStateLock;
    }
}

if ($drunkStage < 1 || !isset($GLOBALS["HERIKA_PERS"])) { return; }

$stagePrompt = getGlobalPrompt("drunk_stage_{$drunkStage}");
if (empty($stagePrompt)) { return; }

$GLOBALS["HERIKA_PERS"] .= "\n\n#CURRENT ALCOHOL STATE: LEVEL {$drunkStage}/10. This is the ONLY active alcohol level. Use the UI prompt below for this level only; do not combine it with older/lower/higher drunk behavior from chat history."
    . "\n#ALCOHOL LEVEL {$drunkStage}/10 - {$stagePrompt}"
    . "\nStay consistent with EXACTLY this current level throughout your entire reply and every line until it wears off. Match this level - do not act more or less affected than it describes.";

if (isset($dynamicBiography)) {
    $dynamicBiography .= "\n\n#FINAL CURRENT ALCOHOL STATE LOCK\nCurrent alcohol state is LEVEL {$drunkStage}/10. This overrides relationship summaries, memories, and chat history. Use ONLY the UI prompt for this current alcohol level:\n{$stagePrompt}\nDo not borrow behavior from old alcohol levels, skooma, sap, or previous intoxicated lines.";
}

// Active OStim/SexLab scene? Count scene-event turns AND an active scene on normal chat turns (file marker,
// same one the physics/rechat guards use) so the prompt stays consistent with the suppressed physics.
// Collapsed on the floor at the top stage - never during a scene (she's mid-act, not passed out cold)
if ($drunkStage >= 10 && !$_inSexScene) {
    $GLOBALS["HERIKA_PERS"] .= "\n#You have collapsed and are lying on the floor, too drunk to stand. Speak from the ground.";
}

// In an active intimate scene, layer the intoxicated-sex framing on top
if ($_inSexScene) {
    $intoxSex = getGlobalPrompt("intoxicated_sex");
    if (!empty($intoxSex)) { $GLOBALS["HERIKA_PERS"] .= "\n#{$intoxSex}"; }
}
