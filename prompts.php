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

// _getNsfwSetting() is now defined in common.php (always available without loading prompts.php)

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

// Climax/Orgasm moment - falls back to NPC's speak style climax_prompt
$_climaxCue = _getNsfwPromptSetting('climax', '');
// Replace #NPC_NAME# placeholder with actual NPC name
$_climaxCue = str_replace('#NPC_NAME#', $GLOBALS["HERIKA_NAME"] ?? "", $_climaxCue);

// If no global climax cue set, use NPC's speak style climax_prompt
// First check if nsfw_ostim_handler already stored it (bypasses Narrator path)
if (empty(trim($_climaxCue)) && !empty($GLOBALS["AIAGENTNSFW_CLIMAX_PROMPT"])) {
    $_climaxCue = $GLOBALS["AIAGENTNSFW_CLIMAX_PROMPT"];
    $_climaxCue = str_replace('#PARTNER#', $GLOBALS["PLAYER_NAME"] ?? 'your partner', $_climaxCue);
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
            $_climaxCue = str_replace('#PARTNER#', $GLOBALS["PLAYER_NAME"] ?? 'your partner', $_climaxCue);
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
$_npcInviteDefault = "(You are approaching #PARTNER_NAME# with romantic/sexual intent. React based on your relationship with them. You may accept, hesitate, or show enthusiasm depending on your feelings toward them.)";
$_npcInviteCue = _getNsfwPromptSetting('npc_invite', $_npcInviteDefault);

$GLOBALS["PROMPTS"]["ext_nsfw_npc_invite"] = [
    "cue" => [_wrapNsfwXml('npc_invite_instruction', $_npcInviteCue) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => [$GLOBALS["gameRequest"][3] ?? ""]
];

// chatnf_npc_sl - NPC-to-NPC scene speech (no player involved)
// Used when two NPCs are in a scene together without the player
$_npcSceneDefaults = [
    "(Focus on your partner #PARTNER_NAME#. Describe the sensation. Moans and gasps, explicit words. SHORT speech.)",
    "(React to #PARTNER_NAME#. Express pleasure or desire. Moans, explicit. 1-2 sentences.)",
    "(Tell #PARTNER_NAME# how they make you feel. Moans and gasps. SHORT.)",
    "(Moan, gasp, respond to #PARTNER_NAME#. Explicit words. Brief.)"
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
// During tier_prompt phase, use the relationship-based tier cue instead of explicit sex commentary
if (!empty($GLOBALS["AIAGENTNSFW_TIER_CUE_OVERRIDE"])) {
    $_sexsceneCue = $GLOBALS["AIAGENTNSFW_TIER_CUE_OVERRIDE"] . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
} else {
    $_sexsceneCue = _wrapNsfwXml('response_instruction', $_sceneCommentary) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
}
$GLOBALS["PROMPTS"]["ext_nsfw_sexcene"] = [
    "cue" => [$_sexsceneCue],
    "player_request" => [$GLOBALS["gameRequest"][3] ?? ""]
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
    $_orgasmCue = str_replace('#PARTNER#', $GLOBALS["PLAYER_NAME"] ?? 'your partner', $_orgasmCue);
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
            $_orgasmCue = str_replace('#PARTNER#', $GLOBALS["PLAYER_NAME"] ?? 'your partner', $_orgasmCue);
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

// ext_nsfw_npc_scene - NPC-to-NPC scene update (no player)
// Uses the npc_scene_active prompt from config
$_npcSceneActiveDefault = "(You are currently in an intimate/sexual scene with #PARTNER#. React naturally to the ongoing action. Express pleasure, desire, or other appropriate emotions. Keep responses SHORT.)";
$_npcSceneActiveCue = _getNsfwPromptSetting('npc_scene_active', $_npcSceneActiveDefault);

$GLOBALS["PROMPTS"]["ext_nsfw_npc_scene"] = [
    "cue" => [_wrapNsfwXml('npc_scene_instruction', $_npcSceneActiveCue) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => [$GLOBALS["gameRequest"][3] ?? ""]
];

// ext_nsfw_npc_orgasm - NPC orgasm in NPC-to-NPC scene
// Uses the npc_orgasm prompt from config (third-person perspective)
$_npcOrgasmDefault = "(#NPC_NAME# is reaching climax with #PARTNER#. Express the peak of pleasure - moans, gasps, cries. VERY SHORT - 3-5 words max.)";
$_npcOrgasmCue = _getNsfwPromptSetting('npc_orgasm', $_npcOrgasmDefault);
$_npcOrgasmCue = str_replace('#NPC_NAME#', $GLOBALS["HERIKA_NAME"] ?? "", $_npcOrgasmCue);

$GLOBALS["PROMPTS"]["ext_nsfw_npc_orgasm"] = [
    "cue" => [_wrapNsfwXml('npc_climax_instruction', $_npcOrgasmCue) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => [""],
    "extra" => ["force_tokens_max" => $_climaxTokenLimit]
];

// NPC Affair prompt - injected when NPC is intimate with someone other than spouse
// Placeholders: #NPC_NAME#, #SPOUSE#, #PARTNER#
$_npcAffairDefault = "(#NPC_NAME# is married to #SPOUSE#, but #NPC_NAME# is being intimate with #PARTNER# instead. This is an affair. React according to your personality - guilt, thrill, justification, or indifference.)";
$_npcAffairCue = _getNsfwPromptSetting('npc_affair', $_npcAffairDefault);
// Note: #NPC_NAME#, #SPOUSE#, and #PARTNER# are replaced at runtime in prerequest.php
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

// Physics touch - React to being touched in VR
$_physicsTouchDefault = "(#NPC_NAME# was just touched physically. React naturally to this touch. Consider your relationship with #PLAYER_NAME# - if you're fond of them, you might enjoy it; if not, you might be offended. Keep response SHORT - 1 sentence.)";
$_physicsTouch = _getNsfwPromptSetting('physics_touch', $_physicsTouchDefault);
$_physicsTouch = str_replace('#NPC_NAME#', $GLOBALS["HERIKA_NAME"] ?? "", $_physicsTouch);
$_physicsTouch = str_replace('#PLAYER_NAME#', $GLOBALS["PLAYER_NAME"] ?? "the player", $_physicsTouch);

// DEBUG: Log physics prompt building
if (($GLOBALS["gameRequest"][0] ?? '') == "ext_nsfw_physics") {
    error_log("[AIAGENTNSFW-DEBUG] prompts.php: Building ext_nsfw_physics prompt");
    error_log("[AIAGENTNSFW-DEBUG] HERIKA_NAME=" . ($GLOBALS["HERIKA_NAME"] ?? "NULL"));
    error_log("[AIAGENTNSFW-DEBUG] gameRequest[3]=" . substr($GLOBALS["gameRequest"][3] ?? "NULL", 0, 100));
    error_log("[AIAGENTNSFW-DEBUG] _physicsTouch=" . substr($_physicsTouch, 0, 100));
}

// Physics blocked touch - React to blocked touch attempt (chastity devices, etc)
$_physicsBlockedDefault = "(#NPC_NAME# felt someone try to touch them but was blocked. React to this - you might feel frustrated, relieved, embarrassed, or teasing. Keep response SHORT - 1 sentence.)";
$_physicsBlocked = _getNsfwPromptSetting('physics_blocked', $_physicsBlockedDefault);
$_physicsBlocked = str_replace('#NPC_NAME#', $GLOBALS["HERIKA_NAME"] ?? "", $_physicsBlocked);

// IMPORTANT: player_request uses $gameRequest[3] to PRESERVE the physics event message
// (e.g., "<SEXUAL_GROPE>Bannon groped Eris's Breasts</SEXUAL_GROPE>")
// set by processInfoPhysics() - do NOT overwrite with empty string!
$_physicsCue = _wrapNsfwXml('physics_touch_instruction', $_physicsTouch) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
$_physicsPlayerReq = $GLOBALS["gameRequest"][3] ?? "";

$GLOBALS["PROMPTS"]["ext_nsfw_physics"] = [
    "cue" => [$_physicsCue],
    "player_request" => [$_physicsPlayerReq]
];

// chatnf_physics - Used when preprocessing rewrites ext_nsfw_physics_raw
// This triggers dialogue via main.php's chatnf handling (line ~2061 checks strpos "chatnf")
$GLOBALS["PROMPTS"]["chatnf_physics"] = [
    "cue" => [$_physicsCue],
    "player_request" => [$_physicsPlayerReq]
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
// VR ITEM AWARENESS (HIGGS)
// ============================================
// *** FUTURE: MOVE TO CORE AIAGENT ***
// These prompts handle VR item pickup/drop detection.
// This is general VR immersion, not NSFW-specific.
// Should be moved to core CHIM/AIAgent when ready.
// ============================================

// Item pickup - NPC notices player picking up an item
$_vrItemPickupDefault = "(#PLAYER_NAME# just picked up an item. You notice this. React naturally if appropriate - you might comment on the item, ask about it, or simply acknowledge it. Keep response SHORT. Only respond if it's relevant to the conversation or noteworthy.)";
$_vrItemPickup = _getNsfwPromptSetting('vr_item_pickup', $_vrItemPickupDefault);
$_vrItemPickup = str_replace('#PLAYER_NAME#', $GLOBALS["PLAYER_NAME"] ?? "the player", $_vrItemPickup);

$GLOBALS["PROMPTS"]["ext_vr_item_pickup"] = [
    "cue" => [_wrapNsfwXml('vr_item_event', $_vrItemPickup) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => [$GLOBALS["gameRequest"][3] ?? ""]  // Contains formatted item pickup message
];

// Item drop - NPC notices player putting down an item
$_vrItemDropDefault = "(#PLAYER_NAME# just put down an item. You notice this. React naturally if appropriate - only if it's relevant or noteworthy. Keep response SHORT.)";
$_vrItemDrop = _getNsfwPromptSetting('vr_item_drop', $_vrItemDropDefault);
$_vrItemDrop = str_replace('#PLAYER_NAME#', $GLOBALS["PLAYER_NAME"] ?? "the player", $_vrItemDrop);

$GLOBALS["PROMPTS"]["ext_vr_item_drop"] = [
    "cue" => [_wrapNsfwXml('vr_item_event', $_vrItemDrop) . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")],
    "player_request" => [$GLOBALS["gameRequest"][3] ?? ""]  // Contains formatted item drop message
];

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
