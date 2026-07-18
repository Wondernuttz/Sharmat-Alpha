<?php
/**
 * NSFW Scene Prompts
 * These prompts are now configurable via the NSFW Config Manager UI (Prompts tab)
 * Settings are stored in conf_opts table under 'aiagent_nsfw_prompts' key
 *
 * NOTE: All $GLOBALS references use null coalescing (??) to provide fallbacks
 * when loaded outside game context (e.g., config manager UI polling).
 * This prevents "Undefined global variable" warnings.
 */

// Load prompt settings from database (set by NSFW Config Manager UI)
$_nsfwPromptSettings = null;
function _getNsfwPromptSetting($key, $default = '') {
    global $_nsfwPromptSettings;

    // Lazy load settings from database
    if ($_nsfwPromptSettings === null) {
        $_nsfwPromptSettings = [];
        if (isset($GLOBALS["db"])) {
            try {
                $row = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_prompts'");
                if ($row && !empty($row['value'])) {
                    $_nsfwPromptSettings = json_decode($row['value'], true) ?: [];
                }
            } catch (Exception $e) {
                // Silently fail, use defaults
            }
        }
    }

    return isset($_nsfwPromptSettings[$key]) && !empty($_nsfwPromptSettings[$key])
        ? $_nsfwPromptSettings[$key]
        : $default;
}

// _getNsfwSetting() lives in common.php. UI/test entry points can load this
// file directly via CHIM's recursive prompts loader without hitting prerequest
// or preprocessing first, so keep this prompt file self-contained.
if (!function_exists('_getNsfwSetting')) {
    require_once(__DIR__ . "/common.php");
}

// Helper to parse multi-line cues (one per line) into array
function _parseNsfwCues($setting, $defaults) {
    if (empty($setting)) {
        return $defaults;
    }
    $lines = array_filter(array_map('trim', explode("\n", $setting)));
    return !empty($lines) ? $lines : $defaults;
}

// Helper to wrap content in XML tags for LLM context separation
function _wrapNsfwXml($tag, $content) {
    if (empty($content)) {
        return '';
    }
    return "<{$tag}>\n{$content}\n</{$tag}>";
}

function _resolveNsfwSpeakStyleCue($cue, $actorName = '') {
    $actorName = $actorName ?: ($GLOBALS["HERIKA_NAME"] ?? '');
    if (function_exists('aiagentNsfwResolveSpeakStylePlaceholders') && $actorName !== '') {
        $status = [];
        if (function_exists('getIntimacyForActor')) {
            $status = getIntimacyForActor($actorName);
            if (!is_array($status)) {
                $status = [];
            }
        }
        return aiagentNsfwResolveSpeakStylePlaceholders($cue, $actorName, $status);
    }
    return (string)$cue;
}

// ============================================
// SCENE CUES - Default Fallback
// ============================================
// These are the FALLBACK cues for NPCs without configured speak styles.
// For configured NPCs, prerequest.php overrides these with the NPC's speak style
// AFTER the tier prompt accept/refuse logic runs (when level > 0).
// ============================================

// Active sex scene - global default cues (fallback for unconfigured NPCs)
$_chatnfSlDefaults = [
    "(Focus on intimate scene participants,moans and gasps,SHORT speech, explicit words)",
    "(Focus on intimate scene description,moans and gasps,SHORT speech, explicit words)",
    "(explain pleasure,moans and gasps,SHORT speech, explicit words)",
    "(give a compliment,moans and gasps,SHORT speech, explicit words)",
    "(moans and gasps,short speech, explicit words)"
];
$_chatnfSlCues = _parseNsfwCues(_getNsfwPromptSetting('chatnf_sl_cues', ''), $_chatnfSlDefaults);

// Get configurable token limit (default 100)
$_sexSceneTokenLimit = _getNsfwSetting('TOKEN_LIMIT_SEX_SCENE', 100);

$GLOBALS["PROMPTS"]["chatnf_sl"] = [
    "cue" => array_map(function($cue) {
        return _wrapNsfwXml('response_instruction', $cue) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
    }, $_chatnfSlCues),
    "player_request" => [($GLOBALS["PLAYER_NAME"] ?? "The Narrator") . ": "],
    "extra" => ["force_tokens_max" => $_sexSceneTokenLimit]
];

// Non-explicit version (same structure, no explicit words)
$_chatnfSlNrDefaults = [
    "(Focus on intimate scene participants)",
    "(Focus on scene description)",
    "(explain pleasure)",
    "(give a compliment)",
    "(moans and gasps)"
];
$_chatnfSlNrCues = _parseNsfwCues(_getNsfwPromptSetting('chatnf_sl_nr_cues', ''), $_chatnfSlNrDefaults);

$GLOBALS["PROMPTS"]["chatnf_sl_nr"] = [
    "cue" => array_map(function($cue) {
        return _wrapNsfwXml('response_instruction', $cue) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
    }, $_chatnfSlNrCues),
    "player_request" => [$GLOBALS["gameRequest"][3] ?? ""],
    "extra" => ["force_tokens_max" => $_sexSceneTokenLimit]
];

// Affection/romantic scenes (tier 1/2) use the non-explicit cue, never the explicit one
if (isset($GLOBALS["AIAGENTNSFW_AFFECTION_TIER"]) && (int)$GLOBALS["AIAGENTNSFW_AFFECTION_TIER"] <= 2) {
    $GLOBALS["PROMPTS"]["chatnf_sl"]["cue"] = $GLOBALS["PROMPTS"]["chatnf_sl_nr"]["cue"];
}

// Consent/tier gate beats must never use the default moan/explicit chatnf_sl cue.
// chatnf_sl is often the only NPC turn OStim emits, so the relationship gate cue
// has to override this event too, not only ext_nsfw_sexcene.
if (!empty($GLOBALS["AIAGENTNSFW_TIER_CUE_OVERRIDE"])) {
    $GLOBALS["PROMPTS"]["chatnf_sl"]["cue"] = [
        $GLOBALS["AIAGENTNSFW_TIER_CUE_OVERRIDE"] . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")
    ];
    $GLOBALS["PROMPTS"]["chatnf_sl"]["player_request"] = [$GLOBALS["gameRequest"][3] ?? ""];
    $GLOBALS["PROMPTS"]["chatnf_sl_nr"]["cue"] = $GLOBALS["PROMPTS"]["chatnf_sl"]["cue"];
    $GLOBALS["PROMPTS"]["chatnf_sl_nr"]["player_request"] = $GLOBALS["PROMPTS"]["chatnf_sl"]["player_request"];
}

