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
