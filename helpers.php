<?php
/**
 * NSFW Helper Functions
 * These are utility functions that need to be available early (before function definitions load).
 * Separated from functions.php to avoid early loading issues with FUNCTIONS array.
 */

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
    $cached = false;  // Match the UI/default config: arousal gating is opt-in.
    return $cached;
}

// REMOVED (user directive 2026-06-29): the NPC-to-NPC affinity gate is gone. It no longer gated refusal / accept /
// end (those were removed); its only remaining effect was deciding whether relationship tier-prompts fired for
// NPC-to-NPC scenes - which was vestigial. This stub now permanently returns false, so every existing call site
// keeps the former default (NPC-to-NPC scenes always use the direct speech-style route). The UI checkbox and config
// wiring for NPC_AFFINITY_GATING_ENABLED were removed. Do NOT re-add the toggle.
function isNpcAffinityGatingEnabled() {
    return false;
}

// GLOBAL PLAYER SCENE-CALL COOLDOWN (user directive 2026-06-29): one shared gate, across ALL NPCs, on how often an
// NPC may CALL / initiate a NEW sex scene toward the player - so several NPCs can't bombard the player with
// scene-calls back to back. Driven off the existing player-scene activity markers (no extra state): a scene that is
// currently active, or that ended within NSFW_PLAYER_SCENE_CALL_COOLDOWN_SECONDS, holds back new calls. Returns true
// while the gate is active. Setting 0 = off. Affection + Accept/Refuse are never gated by this.
function aiagentNsfwPlayerSceneCallCooldownActive() {
    $cooldown = (int)_getNsfwSetting('NSFW_PLAYER_SCENE_CALL_COOLDOWN_SECONDS', 30);
    if ($cooldown <= 0) { return false; }
    $now = time();
    $activeFile = sys_get_temp_dir() . "/nsfw_player_scene_active.txt";
    $endedFile  = sys_get_temp_dir() . "/nsfw_player_scene_ended.txt";
    $activeTs = is_file($activeFile) ? (int)(@file_get_contents($activeFile) ?: 0) : 0;
    $endedTs  = is_file($endedFile)  ? (int)(@file_get_contents($endedFile)  ?: 0) : 0;
    // Scene currently active (not superseded by a later end) -> block competing new calls.
    if ($activeTs > 0 && $activeTs >= $endedTs) { return true; }
    // Scene ended within the cooldown window -> still gated.
    if ($endedTs > 0 && ($now - $endedTs) < $cooldown) { return true; }
    return false;
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

// Helper function to check if non-consent prompt injection is enabled
// (Disabled to prevent canned refusals from frontier models like Claude/GPT)
function isNonConsentPromptEnabled() {
    static $cached = null;
    if ($cached !== null) return $cached;

    try {
        $promptsRow = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_prompts'");
        if ($promptsRow && !empty($promptsRow['value'])) {
            $prompts = json_decode($promptsRow['value'], true);
            if (is_array($prompts) && isset($prompts['enable_non_consent_prompt'])) {
                $cached = (bool)$prompts['enable_non_consent_prompt'];
                return $cached;
            }
        }
    } catch (Exception $e) {
        error_log("[AIAGENTNSFW] Error checking enable_non_consent_prompt: " . $e->getMessage());
    }
    $cached = true;  // Default to enabled
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