// Climax/Orgasm moment - falls back to NPC's speak style climax_prompt
$_climaxCue = _getNsfwPromptSetting('climax', '');
// Replace #NPC_NAME# placeholder with actual NPC name
$_climaxCue = str_replace('#NPC_NAME#', $GLOBALS["HERIKA_NAME"] ?? "", $_climaxCue);

// If no global climax cue set, use NPC's speak style climax_prompt
// First check if nsfw_ostim_handler already stored it (bypasses Narrator path)
if (empty(trim($_climaxCue)) && !empty($GLOBALS["AIAGENTNSFW_CLIMAX_PROMPT"])) {
    $_climaxCue = $GLOBALS["AIAGENTNSFW_CLIMAX_PROMPT"];
    $_climaxCue = _resolveNsfwSpeakStyleCue($_climaxCue);
}
// Fallback: load from NPC's speak style directly
if (empty(trim($_climaxCue))) {
    require_once(__DIR__."/nsfw_data.php");
    $_npcName = $GLOBALS["HERIKA_NAME"] ?? '';
    if (!empty($_npcName)) {
        $_extendedData = NsfwNpcData::get($_npcName);
        if (!empty($_extendedData["sex_speech_style"])) {
            $_speakStyleData = NsfwData::getSpeakStyle($_extendedData["sex_speech_style"]);
            $_climaxCue = $_speakStyleData['climax_prompt'] ?? '';
            $_climaxCue = _resolveNsfwSpeakStyleCue($_climaxCue, $_npcName);
        }
    }
}

// Get configurable climax token limit (default 50 - very short)
$_climaxTokenLimit = _getNsfwSetting('TOKEN_LIMIT_CLIMAX', 50);

$GLOBALS["PROMPTS"]["chatnf_sl_climax"] = [
    "cue" => [_wrapNsfwXml('climax_instruction', $_climaxCue) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => "YEEAH!",
    "extra" => ["force_tokens_max" => $_climaxTokenLimit]
];

// Post-sex pillow talk
$_chatnfSlEndDefaults = [
    "(#NPC_NAME# talks about intimate scene result)",
    "(#NPC_NAME# talks about best sex moment)",
    "(#NPC_NAME# talks about something people usually talk about after sex)"
];
$_chatnfSlEndCues = _parseNsfwCues(_getNsfwPromptSetting('chatnf_sl_end_cues', ''), $_chatnfSlEndDefaults);

$GLOBALS["PROMPTS"]["chatnf_sl_end"] = [
    "cue" => array_map(function($cue) {
        $cue = str_replace('#NPC_NAME#', $GLOBALS["HERIKA_NAME"] ?? "", $cue);
        return _wrapNsfwXml('post_scene_instruction', $cue) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
    }, $_chatnfSlEndCues)
];

// Masturbation start
$_masturbationDefault = "#NPC_NAME# moans about being aroused, and starts self masturbation.";
$_masturbationCue = _getNsfwPromptSetting('masturbation_start', $_masturbationDefault);
$_masturbationCue = str_replace('#NPC_NAME#', $GLOBALS["HERIKA_NAME"] ?? "", $_masturbationCue);

$GLOBALS["PROMPTS"]["afterfunc"]["cue"]["ExtCmdStartSelfMasturbation"] = _wrapNsfwXml('action_event', $_masturbationCue) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");

// ============================================
// CONTEXTUAL NSFW EVENTS (OStim scene-aware)
// ============================================

// Scene commentary - Focus on sensations during active scene
$_sceneCommentaryDefault = "(SEX. Focus on 1-2 things that feel MOST intense right now. Talk about the SENSATION, not a list of actions. Be natural. 1-2 sentences max. Moans and gasps, explicit words.)";
$_sceneCommentary = _getNsfwPromptSetting('scene_commentary', $_sceneCommentaryDefault);

// ext_nsfw_scene - uses gameRequest[3] which contains the OStim scene description
// common.php:processInfoSexScene() sets: "#INTIMATE SCENE: [description]. Scene tags: [tags]"
$GLOBALS["PROMPTS"]["ext_nsfw_scene"] = [
    "cue" => [_wrapNsfwXml('response_instruction', $_sceneCommentary) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => [$GLOBALS["gameRequest"][3] ?? ""]
];

// ext_nsfw_npc_invite - NPC-to-NPC invite phase (dom approaching sub)
// This fires BEFORE the scene starts when one NPC approaches another
$_npcInviteDefault = "NPC invite/walk-to phase: #NPC_NAME# is #NPC_INVITE_ACTION# #PRIMARY_PARTNER# with romantic or sexual intent. This is only an invitation or approach; no sex scene has started yet. React only to the invitation, willingness, hesitation, flirtation, or refusal. Do not describe physical sex, pleasure, penetration, climax, or an active scene yet.";
$_npcInviteCue = _getNsfwPromptSetting('npc_invite', $_npcInviteDefault);
$_npcContextReminderDefault = "Stay anchored to the NPC-only scene with #PRIMARY_PARTNER#. React as #NPC_NAME# using your own speech style, current relationship context, and the current scene description. Keep it brief and in character.";
$_npcContextReminderText = trim((string)($GLOBALS["AIAGENTNSFW_NPC_CONTEXT_REMINDER_TEXT"] ?? _getNsfwPromptSetting('npc_context_reminder', $_npcContextReminderDefault)));
$_npcContextReminderPrefix = ($_npcContextReminderText !== '')
    ? _wrapNsfwXml('npc_context_reminder', $_npcContextReminderText) . "\n"
    : "";

$GLOBALS["PROMPTS"]["ext_nsfw_npc_invite"] = [
    "cue" => [_wrapNsfwXml('npc_invite_instruction', $_npcInviteCue) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => [$GLOBALS["gameRequest"][3] ?? ""]
];

// chatnf_npc_sl - NPC-to-NPC scene speech (no player involved)
// Used when two NPCs are in a scene together without the player
$_npcSceneDefaults = [
    "(Focus on your partner #PRIMARY_PARTNER#. Describe the sensation. Moans and gasps, explicit words. SHORT speech.)",
    "(React to #PRIMARY_PARTNER#. Express pleasure or desire. Moans, explicit. 1-2 sentences.)",
    "(Tell #PRIMARY_PARTNER# how they make you feel. Moans and gasps. SHORT.)",
    "(Moan, gasp, respond to #PRIMARY_PARTNER#. Explicit words. Brief.)"
];
$_npcSceneCues = _parseNsfwCues(_getNsfwPromptSetting('chatnf_npc_sl_cues', ''), $_npcSceneDefaults);

$GLOBALS["PROMPTS"]["chatnf_npc_sl"] = [
    "cue" => array_map(function($cue) {
        return _wrapNsfwXml('npc_scene_instruction', $cue) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
    }, $_npcSceneCues),
    "player_request" => ["The Narrator: "]
];

// ext_nsfw_sexcene - OStim scene events (legacy name with typo, kept for compatibility)
// This is sent by AIAgentNSFW.psc when OStim scenes update
// Scene description is injected by common.php into gameRequest[3]
// During tier_prompt phase, use the relationship-based tier cue instead of explicit sex commentary.
// Standing/intro tier-0 overrides also beat the default sex cue; those scenes are presence-only.
if (!empty($GLOBALS["AIAGENTNSFW_TIER_CUE_OVERRIDE"])) {
    $_sexsceneCue = $GLOBALS["AIAGENTNSFW_TIER_CUE_OVERRIDE"] . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
} else if (!empty($GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"]) && isset($GLOBALS["AIAGENTNSFW_AFFECTION_TIER"]) && (int)$GLOBALS["AIAGENTNSFW_AFFECTION_TIER"] <= 2) {
    // <= 2 (was <= 0): tier-0 standing AND tier-1/2 affection both use the non-sexual cue the affection branch set.
    // Otherwise affection beats fell through to the default sexual commentary below and she sexualized a hug/hold-hands.
    $_sexsceneCue = $GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"];
} else {
    $_sexsceneCue = _wrapNsfwXml('response_instruction', $_sceneCommentary) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
}
$GLOBALS["PROMPTS"]["ext_nsfw_sexcene"] = [
    "cue" => [$_sexsceneCue],
    "player_request" => [$GLOBALS["gameRequest"][3] ?? ""],
    "extra" => ["force_tokens_max" => $_sexSceneTokenLimit]
];

// ext_nsfw_action - Scene action events (position changes, etc.)
// Also uses gameRequest[3] for scene context
$GLOBALS["PROMPTS"]["ext_nsfw_action"] = [
    "cue" => [_wrapNsfwXml('response_instruction', $_sceneCommentary) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => [$GLOBALS["gameRequest"][3] ?? ""]
];

// Contextual orgasm - falls back to NPC's speak style climax_prompt
$_orgasmCue = _getNsfwPromptSetting('orgasm', '');
$_orgasmCue = str_replace('#NPC_NAME#', $GLOBALS["HERIKA_NAME"] ?? "", $_orgasmCue);

// If no global orgasm cue set, use NPC's speak style climax_prompt
// First check if nsfw_ostim_handler already stored it (bypasses Narrator path)
if (empty(trim($_orgasmCue)) && !empty($GLOBALS["AIAGENTNSFW_CLIMAX_PROMPT"])) {
    $_orgasmCue = $GLOBALS["AIAGENTNSFW_CLIMAX_PROMPT"];
    $_orgasmCue = _resolveNsfwSpeakStyleCue($_orgasmCue);
}
// Fallback: load from NPC's speak style directly
if (empty(trim($_orgasmCue))) {
    require_once(__DIR__."/nsfw_data.php");
    $_npcName = $GLOBALS["HERIKA_NAME"] ?? '';
    if (!empty($_npcName)) {
        $_extendedData = NsfwNpcData::get($_npcName);
        if (!empty($_extendedData["sex_speech_style"])) {
            $_speakStyleData = NsfwData::getSpeakStyle($_extendedData["sex_speech_style"]);
            $_orgasmCue = $_speakStyleData['climax_prompt'] ?? '';
            $_orgasmCue = _resolveNsfwSpeakStyleCue($_orgasmCue, $_npcName);
        }
    }
}

$GLOBALS["PROMPTS"]["ext_nsfw_orgasm"] = [
    "cue" => [_wrapNsfwXml('climax_instruction', $_orgasmCue) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => [""],
    "extra" => ["force_tokens_max" => $_climaxTokenLimit]
];

// Apply prerequest.php's computed cue (handles player vs NPC orgasm routing).
// prerequest.php stores this global instead of directly setting $GLOBALS["PROMPTS"]
// to avoid the require_once blocking re-load issue after prompts/prompts.php resets $PROMPTS.
if (!empty($GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"])) {
    $GLOBALS["PROMPTS"]["ext_nsfw_orgasm"]["cue"] = [$GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"] . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")];
}

// ext_nsfw_payment_check - prostitute gift/payment reaction. The event is otherwise silent (no PROMPTS
// entry -> "Request cue is empty"); handlePaymentCheck computes the cue (names the exact item + value vs
// price) into AIAGENTNSFW_PAYMENT_CUE so she reacts immediately the moment the trade menu closes.
if (!empty($GLOBALS["AIAGENTNSFW_PAYMENT_CUE"])) {
    $GLOBALS["PROMPTS"]["ext_nsfw_payment_check"] = [
        "cue" => [$GLOBALS["AIAGENTNSFW_PAYMENT_CUE"] . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
        "player_request" => [$GLOBALS["AIAGENTNSFW_PAYMENT_CUE"]],
    ];
}

// ext_nsfw_npc_scene - NPC-to-NPC scene update (no player)
// Uses the npc_scene_active prompt from config
$_npcSceneActiveDefault = "(You are currently in an intimate/sexual scene with #PRIMARY_PARTNER#. React naturally to the ongoing action. Express pleasure, desire, or other appropriate emotions. Keep responses SHORT.)";
$_npcSceneActiveCue = _getNsfwPromptSetting('npc_scene_active', $_npcSceneActiveDefault);

$GLOBALS["PROMPTS"]["ext_nsfw_npc_scene"] = [
    "cue" => [_wrapNsfwXml('npc_scene_instruction', $_npcSceneActiveCue) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => [$GLOBALS["gameRequest"][3] ?? ""]
];

if (!empty($GLOBALS["AIAGENTNSFW_NPC_GATE_DISABLED_CUE"])) {
    $_npcGateDisabledCue = $GLOBALS["AIAGENTNSFW_NPC_GATE_DISABLED_CUE"];
    foreach (["ext_nsfw_npc_scene", "chatnf_npc_sl"] as $_npcGateKey) {
        if (isset($GLOBALS["PROMPTS"][$_npcGateKey]["cue"])) {
            foreach ($GLOBALS["PROMPTS"][$_npcGateKey]["cue"] as &$_npcGateCue) {
                $_npcGateCue = $_npcGateDisabledCue . "\n" . $_npcGateCue;
            }
            unset($_npcGateCue);
        }
    }
}

// ext_nsfw_npc_orgasm - NPC orgasm in NPC-to-NPC scene
// Uses the npc_orgasm prompt from config (third-person perspective)
$_npcOrgasmDefault = "(#NPC_NAME# is reaching climax with #PRIMARY_PARTNER#. Express the peak of pleasure - moans, gasps, cries. VERY SHORT - 3-5 words max.)";
$_npcOrgasmCue = _getNsfwPromptSetting('npc_orgasm', $_npcOrgasmDefault);
$_npcOrgasmCue = str_replace('#NPC_NAME#', $GLOBALS["HERIKA_NAME"] ?? "", $_npcOrgasmCue);

$GLOBALS["PROMPTS"]["ext_nsfw_npc_orgasm"] = [
    "cue" => [_wrapNsfwXml('npc_climax_instruction', $_npcOrgasmCue) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => [""],
    "extra" => ["force_tokens_max" => $_climaxTokenLimit]
];

if (!empty($GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"]) && (($GLOBALS["gameRequest"][0] ?? '') === 'ext_nsfw_npc_orgasm')) {
    $GLOBALS["PROMPTS"]["ext_nsfw_npc_orgasm"]["cue"] = [$GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"] . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")];
}

// Engaged scene cue (deferred from prerequest): the NPC's configured speak-style, or the slave scene cue.
// Deferred because prerequest's direct chatnf_sl write is clobbered by the rebuild above.
// Skipped for chaste affection tiers (1/2, non-explicit); non-consent + pillow below still override it.
if (!empty($GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"])
    && (empty($GLOBALS["AIAGENTNSFW_AFFECTION_TIER"]) || (int)$GLOBALS["AIAGENTNSFW_AFFECTION_TIER"] > 2)) {
    $GLOBALS["PROMPTS"]["chatnf_sl"]["cue"] = [$GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"]];
    $GLOBALS["PROMPTS"]["chatnf_sl"]["player_request"] = [$GLOBALS["gameRequest"][3] ?? ""];
    $GLOBALS["PROMPTS"]["chatnf_sl_nr"]["cue"] = $GLOBALS["PROMPTS"]["chatnf_sl"]["cue"];
    $GLOBALS["PROMPTS"]["chatnf_sl_nr"]["player_request"] = $GLOBALS["PROMPTS"]["chatnf_sl"]["player_request"];

    $_sceneOverrideEvent = $GLOBALS["gameRequest"][0] ?? "";
    if (!empty($GLOBALS["AIAGENTNSFW_NPC_SCENE"]) || in_array($_sceneOverrideEvent, ["ext_nsfw_npc_scene", "chatnf_npc_sl"], true)) {
        foreach (["ext_nsfw_npc_scene", "chatnf_npc_sl"] as $_npcScenePromptKey) {
            if (isset($GLOBALS["PROMPTS"][$_npcScenePromptKey])) {
                $GLOBALS["PROMPTS"][$_npcScenePromptKey]["cue"] = [$GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"]];
                $GLOBALS["PROMPTS"][$_npcScenePromptKey]["player_request"] = [$GLOBALS["gameRequest"][3] ?? ""];
            }
        }
    }
}
if (!empty($GLOBALS["AIAGENTNSFW_SCENE_CLIMAX_OVERRIDE"])) {
    $GLOBALS["PROMPTS"]["chatnf_sl_climax"]["cue"] = [$GLOBALS["AIAGENTNSFW_SCENE_CLIMAX_OVERRIDE"]];
}

if ($_npcContextReminderPrefix !== '') {
    foreach (["ext_nsfw_npc_scene", "chatnf_npc_sl"] as $_npcReminderKey) {
        if (!isset($GLOBALS["PROMPTS"][$_npcReminderKey]["cue"])) {
            continue;
        }
        foreach ($GLOBALS["PROMPTS"][$_npcReminderKey]["cue"] as &$_npcReminderCue) {
            if (strpos($_npcReminderCue, '<npc_context_reminder>') === false) {
                $_npcReminderCue = $_npcContextReminderPrefix . $_npcReminderCue;
            }
        }
        unset($_npcReminderCue);
    }
}

// #8 gag precedence: a gagged NPC can't articulate, so suppress the explicit-words cue by swapping to the
// non-explicit variant. The device_gag note (context.php, injected last) adds the muffled framing on top.
if (getGlobalPrompt("dd_awareness") === '1' && getGlobalPrompt("dd_gag_muffle") === '1' && !empty($GLOBALS["PROMPTS"]["chatnf_sl_nr"]["cue"])) {
    require_once(__DIR__."/nsfw_data.php");
    $_gagName = $GLOBALS["HERIKA_NAME"] ?? '';
    if (!empty($_gagName)) {
        $_gagWorn = NsfwNpcData::get($_gagName)["aiagent_nsfw_devices"] ?? '';
        if (strpos(",{$_gagWorn},", ",gag,") !== false) {
            $GLOBALS["PROMPTS"]["chatnf_sl"]["cue"] = $GLOBALS["PROMPTS"]["chatnf_sl_nr"]["cue"];
            // Scene-event turns own live scene dialogue - swap them too, and drop the deferred speak-style
            // cue override so the explicit style can't ride over the muffle (fix 2026-07-02, audit #4).
            foreach (["ext_nsfw_sexcene", "chatnf_sl_moan"] as $_gagEvt) {
                if (!empty($GLOBALS["PROMPTS"][$_gagEvt]["cue"])) {
                    $GLOBALS["PROMPTS"][$_gagEvt]["cue"] = $GLOBALS["PROMPTS"]["chatnf_sl_nr"]["cue"];
                }
            }
            unset($GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"]);
        }
    }
}

// Non-consent (deferred from prerequest forced_scene): force the sex cues to a reluctant/distressed instruction
// so a refused-but-forced NPC isn't handed a "moan with pleasure" cue. Pillow override below still wins if both set.
if (!empty($GLOBALS["AIAGENTNSFW_NONCONSENT_CUE"])) {
    $_ncCue = _wrapNsfwXml('response_instruction', $GLOBALS["AIAGENTNSFW_NONCONSENT_CUE"]) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
    foreach (["chatnf_sl","chatnf_sl_climax","ext_nsfw_sexcene","ext_nsfw_action"] as $_nck) {
        if (isset($GLOBALS["PROMPTS"][$_nck])) {
            $GLOBALS["PROMPTS"][$_nck]["cue"] = [$_ncCue];
        }
    }
}

// Pillow talk (deferred from prerequest.php): scene just ended, override EVERY sex cue to stop reignition.
// Must run here because prompts.php rebuilds all cues after prerequest; a direct write in prerequest is clobbered.
if (!empty($GLOBALS["AIAGENTNSFW_PILLOW_TALK_CUE"])) {
    $_pillowCue = $GLOBALS["AIAGENTNSFW_PILLOW_TALK_CUE"] . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
    foreach (["chatnf_sl","chatnf_sl_end","chatnf_sl_climax","ext_nsfw_orgasm","ext_nsfw_sexcene","ext_nsfw_action","ext_nsfw_npc_scene","ext_nsfw_npc_orgasm","chatnf_npc_sl"] as $_pk) {
        if (isset($GLOBALS["PROMPTS"][$_pk])) {
            $GLOBALS["PROMPTS"][$_pk]["cue"] = [$_pillowCue];
        }
    }
}

// NPC-to-NPC partner placeholders (deferred from prerequest): resolve AFTER the npc cues are (re)built and
// after pillow/non-consent overrides, otherwise literal placeholders ship to the LLM.
if (!empty($GLOBALS["AIAGENTNSFW_NPC_PARTNER_NAME"])) {
    $_npcPartner = $GLOBALS["AIAGENTNSFW_NPC_PARTNER_NAME"];
    $_npcSpeaker = $GLOBALS["HERIKA_NAME"] ?? "";
    $_npcInviteRole = trim((string)($GLOBALS["AIAGENTNSFW_NPC_INVITE_ROLE"] ?? ""));
    $_npcInviteInitiator = trim((string)($GLOBALS["AIAGENTNSFW_NPC_INVITE_INITIATOR"] ?? ""));
    $_npcInviteTarget = trim((string)($GLOBALS["AIAGENTNSFW_NPC_INVITE_TARGET"] ?? ""));

    $_npcStatus = [];
    if (function_exists('getIntimacyForActor') && $_npcSpeaker !== '') {
        $_npcStatus = getIntimacyForActor($_npcSpeaker);
        if (!is_array($_npcStatus)) {
            $_npcStatus = [];
        }
    }
    $_npcStatus["is_npc_scene"] = true;
    $_npcStatus["npc_scene_partner"] = $_npcPartner;
    if ($_npcInviteRole !== "") {
        $_npcStatus["npc_invite_role"] = $_npcInviteRole;
    }
    if ($_npcInviteInitiator !== "") {
        $_npcStatus["npc_invite_initiator"] = $_npcInviteInitiator;
    }
    if ($_npcInviteTarget !== "") {
        $_npcStatus["npc_invite_target"] = $_npcInviteTarget;
    }

    foreach (["ext_nsfw_npc_scene","ext_nsfw_npc_invite","ext_nsfw_npc_orgasm","chatnf_npc_sl"] as $_npck) {
        if (isset($GLOBALS["PROMPTS"][$_npck]["cue"])) {
            foreach ($GLOBALS["PROMPTS"][$_npck]["cue"] as &$_npcCue) {
                if (function_exists('aiagentNsfwRenderNpcScenePrompt')) {
                    $_npcCue = aiagentNsfwRenderNpcScenePrompt($_npcCue, $_npcSpeaker, $_npcStatus);
                } else {
                    $_npcCue = str_replace(
                        ['#NPC_NAME#', '#NPC_PARTNER#', '#PRIMARY_PARTNER#', '#PLAYER_NAME#'],
                        [$_npcSpeaker, $_npcPartner, $_npcPartner, $GLOBALS["PLAYER_NAME"] ?? "Player"],
                        $_npcCue
                    );
                }
            }
            unset($_npcCue);
        }
    }
}

// Engaged rechat (deferred): mirror the FINAL chatnf_sl cue onto rechat so an engaged NPC talking via
// the multi-NPC chat path gets the scene cue, not a generic dialogue cue. Runs last = reflects all overrides.
if (!empty($GLOBALS["AIAGENTNSFW_RECHAT_SEX"]) && isset($GLOBALS["PROMPTS"]["rechat"]["cue"]) && !empty($GLOBALS["PROMPTS"]["chatnf_sl"]["cue"][0])) {
    $GLOBALS["PROMPTS"]["rechat"]["cue"] = [$GLOBALS["PROMPTS"]["chatnf_sl"]["cue"][0]];
}

// ============================================
// REFUSAL CUE OVERRIDE (authoritative - runs after all scene cues are built)
// ============================================
// When the NPC is refusing (rel-type gate / orientation / two-tier consent refusal) her refusal must drive
// EVERY player scene beat. Otherwise a position-change, scene-update or climax beat falls back to the explicit
// sex commentary and re-sexualizes her line mid-refusal. Only fires when AIAGENTNSFW_REFUSAL_CUE is set in
// prerequest.php, so normal accepted scenes are untouched. NPC<->NPC events are excluded (player-only gate).
if (!empty($GLOBALS["AIAGENTNSFW_REFUSAL_CUE"])) {
    $_refusalDriveCue = $GLOBALS["AIAGENTNSFW_REFUSAL_CUE"] . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
    $_refusalPlayerRequest = (string)($GLOBALS["AIAGENTNSFW_REFUSAL_PLAYER_REQUEST"] ?? "");
    $GLOBALS["gameRequest"][3] = $_refusalPlayerRequest;
    foreach (["chatnf_sl", "chatnf_sl_climax", "chatnf_sl_moan", "chatnf_sl_naked",
              "chatnf_physics", "ext_nsfw_physics", "ext_nsfw_sexcene", "ext_nsfw_action", "ext_nsfw_scene", "ext_nsfw_orgasm"] as $_rfEvt) {
        if (isset($GLOBALS["PROMPTS"][$_rfEvt])) {
            $GLOBALS["PROMPTS"][$_rfEvt]["cue"] = [$_refusalDriveCue];
            $GLOBALS["PROMPTS"][$_rfEvt]["player_request"] = [$_refusalPlayerRequest];
        }
    }
    error_log("[AIAGENTNSFW] REFUSAL_CUE active - player scene-event cues and player_request driven only by refusal");
}

// (Intoxication cue-gate moved BELOW - placed after all scene cues are built so it covers EVERY event type,
//  not just sex. See "INTOXICATION GATE - EVERY PROMPT" further down.)

// NPC Affair prompt - injected when NPC is intimate with someone other than spouse
// Placeholders: #NPC_NAME#, #NPC_SPOUSE#/#SPOUSE#, #PRIMARY_PARTNER#/#NPC_PARTNER#
$_npcAffairDefault = "(#NPC_NAME# is married to #NPC_SPOUSE#, but #NPC_NAME# is being intimate with #PRIMARY_PARTNER# instead. This is an affair. React according to your personality - guilt, thrill, justification, or indifference.)";
$_npcAffairCue = _getNsfwPromptSetting('npc_affair', $_npcAffairDefault);
// Note: placeholders are replaced at runtime in prerequest.php / relationship routing
// when affair conditions are detected

// Store the affair prompt globally for prerequest.php to access
$GLOBALS["NSFW_AFFAIR_PROMPT"] = $_npcAffairCue;

// Scene start - anticipation
$_sceneStartDefault = "(Sex is starting. React with anticipation. You might feel: eager, nervous, excited, playful, seductive, or hesitant. Express ONE emotion. Keep it SHORT.)";
$_sceneStart = _getNsfwPromptSetting('scene_start', $_sceneStartDefault);

$GLOBALS["PROMPTS"]["ext_nsfw_start"] = [
    "cue" => [_wrapNsfwXml('scene_start_instruction', $_sceneStart) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => [""]
];

// Scene end - post-coital
$_sceneEndDefault = "(#NPC_NAME# just finished having sex. React naturally. You might feel: satisfied, affectionate, playful, tired, wanting more, or cuddly. Express how YOU feel. SHORT response.)";
$_sceneEnd = _getNsfwPromptSetting('scene_end', $_sceneEndDefault);
$_sceneEnd = str_replace('#NPC_NAME#', $GLOBALS["HERIKA_NAME"] ?? "", $_sceneEnd);

$GLOBALS["PROMPTS"]["ext_nsfw_end"] = [
    "cue" => [_wrapNsfwXml('post_scene_instruction', $_sceneEnd) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => [""]
];

// ============================================
// VR PHYSICS TOUCH DETECTION (CBPC)
// ============================================

// Legacy setting key is "physics_touch", but this cue is shared by touch, grab, and spank.
$_physicsEventDefault = "(A VR physical contact event involving #NPC_NAME# just happened. Use the active VR Physics touch/grab/spank prompt for relationship tone and body-part meaning. The current physical event must be acknowledged directly in the reply. Keep response SHORT - 1 sentence.)";
$_physicsEventInstruction = _getNsfwPromptSetting('physics_touch', $_physicsEventDefault);
$_physicsEventInstruction = str_replace('#NPC_NAME#', $GLOBALS["HERIKA_NAME"] ?? "", $_physicsEventInstruction);
$_physicsEventInstruction = str_replace('#PLAYER_NAME#', $GLOBALS["PLAYER_NAME"] ?? "the player", $_physicsEventInstruction);

$_physicsDirectReaction = "";
$_physicsEventType = $GLOBALS["gameRequest"][0] ?? '';
$_lastVrPhysicsPrompt = $GLOBALS["AIAGENTNSFW_LAST_VR_PHYSICS_PROMPT"] ?? null;
if (in_array($_physicsEventType, ['chatnf_physics', 'ext_nsfw_physics'], true) && is_array($_lastVrPhysicsPrompt)) {
    $_physicsAction = strtolower(trim((string)($_lastVrPhysicsPrompt['action'] ?? 'touch')));
    $_physicsBodyPart = trim((string)($_lastVrPhysicsPrompt['bodyPart'] ?? 'body'));
    $_physicsEventRaw = (string)($GLOBALS["gameRequest"][3] ?? '');
    if (function_exists('aiagentNsfwContactStripTags')) {
        $_physicsEventRaw = aiagentNsfwContactStripTags($_physicsEventRaw);
    }
    $_physicsEventText = trim(strip_tags($_physicsEventRaw));
    $_physicsEventText = preg_replace('/\s+/', ' ', $_physicsEventText);

    if ($_physicsEventText !== '') {
        if ($_physicsAction === 'spank') {
            $_physicsDirectReaction = "CURRENT VR PHYSICS EVENT: {$_physicsEventText} This is the active event this turn. #NPC_NAME# must unmistakably acknowledge the ass slap/spank in the dialogue. Do not answer only with generic flirtation, generic attraction, or unrelated scene talk.";
        } else {
            $_physicsDirectReaction = "CURRENT VR PHYSICS EVENT: {$_physicsEventText} This is the active event this turn. #NPC_NAME# must unmistakably acknowledge the {$_physicsAction} on {$_physicsBodyPart} in the dialogue. Do not answer only with generic flirtation, generic attraction, or unrelated scene talk.";
        }
        $_physicsDirectReaction = str_replace('#NPC_NAME#', $GLOBALS["HERIKA_NAME"] ?? "", $_physicsDirectReaction);
    }
}

// DEBUG: Log physics prompt building
if (($GLOBALS["gameRequest"][0] ?? '') == "ext_nsfw_physics") {
    error_log("[AIAGENTNSFW-DEBUG] prompts.php: Building ext_nsfw_physics prompt");
    error_log("[AIAGENTNSFW-DEBUG] HERIKA_NAME=" . ($GLOBALS["HERIKA_NAME"] ?? "NULL"));
    error_log("[AIAGENTNSFW-DEBUG] gameRequest[3]=" . substr($GLOBALS["gameRequest"][3] ?? "NULL", 0, 100));
    error_log("[AIAGENTNSFW-DEBUG] _physicsEventInstruction=" . substr($_physicsEventInstruction, 0, 100));
}

// Physics blocked touch - React to blocked touch attempt (chastity devices, etc)
$_physicsBlockedDefault = "(#NPC_NAME# felt someone try to touch them but was blocked. React to this - you might feel frustrated, relieved, embarrassed, or teasing. Keep response SHORT - 1 sentence.)";
$_physicsBlocked = _getNsfwPromptSetting('physics_blocked', $_physicsBlockedDefault);
$_physicsBlocked = str_replace('#NPC_NAME#', $GLOBALS["HERIKA_NAME"] ?? "", $_physicsBlocked);

// IMPORTANT: player_request uses $gameRequest[3] to PRESERVE the physics event message
// (e.g., "<SEXUAL_GROPE>PlayerName groped NpcName's Breasts</SEXUAL_GROPE>")
// set by processInfoPhysics() - do NOT overwrite with empty string!
$_physicsCue = _wrapNsfwXml('vr_physics_instruction', trim($_physicsEventInstruction . "\n" . $_physicsDirectReaction)) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
$_physicsPlayerReq = $GLOBALS["gameRequest"][3] ?? "";
if (function_exists('aiagentNsfwContactStripTags')) {
    $_physicsPlayerReq = aiagentNsfwContactStripTags($_physicsPlayerReq);
}
$_physicsTokenLimit = max(120, min(400, (int)_getNsfwSetting('TOKEN_LIMIT_PHYSICS', 240)));

$GLOBALS["PROMPTS"]["ext_nsfw_physics"] = [
    "cue" => [$_physicsCue],
    "player_request" => [$_physicsPlayerReq],
    "extra" => ["force_tokens_max" => $_physicsTokenLimit]
];

// chatnf_physics - Used when preprocessing rewrites ext_nsfw_physics_raw
// This triggers dialogue via main.php's chatnf handling (line ~2061 checks strpos "chatnf")
$GLOBALS["PROMPTS"]["chatnf_physics"] = [
    "cue" => [$_physicsCue],
    "player_request" => [$_physicsPlayerReq],
    "extra" => ["force_tokens_max" => $_physicsTokenLimit]
];

// DEBUG: Log the final prompt after building
if (($GLOBALS["gameRequest"][0] ?? '') == "ext_nsfw_physics") {
    error_log("[AIAGENTNSFW-DEBUG] prompts.php: Final ext_nsfw_physics prompt:");
    error_log("[AIAGENTNSFW-DEBUG] cue length=" . strlen($_physicsCue));
    error_log("[AIAGENTNSFW-DEBUG] cue first 200 chars=" . substr($_physicsCue, 0, 200));
    error_log("[AIAGENTNSFW-DEBUG] player_request=" . substr($_physicsPlayerReq, 0, 100));
}

// This prompt handles blocked touch attempts (chastity devices, armor)
// Also preserves $gameRequest[3] for the blocked touch message
$GLOBALS["PROMPTS"]["ext_nsfw_physics_blocked"] = [
    "cue" => [_wrapNsfwXml('physics_touch_instruction', $_physicsBlocked) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => [$GLOBALS["gameRequest"][3] ?? ""]
];

// Raw physics event - placeholder until preprocessed by processInfoPhysics()
$GLOBALS["PROMPTS"]["ext_nsfw_physics_raw"] = [
    "cue" => [($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => [""]
];

// ============================================
// INTOXICATION GATE - EVERY PROMPT (per-speaker, current event)
// ============================================
// TOTAL CONTROL: an intoxicated NPC's state must ride EVERY prompt that goes to her - normal speech, any scene,
// sex, physics, all of it - so the model can never drop it. The persona block (context_pre) already injects on
// every prompt; this ALSO appends the reminder onto THIS turn's actual CUE (which a small model weights heavily),
// whatever event it is. Placed here, after all scene cues are built, so no event type is missed. Per-speaker
// (HERIKA_NAME) so a sober NPC in a group gets nothing. Substance flavor lives in the character block; here we
// just HOLD it (structural) + the UI intoxicated_sex tone.
$_ixEvt = $GLOBALS["gameRequest"][0] ?? '';
$_ixSpeaker = $GLOBALS["HERIKA_NAME"] ?? "";
if ($_ixEvt !== '' && $_ixSpeaker !== '' && isset($GLOBALS["PROMPTS"][$_ixEvt]["cue"]) && is_array($GLOBALS["PROMPTS"][$_ixEvt]["cue"]) && function_exists('getDrunkStageForActor')) {
    $_ixDrunk  = getDrunkStageForActor($_ixSpeaker);
    $_drugsOn  = _getNsfwSetting('DRUGS_ENABLED', true);
    $_ixSkooma = ($_drugsOn && function_exists('getDrugStageForActor')) ? getDrugStageForActor($_ixSpeaker, 'skooma') : 0;
    $_ixSap    = ($_drugsOn && function_exists('getDrugStageForActor')) ? getDrugStageForActor($_ixSpeaker, 'sleeping_tree_sap') : 0;
    if ($_ixDrunk >= 1 || $_ixSkooma >= 1 || $_ixSap >= 1) {
        $_ixSexTxt = _getNsfwPromptSetting('intoxicated_sex', '');
        $_ixClause = " (You are intoxicated - keep speaking exactly the way your intoxicated state has you speak for this WHOLE reply; do NOT slip back into sober, normal speech."
                   . ($_ixSexTxt !== '' ? " {$_ixSexTxt}" : "") . ")";
        foreach ($GLOBALS["PROMPTS"][$_ixEvt]["cue"] as $_ci => $_cv) {
            if (is_string($_cv)) { $GLOBALS["PROMPTS"][$_ixEvt]["cue"][$_ci] = $_cv . $_ixClause; }
        }
        error_log("[AIAGENTNSFW] Intoxication gate on EVERY prompt for {$_ixSpeaker} ({$_ixEvt}; drunk {$_ixDrunk}/skooma {$_ixSkooma}/sap {$_ixSap})");
    }
}

// ============================================
// FERTILITY MODE RELOADED (FMR) PROMPTS
// ============================================
// These prompts are injected when fertility events occur.
// Placeholders:
//   #NPC_NAME#    - Current NPC being processed
//   #PLAYER_NAME# - Player's name
//   #MOTHER#      - Mother's name (NPC if she's pregnant, or from extended data)
//   #FATHER#      - Father's name (from extended data, or player if applicable)
//   #PROGRESS#    - Pregnancy progress (0-100)
//   #BABY_HEALTH# - Baby's health percentage
//   #CAUSE#       - Cause of miscarriage/death
//   #RECOVERY_DAY# - Days into postpartum recovery
// ============================================

// Helper function to get FMR prompt with placeholder replacement
function _getFmrPrompt($key, $default, $replacements = []) {
    $prompt = _getNsfwPromptSetting($key, $default);

    // Standard replacements
    $prompt = str_replace('#NPC_NAME#', $GLOBALS["HERIKA_NAME"] ?? "She", $prompt);
    $prompt = str_replace('#PLAYER_NAME#', $GLOBALS["PLAYER_NAME"] ?? "the player", $prompt);

    // Custom replacements (father name, progress, etc)
    foreach ($replacements as $placeholder => $value) {
        $prompt = str_replace($placeholder, $value, $prompt);
    }

    return $prompt;
}

// LEGACY (retired 2026-07-10): the fmr_* prompt set and getFertilityPromptForNpc below are no
// longer injected anywhere - the Fertility tab pipeline (context_pre.php) owns fertility prompts.
// Kept only so old saved fmr_* values and any third-party callers do not fatal.
// Pregnancy prompts by trimester
$_fmrPregnantT1Default = "You are in early pregnancy. You may experience nausea, mood swings, and fatigue. Be careful but intimacy is still possible.";
$_fmrPregnantT2Default = "You are visibly pregnant now. You can feel the baby moving. Some positions are uncomfortable. Be mindful of the belly.";
$_fmrPregnantT3Default = "You are very pregnant. Limited positions work, be very careful. You may be protective of the baby. Birth is approaching.";

// Recovery prompt
$_fmrRecoveryDefault = "You are recovering postpartum. Your body is healing. You are a new mother - emotions may be intense. Intimacy may be limited.";

// Menstrual cycle prompts
$_fmrMenstruationDefault = "You are menstruating. May affect your mood and libido. Some prefer to avoid intimacy during this time.";
$_fmrFollicularDefault = "Follicular phase - your energy is building. Feeling more positive and open to intimacy.";
$_fmrOvulationDefault = "You are ovulating - peak fertility! Heightened arousal, strong desire. You know pregnancy is possible right now.";
$_fmrLutealDefault = "Luteal phase - PMS territory. Mood swings, irritability, tender. May be less interested in intimacy.";

// Baby health prompts
$_fmrBabyHealthyDefault = "Your baby is healthy. You feel relieved and protective. Mention the baby's wellbeing with affection.";
$_fmrBabyDamageDefault = "Your baby's health is at risk! You are worried, protective, possibly panicked. Intimacy may be the last thing on your mind.";

// Tragedy prompts
$_fmrMiscarriageDefault = "You have just miscarried. You are in shock, grief-stricken, traumatized. You need time to process this loss.";
$_fmrBabyDeathDefault = "Your baby has died. Devastating loss. You are in deep grief, may be inconsolable. This changes everything.";
$_fmrMotherDeathDefault = "EMERGENCY: The mother is dying or has died. Panic, crisis, tragedy. All normal behavior suspended.";

// Store defaults in global for access by other modules
$GLOBALS["FMR_PROMPT_DEFAULTS"] = [
    'fmr_pregnant_t1' => $_fmrPregnantT1Default,
    'fmr_pregnant_t2' => $_fmrPregnantT2Default,
    'fmr_pregnant_t3' => $_fmrPregnantT3Default,
    'fmr_recovery' => $_fmrRecoveryDefault,
    'fmr_menstruation' => $_fmrMenstruationDefault,
    'fmr_follicular' => $_fmrFollicularDefault,
    'fmr_ovulation' => $_fmrOvulationDefault,
    'fmr_luteal' => $_fmrLutealDefault,
    'fmr_baby_healthy' => $_fmrBabyHealthyDefault,
    'fmr_baby_damage' => $_fmrBabyDamageDefault,
    'fmr_miscarriage' => $_fmrMiscarriageDefault,
    'fmr_baby_death' => $_fmrBabyDeathDefault,
    'fmr_mother_death' => $_fmrMotherDeathDefault
];

/**
 * Get fertility prompt based on NPC's current fertility state
 * Called from preprocessing or scene context building
 *
 * @param array $extended NPC's extended_data with fertility fields
 * @param string $npcName The NPC's name (usually the mother for pregnancy events)
 * @param array $replacements Additional placeholders to replace
 * @return string|null Fertility prompt or null if no relevant state
 */
function getFertilityPromptForNpc($extended, $npcName = null, $replacements = []) {
    if (!$extended || !is_array($extended)) {
        return null;
    }

    $defaults = $GLOBALS["FMR_PROMPT_DEFAULTS"];
    $motherName = $npcName ?: ($GLOBALS["HERIKA_NAME"] ?? "She");
    $playerName = $GLOBALS["PLAYER_NAME"] ?? "the player";

    // Set up mother/father placeholders
    // For pregnancy events, the NPC is the mother
    $replacements['#MOTHER#'] = $motherName;

    // Father comes from extended data - could be player or another NPC
    $fatherName = $extended["fertility_father"] ?? '';
    $replacements['#FATHER#'] = $fatherName ?: 'unknown';

    // Check for tragedy states first (highest priority)
    if (!empty($extended["fertility_miscarriage"])) {
        $cause = $extended["fertility_miscarriage_cause"] ?? '';
        $replacements['#CAUSE#'] = $cause;
        return _getFmrPrompt('fmr_miscarriage', $defaults['fmr_miscarriage'], $replacements);
    }

    if (!empty($extended["fertility_baby_lost"])) {
        $cause = $extended["fertility_baby_death_cause"] ?? '';
        $replacements['#CAUSE#'] = $cause;
        return _getFmrPrompt('fmr_baby_death', $defaults['fmr_baby_death'], $replacements);
    }

    if (!empty($extended["fertility_mother_died"])) {
        return _getFmrPrompt('fmr_mother_death', $defaults['fmr_mother_death'], $replacements);
    }

    // Check pregnancy state
    if (!empty($extended["fertility_is_pregnant"])) {
        $progress = intval($extended["fertility_progress"] ?? 0);
        $replacements['#PROGRESS#'] = $progress;

        // Baby health concerns during pregnancy
        if (!empty($extended["fertility_baby_damaged"]) && ($extended["fertility_baby_health"] ?? 100) < 50) {
            $replacements['#BABY_HEALTH#'] = $extended["fertility_baby_health"];
            return _getFmrPrompt('fmr_baby_damage', $defaults['fmr_baby_damage'], $replacements);
        }

        // Trimester-based prompts
        if ($progress <= 33) {
            return _getFmrPrompt('fmr_pregnant_t1', $defaults['fmr_pregnant_t1'], $replacements);
        } elseif ($progress <= 66) {
            return _getFmrPrompt('fmr_pregnant_t2', $defaults['fmr_pregnant_t2'], $replacements);
        } else {
            return _getFmrPrompt('fmr_pregnant_t3', $defaults['fmr_pregnant_t3'], $replacements);
        }
    }

    // Check recovery state (post-birth)
    if (!empty($extended["fertility_recovery_day"])) {
        $day = intval($extended["fertility_recovery_day"]);
        $replacements['#RECOVERY_DAY#'] = $day;
        if ($day <= 15) {  // Recovery lasts ~15 days
            return _getFmrPrompt('fmr_recovery', $defaults['fmr_recovery'], $replacements);
        }
    }

    // Check menstrual cycle phases (ranks 116-119)
    // This would need cycle tracking from FMR - placeholder for future

    return null;
}

?>
