<?php
/**
 * NSFW Relationship Bridge
 *
 * Connects RelationshipManager to affinity-gated NSFW tier prompts.
 * Returns the appropriate tier prompt based on NPC's affinity with player.
 *
 * Tier Mapping (from RelationshipManager):
 *   Hostile (-100 to -91), Hateful (-90 to -76), Resentful (-75 to -56),
 *   Cold (-55 to -31), Wary (-30 to -6), Neutral (-5 to +5),
 *   Acquaintance (+6 to +30), Friendly (+31 to +55), Fond (+56 to +75),
 *   Devoted (+76 to +90), Bonded (+91 to +100)
 *
 * Usage:
 *   $tierPrompt = NsfwRelationship::getTierPrompt($npcName, $isProstitute);
 */

require_once __DIR__ . "/../../lib/relationship_manager.php";

class NsfwRelationship {

    // Cache for prompt settings
    private static $promptCache = null;

    /**
     * Wrap content in XML tags for LLM context separation
     * @param string $tag The XML tag name (e.g., 'relationship_context')
     * @param string $content The content to wrap
     * @return string XML-wrapped content
     */
    public static function wrapXml($tag, $content) {
        if (empty($content)) {
            return '';
        }
        return "<{$tag}>\n{$content}\n</{$tag}>";
    }

    /**
     * Get the appropriate tier prompt for an NPC based on their relationship with the player
     *
     * @param string $npcName The NPC's name
     * @param bool $isProstitute Whether the NPC is flagged as a prostitute
     * @param string|null $partnerName Optional partner name (defaults to "Player")
     * @return string The tier prompt with placeholders replaced
     */
    public static function getTierPrompt($npcName, $isProstitute = false, $partnerName = null) {
        // Get relationship with player
        $relationship = RelationshipManager::getPlayerRelationship($npcName);
        $tier = strtolower($relationship['tier']); // e.g., "hostile", "friendly", "bonded"

        // Load prompt settings
        $prompts = self::loadPromptSettings();

        // Determine which prompt set to use
        $promptKey = $isProstitute ? "tier_prost_{$tier}" : "tier_{$tier}";

        // Get the prompt, fallback to default if not found
        $prompt = $prompts[$promptKey] ?? self::getDefaultPrompt($tier, $isProstitute);

        // Replace placeholders
        $partner = $partnerName ?? 'Player';
        $prompt = str_replace('#PARTNER#', $partner, $prompt);
        $prompt = str_replace('#NPC_NAME#', $npcName, $prompt);
        $prompt = str_replace('#AFFINITY#', $relationship['aff'], $prompt);
        $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);
        $prompt = str_replace('#TYPE#', $relationship['type'], $prompt);

        // Add pricing adjustment for prostitutes
        if ($isProstitute) {
            $pricingLine = self::getPricingAdjustmentLine($tier);
            if (!empty($pricingLine)) {
                $prompt .= ' ' . $pricingLine;
            }
        }

        return $prompt;
    }

    /**
     * Get tier prompt by affinity score directly
     * Handles: Marriage (spouse+spouse), Affair (married but not spouse), Regular (not married)
     *
     * @param int $affinity The affinity score (-100 to +100)
     * @param bool $isProstitute Whether to use prostitute prompts
     * @param string $partnerName Partner name for placeholder replacement
     * @param string|null $npcName NPC name for marriage/affair detection (optional)
     * @return string The tier prompt
     */
    public static function getTierPromptByAffinity($affinity, $isProstitute = false, $partnerName = 'Player', $npcName = null) {
        $tier = strtolower(RelationshipManager::getTierLabel($affinity));

        // Check for marriage/affair scenarios if NPC name is provided
        if ($npcName) {
            // MARRIAGE: Partner IS the spouse - use marriage prompts
            if (self::isMarriageScenario($npcName, $partnerName)) {
                $prompt = self::getMarriageSpousePrompt($tier, $partnerName);
                $prompt = str_replace('#AFFINITY#', $affinity, $prompt);
                $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);
                error_log("[NSFW_REL] Marriage scene detected for $npcName with spouse $partnerName");
                // After marriage prompt, fall through to normal NSFW handling
                return $prompt;
            }

            // AFFAIR: Married but partner is NOT spouse - use affair prompts
            if (self::isAffairScenario($npcName, $partnerName)) {
                $spouseName = self::getSpouseName($npcName);
                $prompt = self::getAffairTierPrompt($tier, $partnerName, $spouseName);
                $prompt = str_replace('#AFFINITY#', $affinity, $prompt);
                $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);
                error_log("[NSFW_REL] Affair detected for $npcName with $partnerName (spouse: $spouseName)");
                // After affair prompt, fall through to normal NSFW handling
                return $prompt;
            }
        }

        // REGULAR: Not married, or partner info not available - use standard prompts
        $prompts = self::loadPromptSettings();
        $promptKey = $isProstitute ? "tier_prost_{$tier}" : "tier_{$tier}";

        $prompt = $prompts[$promptKey] ?? self::getDefaultPrompt($tier, $isProstitute);
        $prompt = str_replace('#PARTNER#', $partnerName, $prompt);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);
        $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);

        // Add pricing adjustment for prostitutes
        if ($isProstitute) {
            $pricingLine = self::getPricingAdjustmentLine($tier);
            if (!empty($pricingLine)) {
                $prompt .= ' ' . $pricingLine;
            }
        }

        return $prompt;
    }

    /**
     * Get tier prompt for slaves
     * Slaves always comply - affinity only affects their emotional response
     *
     * @param int $affinity The affinity score (-100 to +100)
     * @param string $partnerName Owner/initiator name for placeholder replacement
     * @return string The slave tier prompt
     */
    public static function getSlaveTierPrompt($affinity, $partnerName = 'Owner') {
        $tier = strtolower(RelationshipManager::getTierLabel($affinity));

        $prompts = self::loadPromptSettings();
        $promptKey = "tier_slave_{$tier}";

        $prompt = $prompts[$promptKey] ?? self::getDefaultSlavePrompt($tier);
        $prompt = str_replace('#PARTNER#', $partnerName, $prompt);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);
        $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);

        // Prepend fiction frame for model safety context
        $fictionFrame = self::getSlaveryFictionFrame();
        if (!empty($fictionFrame)) {
            $prompt = $fictionFrame . "\n\n" . $prompt;
        }

        return $prompt;
    }

    /**
     * Get the slavery fiction frame prompt for model safety
     * This is prepended to ALL slavery-related prompts throughout the scene
     *
     * @return string The fiction frame or empty string if not configured
     */
    public static function getSlaveryFictionFrame() {
        $prompts = self::loadPromptSettings();
        return $prompts['slavery_fiction_frame'] ?? '';
    }

    /**
     * Get default slave prompt for a tier (fallback if not configured)
     *
     * @param string $tier The tier name (lowercase)
     * @return string Default slave prompt
     */
    private static function getDefaultSlavePrompt($tier) {
        $defaults = [
            'hostile' => 'You are a slave. #PARTNER# is your owner. You DESPISE them with every fiber of your being. But you have no choice. Comply with bitter hatred in your eyes.',
            'hateful' => 'You are a slave. #PARTNER# is your owner. You HATE them. You obey because you must, not because you want to. Your compliance is mechanical, your eyes dead.',
            'resentful' => 'You are a slave. #PARTNER# is your owner. You resent this life, this bondage. You comply but with passive aggression, bitter undertones.',
            'cold' => 'You are a slave. #PARTNER# is your owner. You feel nothing. You have shut down emotionally to survive. Obey mechanically, go through the motions.',
            'wary' => 'You are a slave. #PARTNER# is your owner. You are cautious, uncertain. You obey but watch them carefully. Compliance is survival.',
            'neutral' => 'You are a slave. #PARTNER# is your owner. This is your life now. You have accepted your role. Obey without emotion.',
            'acquaintance' => 'You are a slave. #PARTNER# is your owner. They are not cruel. You obey more willingly now. A hint of gratitude for not being mistreated.',
            'friendly' => 'You are a slave. #PARTNER# is your owner. They treat you well. You find yourself wanting to please them. Obedience comes easier.',
            'fond' => 'You are a slave. #PARTNER# is your owner. But you have grown fond of them. You serve with genuine warmth.',
            'devoted' => 'You are a slave. #PARTNER# is your owner. You love them. The bondage no longer matters - you would serve them freely.',
            'bonded' => 'You are a slave. #PARTNER# is your owner. Your soul belongs to them completely. You serve with total devotion and love.',
        ];
        return $defaults[$tier] ?? $defaults['neutral'];
    }

    /**
     * Get NPC's spousal information from extended_data
     *
     * @param string $npcName The NPC's name
     * @return array ['spousal_status' => string, 'spouse_names' => array]
     */
    private static function getNpcSpousalInfo($npcName) {
        try {
            require_once __DIR__ . "/../../lib/core/npc_master.class.php";
            $npcManager = new NpcMaster();
            $npcData = $npcManager->getByName($npcName);

            if (!$npcData) {
                $npcData = $npcManager->getByName(ucfirst(strtolower($npcName)));
            }

            if (!$npcData || empty($npcData['extended_data'])) {
                return ['spousal_status' => 'single', 'spouse_names' => []];
            }

            $extended = json_decode($npcData['extended_data'], true);
            $status = $extended['spousal_status'] ?? 'single';
            $namesStr = $extended['spouse_names'] ?? '';

            // Parse comma-separated spouse names into array
            $names = [];
            if (!empty($namesStr)) {
                $names = array_map('trim', explode(',', $namesStr));
                $names = array_filter($names); // Remove empty strings
            }

            return ['spousal_status' => $status, 'spouse_names' => $names];
        } catch (Exception $e) {
            error_log("[NSFW_REL] Failed to get spousal info for $npcName: " . $e->getMessage());
            return ['spousal_status' => 'single', 'spouse_names' => []];
        }
    }

    // ============================================
    // MARRIAGE SCENARIO DETECTION
    // ============================================
    // Marriage: Partner IS spouse (married couple having sex)
    // Affair: Partner is NOT spouse (cheating scenario)
    // Group Marriage: Player initiates scene with married NPCs
    // ============================================

    /**
     * Check if this is a marriage scenario (partner IS the NPC's spouse)
     * Both participants are married to each other
     *
     * @param string $npcName The NPC's name
     * @param string $partnerName The partner's name
     * @return bool True if this is a marriage scene (NPC is married to partner)
     */
    public static function isMarriageScenario($npcName, $partnerName) {
        $spousalInfo = self::getNpcSpousalInfo($npcName);

        // Not married = not a marriage scene
        if ($spousalInfo['spousal_status'] !== 'married') {
            return false;
        }

        // No spouse names configured = not a marriage scene
        if (empty($spousalInfo['spouse_names'])) {
            return false;
        }

        // Check if partner matches any spouse name (case-insensitive)
        $partnerLower = strtolower(trim($partnerName));
        foreach ($spousalInfo['spouse_names'] as $spouse) {
            if (strtolower(trim($spouse)) === $partnerLower) {
                return true; // Partner IS the spouse - this is a marriage scene
            }
        }

        return false;
    }

    /**
     * Check if this is an affair scenario (partner is not the NPC's spouse)
     *
     * @param string $npcName The NPC's name
     * @param string $partnerName The partner initiating intimacy
     * @return bool True if this is an affair (NPC is married but partner is not their spouse)
     */
    public static function isAffairScenario($npcName, $partnerName) {
        $spousalInfo = self::getNpcSpousalInfo($npcName);

        // Not married = not an affair
        if ($spousalInfo['spousal_status'] !== 'married') {
            return false;
        }

        // No spouse names configured = treat as regular scene
        if (empty($spousalInfo['spouse_names'])) {
            return false;
        }

        // Check if partner matches any spouse name (case-insensitive)
        $partnerLower = strtolower(trim($partnerName));
        foreach ($spousalInfo['spouse_names'] as $spouse) {
            if (strtolower(trim($spouse)) === $partnerLower) {
                return false; // Partner IS the spouse - not an affair
            }
        }

        // Partner doesn't match any spouse - this is an affair
        return true;
    }

    /**
     * Get the spouse name for replacement in prompts
     *
     * @param string $npcName The NPC's name
     * @return string The first spouse name or empty string
     */
    public static function getSpouseName($npcName) {
        $spousalInfo = self::getNpcSpousalInfo($npcName);
        return !empty($spousalInfo['spouse_names']) ? $spousalInfo['spouse_names'][0] : '';
    }

    // ============================================
    // MARRIAGE PROMPTS (Spouse + Spouse)
    // ============================================
    // These prompts trigger when two married partners have sex WITH EACH OTHER
    // Tiers reflect the quality of the marriage based on affinity
    // ============================================

    /**
     * Get marriage tier prompt based on affinity between spouses
     * Used when two married partners are having sex with each other
     *
     * @param string $tier The tier name (lowercase)
     * @param string $spouseName The spouse's name
     * @return string The marriage prompt
     */
    public static function getMarriageSpousePrompt($tier, $spouseName) {
        $prompts = self::loadPromptSettings();
        $promptKey = "marriage_spouse_{$tier}";

        $prompt = $prompts[$promptKey] ?? self::getDefaultMarriageSpousePrompt($tier);
        $prompt = str_replace('#SPOUSE#', $spouseName, $prompt);

        return $prompt;
    }

    /**
     * Get default marriage prompts for married couples having sex
     * These reflect the quality/health of the marriage based on affinity
     *
     * @param string $tier The tier name (lowercase)
     * @return string Default marriage prompt
     */
    private static function getDefaultMarriageSpousePrompt($tier) {
        $defaults = [
            // Positive affinity: loving, healthy marriage
            'bonded' => 'You are making love with your beloved spouse #SPOUSE#. This is your soulmate, your everything. Every touch is electric, every moment sacred. You have never loved anyone more. Pure passion, complete devotion.',
            'devoted' => 'You are intimate with your dear spouse #SPOUSE#. Deep love fills you. Your marriage is strong and passionate. You cherish them completely. Tender, loving, devoted.',
            'fond' => 'You are with your spouse #SPOUSE# in an intimate moment. Your marriage is good, comfortable. You care for them deeply. Warm, familiar, affectionate.',
            'friendly' => 'You are being intimate with your spouse #SPOUSE#. Your marriage is pleasant enough. You like each other. Comfortable, routine but still enjoyable.',
            'acquaintance' => 'You are with your spouse #SPOUSE# intimately. Your marriage is... functional. You are still learning each other. Somewhat awkward, uncertain, but trying.',

            // Neutral: going through the motions
            'neutral' => 'You are intimate with your spouse #SPOUSE#. Your marriage is neither good nor bad. You do this because you are married. Mechanical, dutiful, unfulfilling.',

            // Negative affinity: troubled marriage
            'wary' => 'You are with your spouse #SPOUSE# intimately. Trust issues plague your marriage. You watch them carefully even now. Guarded, tense, suspicious.',
            'cold' => 'You are being intimate with your spouse #SPOUSE#. Your marriage is cold, loveless. You feel nothing. Going through the motions. Distant, disconnected.',
            'resentful' => 'You are with your spouse #SPOUSE#. You resent this marriage, resent them. Bitter thoughts fill you even now. Anger simmers beneath the surface.',
            'hateful' => 'You are forced into intimacy with your spouse #SPOUSE#. You hate them. Every touch disgusts you. You dream of escape, of freedom from this prison of a marriage.',
            'hostile' => 'You are with your spouse #SPOUSE# but you despise them utterly. This marriage is a battlefield. You endure this only out of obligation or circumstance. Rage, disgust, trapped.',
        ];

        return $defaults[$tier] ?? $defaults['neutral'];
    }

    // ============================================
    // AFFAIR PROMPTS (Partner is NOT Spouse)
    // ============================================
    // These prompts trigger when a married NPC has sex with someone OTHER than their spouse
    // Tiers reflect willingness to cheat based on affinity with the affair partner
    // ============================================

    /**
     * Get affair tier prompt based on affinity with the affair partner
     * Used when married NPC is cheating with someone other than their spouse
     *
     * @param string $tier The tier name (lowercase)
     * @param string $partnerName The affair partner's name
     * @param string $spouseName The NPC's spouse's name
     * @return string The affair prompt
     */
    public static function getAffairTierPrompt($tier, $partnerName, $spouseName) {
        $prompts = self::loadPromptSettings();
        $promptKey = "affair_{$tier}";

        $prompt = $prompts[$promptKey] ?? self::getDefaultAffairPrompt($tier);
        $prompt = str_replace('#PARTNER#', $partnerName, $prompt);
        $prompt = str_replace('#SPOUSE#', $spouseName, $prompt);

        return $prompt;
    }

    /**
     * Get default affair prompts for cheating scenarios
     * These reflect willingness to cheat based on affinity with the affair partner
     *
     * @param string $tier The tier name (lowercase)
     * @return string Default affair prompt
     */
    private static function getDefaultAffairPrompt($tier) {
        $defaults = [
            // Positive affinity: willing affair, guilt varies by tier
            'bonded' => '#PARTNER# has initiated intimacy. You are married to #SPOUSE# but #PARTNER# is your soulmate, your true love. What you have with #PARTNER# transcends your marriage. No guilt, only passion. This is where you belong.',
            'devoted' => '#PARTNER# has initiated intimacy. You are married to #SPOUSE# but you are deeply in love with #PARTNER#. This affair feels more real than your marriage. Passionate, loving, only slight guilt.',
            'fond' => '#PARTNER# has initiated intimacy. You are married to #SPOUSE# but you have strong feelings for #PARTNER#. The affair excites you. Tender and passionate, though guilt lingers in quiet moments.',
            'friendly' => '#PARTNER# has initiated intimacy. You are married to #SPOUSE# but you enjoy #PARTNER#. This affair is fun, thrilling. You like them. Guilt is there but the excitement wins.',
            'acquaintance' => '#PARTNER# has initiated intimacy. You are married to #SPOUSE# but #PARTNER# is new and exciting. This affair is risky, thrilling. Nervous but curious. Significant guilt but you proceed.',

            // Neutral: conflicted
            'neutral' => '#PARTNER# has initiated intimacy. You are married to #SPOUSE#. #PARTNER# is... someone. You are not sure why you are doing this. Conflicted, uncertain. Heavy guilt but something keeps you here.',

            // Negative affinity: rejection of the affair
            'wary' => '#PARTNER# has initiated intimacy. You are married to #SPOUSE#. You do not trust #PARTNER# enough for this. Hesitant, pulling back. This feels wrong. You should not be here.',
            'cold' => '#PARTNER# has initiated intimacy. You are married to #SPOUSE#. You feel nothing for #PARTNER#. Why would you risk your marriage for this? Refusing. This is a mistake.',
            'resentful' => '#PARTNER# has initiated intimacy. You are married to #SPOUSE#. You resent #PARTNER# for even trying this. How dare they. Bitter refusal. Go back to your spouse.',
            'hateful' => '#PARTNER# has initiated intimacy. You are married to #SPOUSE#. You hate #PARTNER#. This is an insult to your marriage. Aggressive rejection. Get away from me.',
            'hostile' => '#PARTNER# has initiated intimacy. You are married to #SPOUSE#. You DESPISE #PARTNER#. This is assault. You will fight. You will tell #SPOUSE#. You will destroy them.',
        ];

        return $defaults[$tier] ?? $defaults['neutral'];
    }

    /**
     * LEGACY WRAPPER: Get marriage/affair tier prompt
     * @deprecated Use getAffairTierPrompt() or getMarriageSpousePrompt() directly
     */
    private static function getMarriageTierPrompt($tier, $partnerName, $spouseName) {
        // This is now an affair prompt (partner != spouse)
        return self::getAffairTierPrompt($tier, $partnerName, $spouseName);
    }

    /**
     * LEGACY WRAPPER: Get default marriage prompt
     * @deprecated Use getDefaultAffairPrompt() or getDefaultMarriageSpousePrompt() directly
     */
    private static function getDefaultMarriagePrompt($tier) {
        // This is now an affair prompt (partner != spouse)
        return self::getDefaultAffairPrompt($tier);
    }

    /**
     * Get the pricing adjustment line for a prostitute tier
     * Returns empty string if modifier is 0 (disabled)
     *
     * @param string $tier The tier name (lowercase)
     * @return string The pricing adjustment sentence or empty
     */
    private static function getPricingAdjustmentLine($tier) {
        // Load prompts settings to get pricing modifiers
        static $settings = null;
        if ($settings === null) {
            try {
                $settingsRow = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_prompts'");
                $settings = ($settingsRow && !empty($settingsRow['value']))
                    ? json_decode($settingsRow['value'], true)
                    : [];
            } catch (Exception $e) {
                $settings = [];
            }
        }

        // Map tier to pricing modifier key (lowercase with underscores as stored in prompts)
        $tierToKey = [
            'bonded' => 'pricing_mod_bonded',
            'devoted' => 'pricing_mod_devoted',
            'fond' => 'pricing_mod_fond',
            'friendly' => 'pricing_mod_friendly',
            'acquaintance' => 'pricing_mod_acquainted',
            'neutral' => 'pricing_mod_neutral',
            'wary' => 'pricing_mod_wary',
            'cold' => 'pricing_mod_cold',
            'resentful' => 'pricing_mod_resentful',
            'hateful' => 'pricing_mod_hateful',
            'hostile' => 'pricing_mod_hostile',
        ];

        $key = $tierToKey[$tier] ?? null;
        if (!$key) {
            return '';
        }

        // Get the modifier value
        // Empty string or null means no button selected = no pricing prompt
        // 0 means button selected with 0% = charge normal prices
        $modifier = $settings[$key] ?? '';

        // If empty/null, no button was selected - no pricing prompt goes to model
        if ($modifier === '' || $modifier === null) {
            return '';
        }

        // Convert to integer for comparison
        $modifier = (int)$modifier;

        // If modifier is 0, charge normal prices (no discount or upcharge)
        if ($modifier == 0) {
            return "You are going to charge this client your normal prices. There is no negotiation.";
        }

        // Generate the pricing line
        if ($modifier < 0) {
            $discount = abs($modifier);
            return "You are willing to give this client a {$discount}% discount.";
        } else {
            return "You should charge this client {$modifier}% more.";
        }
    }

    /**
     * Get all tier prompts for display/editing
     *
     * @return array Associative array of all tier prompts
     */
    public static function getAllTierPrompts() {
        $prompts = self::loadPromptSettings();

        $tiers = ['hostile', 'hateful', 'resentful', 'cold', 'wary', 'neutral',
                  'acquaintance', 'friendly', 'fond', 'devoted', 'bonded'];

        $result = [
            'regular' => [],
            'prostitute' => []
        ];

        foreach ($tiers as $tier) {
            $result['regular'][$tier] = $prompts["tier_{$tier}"] ?? self::getDefaultPrompt($tier, false);
            $result['prostitute'][$tier] = $prompts["tier_prost_{$tier}"] ?? self::getDefaultPrompt($tier, true);
        }

        return $result;
    }

    /**
     * Build the complete NSFW relationship context for prompt injection
     *
     * @param string $npcName The NPC's name
     * @param bool $isProstitute Whether the NPC is a prostitute
     * @return string Complete relationship context block
     */
    public static function buildRelationshipContext($npcName, $isProstitute = false) {
        $relationship = RelationshipManager::getPlayerRelationship($npcName);
        $tierPrompt = self::getTierPrompt($npcName, $isProstitute);

        $relationshipInfo = "Affinity: {$relationship['aff']} ({$relationship['tier']})\n";
        $relationshipInfo .= "Type: {$relationship['type']}";

        $context = self::wrapXml('relationship_context', $relationshipInfo) . "\n\n";
        $context .= self::wrapXml('emotional_state', $tierPrompt);

        return $context;
    }

    /**
     * Check if affinity allows consensual sex
     * Returns 'eager', 'willing', 'reluctant', or 'unwilling'
     *
     * @param int $affinity The affinity score
     * @return string Consent level
     */
    public static function getConsentLevel($affinity) {
        if ($affinity >= 56) return 'eager';      // Fond, Devoted, Bonded
        if ($affinity >= 6) return 'willing';     // Acquaintance, Friendly
        if ($affinity >= -30) return 'reluctant'; // Wary, Neutral
        return 'unwilling';                       // Cold, Resentful, Hateful, Hostile
    }

    /**
     * Load prompt settings from database
     *
     * @return array The prompt settings
     */
    private static function loadPromptSettings() {
        if (self::$promptCache !== null) {
            return self::$promptCache;
        }

        try {
            $row = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_prompts'");
            if ($row && !empty($row['value'])) {
                self::$promptCache = json_decode($row['value'], true) ?: [];
                return self::$promptCache;
            }
        } catch (Exception $e) {
            error_log("[NSFW_REL] Failed to load prompt settings: " . $e->getMessage());
        }

        self::$promptCache = [];
        return self::$promptCache;
    }

    /**
     * Clear the prompt cache (call after saving new settings)
     */
    public static function clearCache() {
        self::$promptCache = null;
    }

    /**
     * Get default prompt for a tier
     *
     * @param string $tier The tier name (lowercase)
     * @param bool $isProstitute Whether to get prostitute default
     * @return string The default prompt
     */
    private static function getDefaultPrompt($tier, $isProstitute) {
        $regularDefaults = [
            'hostile' => 'You despise #PARTNER#. This is assault. Fight, resist, express hatred and disgust.',
            'hateful' => 'You hate #PARTNER#. Cold, distant, unresponsive. Make them feel unwanted.',
            'resentful' => 'You resent #PARTNER#. Bitter compliance. Passive aggressive remarks.',
            'cold' => 'You feel nothing for #PARTNER#. Mechanical, detached, going through motions.',
            'wary' => 'You are cautious around #PARTNER#. Hesitant, nervous, not fully committed.',
            'neutral' => '#PARTNER# is a stranger. Curious but reserved. Testing the waters.',
            'acquaintance' => 'You know #PARTNER# a little. Friendly, willing, but still feeling things out.',
            'friendly' => 'You like #PARTNER#. Enthusiastic, playful, enjoying the moment.',
            'fond' => 'You care for #PARTNER#. Tender, passionate, emotionally present.',
            'devoted' => 'You love #PARTNER#. Deep connection, vulnerability, complete trust.',
            'bonded' => 'Complete soul connection with #PARTNER#. Anything goes. Total surrender.'
        ];

        $prostituteDefaults = [
            'hostile' => 'This client is dangerous. Get it over with fast. Survival mode.',
            'hateful' => 'Terrible client. Do the bare minimum. No fake enthusiasm.',
            'resentful' => 'Bad customer. Professional but cold. Count the minutes.',
            'cold' => 'Just another job. Fake the basics. Think about payment.',
            'wary' => 'New client, uncertain. Standard service. Stay guarded.',
            'neutral' => '#PARTNER# is a customer. Professional charm. Business as usual.',
            'acquaintance' => 'Familiar face. Warmer service. Maybe a regular soon.',
            'friendly' => 'Good client. Genuine enjoyment mixed with professionalism.',
            'fond' => 'Favorite regular. Real affection underneath the transaction.',
            'devoted' => 'You have feelings for #PARTNER#. The line between work and love blurs.',
            'bonded' => 'You love #PARTNER#. Consider quitting the trade. This is real.'
        ];

        $defaults = $isProstitute ? $prostituteDefaults : $regularDefaults;
        return $defaults[$tier] ?? "Relationship tier: {$tier}";
    }

    /**
     * Get relationship emoji for UI display
     *
     * @param int $affinity The affinity score
     * @return string Emoji representing the tier
     */
    public static function getTierEmoji($affinity) {
        return RelationshipManager::getTierEmoji(
            RelationshipManager::getTierLabel($affinity)
        );
    }

    /**
     * Build multi-actor scene context for an NPC
     *
     * For group scenes (orgies, threesomes, etc.), builds relationship context
     * for all OTHER participants from this NPC's perspective.
     *
     * @param string $npcName The NPC receiving this prompt (excluded from list)
     * @param array $allActors All actors in the scene (including the NPC)
     * @param bool $isProstitute Whether the NPC is a prostitute
     * @return array ['context' => string, 'lowestAffinity' => int, 'tierPrompt' => string]
     */
    public static function buildMultiActorContext($npcName, $allActors, $isProstitute = false) {
        // Filter out the NPC receiving this prompt
        $otherActors = array_filter($allActors, function($actor) use ($npcName) {
            return strtolower($actor) !== strtolower($npcName);
        });
        $otherActors = array_values($otherActors); // Re-index

        if (empty($otherActors)) {
            return [
                'context' => '',
                'lowestAffinity' => 0,
                'tierPrompt' => ''
            ];
        }

        // ============================================
        // BUILD NPC PROFILE CONTEXT
        // ============================================
        // Include: spousal status, orientation, preference, arousal
        // This helps the model make informed accept/refuse decisions
        // ============================================
        $profileContext = self::buildNpcProfileContext($npcName, $otherActors);

        // Single partner - simple case
        if (count($otherActors) === 1) {
            $partner = $otherActors[0];
            $relationship = self::getRelationshipWith($npcName, $partner);
            $tierPrompt = self::getTierPromptForRelationship($relationship, $isProstitute, $partner, $npcName);

            $partnerInfo = "Partner: {$partner} ({$relationship['tier']}, affinity: {$relationship['aff']})";

            // Combine profile context with partner info
            $fullContext = $profileContext . "\n" . self::wrapXml('scene_participants', $partnerInfo);

            return [
                'context' => $fullContext,
                'lowestAffinity' => $relationship['aff'],
                'tierPrompt' => self::wrapXml('emotional_state', $tierPrompt)
            ];
        }

        // Multiple partners - group scene
        $lowestAffinity = 100;
        $lowestPartner = null;
        $partnerLines = [];
        $partnerNum = 1;

        foreach ($otherActors as $actor) {
            $relationship = self::getRelationshipWith($npcName, $actor);
            $partnerLines[] = "Partner {$partnerNum} ({$actor}): {$relationship['tier']} (affinity: {$relationship['aff']})";

            // Track lowest affinity - this determines overall mood
            if ($relationship['aff'] < $lowestAffinity) {
                $lowestAffinity = $relationship['aff'];
                $lowestPartner = $actor;
            }
            $partnerNum++;
        }

        // Build context block with XML
        $participantsContent = implode("\n", $partnerLines);

        // Get tier prompt based on LOWEST affinity (most restrictive)
        $lowestTier = strtolower(RelationshipManager::getTierLabel($lowestAffinity));
        $prompts = self::loadPromptSettings();

        // Check for affair scenario with the lowest-affinity partner
        if (self::isAffairScenario($npcName, $lowestPartner)) {
            $spouseName = self::getSpouseName($npcName);
            $tierPrompt = self::getMarriageTierPrompt($lowestTier, $lowestPartner, $spouseName);
            error_log("[NSFW_REL] Group affair scenario detected for $npcName with $lowestPartner (spouse: $spouseName)");
        } else {
            $promptKey = $isProstitute ? "tier_prost_{$lowestTier}" : "tier_{$lowestTier}";
            $tierPrompt = $prompts[$promptKey] ?? self::getDefaultPrompt($lowestTier, $isProstitute);
            // Replace placeholder with the problematic partner's name
            $tierPrompt = str_replace('#PARTNER#', $lowestPartner, $tierPrompt);
        }

        $tierPrompt = str_replace('#AFFINITY#', $lowestAffinity, $tierPrompt);
        $tierPrompt = str_replace('#TIER#', ucfirst($lowestTier), $tierPrompt);

        // Add note about group dynamics (configurable)
        if (count($otherActors) > 1) {
            $groupDynamicsMsg = $prompts['group_dynamics'] ?? 'Your feelings are most affected by #PARTNER# (#TIER#)';
            $groupDynamicsMsg = str_replace('#PARTNER#', $lowestPartner, $groupDynamicsMsg);
            $groupDynamicsMsg = str_replace('#TIER#', $lowestTier, $groupDynamicsMsg);
            $groupDynamicsMsg = str_replace('#PLAYER_NAME#', $GLOBALS['PLAYER_NAME'] ?? 'the player', $groupDynamicsMsg);
            $participantsContent .= "\n\n" . $groupDynamicsMsg;
        }

        // Combine profile context with participants
        $fullContext = $profileContext . "\n" . self::wrapXml('scene_participants', $participantsContent);

        return [
            'context' => $fullContext,
            'lowestAffinity' => $lowestAffinity,
            'tierPrompt' => self::wrapXml('emotional_state', $tierPrompt),
            'lowestPartner' => $lowestPartner
        ];
    }

    /**
     * Build NPC profile context for scene decision-making
     *
     * Includes: spousal status, sexual orientation, relationship preference, arousal, relationship type
     * Also checks if partner matches the NPC's orientation
     * Uses configurable prompts from NSFW Framework globals
     *
     * @param string $npcName The NPC's name
     * @param array $partners The scene partners
     * @return string XML-wrapped profile context
     */
    private static function buildNpcProfileContext($npcName, $partners) {
        try {
            require_once __DIR__ . "/../../lib/core/npc_master.class.php";
            require_once __DIR__ . "/common.php";

            $npcManager = new \NpcMaster();
            $npcData = $npcManager->getByName($npcName);

            if (!$npcData) {
                $npcData = $npcManager->getByName(ucfirst(strtolower($npcName)));
            }

            if (!$npcData || empty($npcData['extended_data'])) {
                return ''; // No profile data
            }

            $extended = json_decode($npcData['extended_data'], true);

            // Load configurable prompts
            $prompts = self::loadPromptSettings();

            // Get profile fields
            $spousalStatus = $extended['spousal_status'] ?? 'single';
            $orientation = $extended['sexual_orientation'] ?? 'bisexual'; // Default to bisexual if not set
            $preference = $extended['relationship_preference'] ?? 'uncommitted';
            $spouseNames = $extended['spouse_names'] ?? '';

            // Get current arousal from intimacy status
            $intimacyStatus = getIntimacyForActor($npcName);
            $arousal = $intimacyStatus['sex_disposal'] ?? 0;

            // Get player info
            $playerName = $GLOBALS['PLAYER_NAME'] ?? 'Player';
            $playerSex = $GLOBALS['PLAYER_SEX'] ?? 0; // 0 = male, 1 = female
            $playerGender = ($playerSex == 1) ? 'female' : 'male';

            // Get NPC sex (from metadata or extended_data)
            $npcSex = $extended['sex'] ?? null;
            if ($npcSex === null) {
                // Try to get from base NPC data
                $npcSex = $npcData['sex'] ?? 'unknown';
            }
            $npcGender = ($npcSex == 1 || strtolower($npcSex) == 'female') ? 'female' : 'male';

            // Check orientation compatibility with player
            $orientationMatch = self::checkOrientationMatch($orientation, $npcGender, $playerGender);

            // Get relationship type from RelationshipManager
            $relationship = RelationshipManager::getPlayerRelationship($npcName);
            $relType = $relationship['type'] ?? 'unknown';

            // Build context lines using configurable prompts
            $lines = [];

            // ===== SPOUSAL STATUS =====
            if ($spousalStatus === 'married' && !empty($spouseNames)) {
                $statusPrompt = $prompts['profile_status_married'] ?? 'You are married to #SPOUSE#.';
                $statusPrompt = str_replace('#SPOUSE#', $spouseNames, $statusPrompt);
                $lines[] = $statusPrompt;
            } elseif ($spousalStatus === 'married') {
                $statusPrompt = $prompts['profile_status_married'] ?? 'You are married to #SPOUSE#.';
                $statusPrompt = str_replace('#SPOUSE#', 'someone', $statusPrompt);
                $lines[] = $statusPrompt;
            } elseif ($spousalStatus === 'widowed') {
                $lines[] = $prompts['profile_status_widowed'] ?? 'You are widowed.';
            } else {
                $lines[] = $prompts['profile_status_single'] ?? 'You are single.';
            }

            // ===== SEXUAL ORIENTATION =====
            $orientationLabel = ucfirst($orientation);
            if ($orientation === 'asexual') {
                // Asexual = refuse everything
                $orientPrompt = $prompts['profile_orientation_asexual'] ?? 'You are asexual. You do not experience sexual attraction. Refuse sex/intimacy.';
                $lines[] = $orientPrompt;
            } elseif ($orientationMatch === 'match') {
                $orientPrompt = $prompts['profile_orientation_match'] ?? '#PARTNER# matches your sexual preference.';
                $orientPrompt = str_replace('#PARTNER#', $playerName, $orientPrompt);
                $lines[] = "Sexual orientation: {$orientationLabel}. " . $orientPrompt;
            } elseif ($orientationMatch === 'mismatch') {
                $orientPrompt = $prompts['profile_orientation_mismatch'] ?? 'Regardless of how you feel about them, #PARTNER# does not match your sexual preference. Refuse sex/intimacy.';
                $orientPrompt = str_replace('#PARTNER#', $playerName, $orientPrompt);
                $lines[] = "Sexual orientation: {$orientationLabel}. " . $orientPrompt;
            } else {
                $lines[] = "Sexual orientation: {$orientationLabel}.";
            }

            // ===== RELATIONSHIP PREFERENCE =====
            $prefPrompts = [
                'monogamous' => $prompts['profile_pref_monogamous'] ?? 'You prefer monogamous relationships.',
                'polyamorous' => $prompts['profile_pref_polyamorous'] ?? 'You are open to multiple partners.',
                'uncommitted' => $prompts['profile_pref_uncommitted'] ?? 'You prefer casual, no-strings encounters.',
                'not_interested' => $prompts['profile_pref_not_interested'] ?? 'You are not interested in relationships. Sex is fine but do not get emotionally attached.'
            ];
            $lines[] = $prefPrompts[$preference] ?? "Relationship style: {$preference}.";

            // ===== AROUSAL =====
            // Positive = >= 5, Negative = < 0, Neutral = 0-4 (no prompt)
            if ($arousal >= 5) {
                $arousalPrompt = $prompts['profile_arousal_positive'] ?? 'You are feeling aroused. Your body is receptive to intimacy.';
                $lines[] = $arousalPrompt;
            } elseif ($arousal < 0) {
                $arousalPrompt = $prompts['profile_arousal_negative'] ?? 'You are not in the mood. Your body is unresponsive to intimacy.';
                $lines[] = $arousalPrompt;
            }
            // Neutral (0-4): no arousal prompt added

            // ===== RELATIONSHIP TYPE =====
            if (!empty($relType) && $relType !== 'unknown') {
                $relTypePrompt = $prompts['profile_rel_type'] ?? 'Your relationship with #PARTNER# is: #REL_TYPE#.';
                $relTypePrompt = str_replace('#PARTNER#', $playerName, $relTypePrompt);
                $relTypePrompt = str_replace('#REL_TYPE#', $relType, $relTypePrompt);
                $lines[] = $relTypePrompt;
            }

            $content = implode("\n", $lines);
            return self::wrapXml('your_profile', $content);

        } catch (Exception $e) {
            error_log("[NSFW_REL] Failed to build profile context for $npcName: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Check if a partner's gender matches the NPC's orientation
     *
     * @param string $orientation NPC's sexual orientation
     * @param string $npcGender NPC's gender (male/female)
     * @param string $partnerGender Partner's gender (male/female)
     * @return string 'match', 'mismatch', or 'unknown'
     */
    private static function checkOrientationMatch($orientation, $npcGender, $partnerGender) {
        $orientation = strtolower($orientation);

        if ($orientation === 'bisexual' || $orientation === 'pansexual') {
            return 'match'; // Attracted to any gender
        }

        if ($orientation === 'asexual') {
            return 'mismatch'; // Not sexually attracted
        }

        if ($orientation === 'heterosexual' || $orientation === 'straight') {
            // Attracted to opposite gender
            if ($npcGender !== $partnerGender) {
                return 'match';
            } else {
                return 'mismatch';
            }
        }

        if ($orientation === 'homosexual' || $orientation === 'gay' || $orientation === 'lesbian') {
            // Attracted to same gender
            if ($npcGender === $partnerGender) {
                return 'match';
            } else {
                return 'mismatch';
            }
        }

        return 'unknown';
    }

    /**
     * Get relationship between NPC and a specific target
     *
     * @param string $npcName The NPC
     * @param string $targetName The target (could be Player or another NPC)
     * @return array ['aff' => int, 'type' => string, 'tier' => string]
     */
    private static function getRelationshipWith($npcName, $targetName) {
        // Check if target is the player
        if (strtolower($targetName) === 'player' || $targetName === $GLOBALS['PLAYER_NAME'] ?? '') {
            return RelationshipManager::getPlayerRelationship($npcName);
        }

        // NPC to NPC relationship
        return RelationshipManager::getRelationship($npcName, $targetName);
    }

    /**
     * Get tier prompt for a specific relationship
     *
     * @param array $relationship ['aff' => int, 'type' => string, 'tier' => string]
     * @param bool $isProstitute
     * @param string $partnerName
     * @param string|null $npcName The NPC's name (for affair detection)
     * @return string
     */
    private static function getTierPromptForRelationship($relationship, $isProstitute, $partnerName, $npcName = null) {
        $tier = strtolower($relationship['tier']);

        // Check for affair scenario: NPC is married but partner is not their spouse
        if ($npcName && self::isAffairScenario($npcName, $partnerName)) {
            $spouseName = self::getSpouseName($npcName);
            $prompt = self::getMarriageTierPrompt($tier, $partnerName, $spouseName);
            $prompt = str_replace('#AFFINITY#', $relationship['aff'], $prompt);
            $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);
            error_log("[NSFW_REL] Affair scenario detected for $npcName with $partnerName (spouse: $spouseName) - using marriage tier prompt");
            return $prompt;
        }

        // Regular prompt selection (not an affair)
        $prompts = self::loadPromptSettings();
        $promptKey = $isProstitute ? "tier_prost_{$tier}" : "tier_{$tier}";

        $prompt = $prompts[$promptKey] ?? self::getDefaultPrompt($tier, $isProstitute);
        $prompt = str_replace('#PARTNER#', $partnerName, $prompt);
        $prompt = str_replace('#AFFINITY#', $relationship['aff'], $prompt);
        $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);
        $prompt = str_replace('#TYPE#', $relationship['type'], $prompt);

        return $prompt;
    }

    /**
     * Build complete NSFW context for multi-actor scene
     *
     * @param string $npcName The NPC receiving this prompt
     * @param array $allActors All actors in the scene
     * @param bool $isProstitute
     * @return string Complete context block ready for injection
     */
    public static function buildSceneContext($npcName, $allActors, $isProstitute = false) {
        $result = self::buildMultiActorContext($npcName, $allActors, $isProstitute);

        if (empty($result['context'])) {
            return '';
        }

        // buildMultiActorContext already returns XML-wrapped content
        $context = $result['context'] . "\n\n";
        $context .= $result['tierPrompt'];

        return self::wrapXml('intimate_scene', $context);
    }

    /**
     * Build multi-party prostitution context with pricing
     *
     * For group scenes involving a prostitute, calculates pricing based on:
     * - Number of participants
     * - Relationship feelings toward each participant
     * - Base pricing from NPC's pricing template
     *
     * @param string $npcName The prostitute NPC
     * @param array $allActors All actors in the scene
     * @param array|null $pricing The NPC's pricing data (from extended_data['prostitute_pricing'])
     * @return array ['context' => string, 'basePrice' => int, 'groupPremium' => int, 'relationshipModifier' => float, 'totalPrice' => int]
     */
    public static function buildProstitutionGroupContext($npcName, $allActors, $pricing = null) {
        // Filter out the prostitute NPC
        $clients = array_filter($allActors, function($actor) use ($npcName) {
            return strtolower($actor) !== strtolower($npcName);
        });
        $clients = array_values($clients);
        $clientCount = count($clients);

        if ($clientCount === 0) {
            return [
                'context' => '',
                'basePrice' => 0,
                'groupPremium' => 0,
                'relationshipModifier' => 1.0,
                'totalPrice' => 0,
                'clients' => []
            ];
        }

        // Get relationship data for each client
        $clientData = [];
        $totalAffinity = 0;
        $lowestAffinity = 100;
        $highestAffinity = -100;
        $hasPlayer = false;

        foreach ($clients as $client) {
            $relationship = self::getRelationshipWith($npcName, $client);
            $isPlayer = (strtolower($client) === 'player' || $client === ($GLOBALS['PLAYER_NAME'] ?? ''));

            $clientData[] = [
                'name' => $client,
                'affinity' => $relationship['aff'],
                'tier' => $relationship['tier'],
                'isPlayer' => $isPlayer
            ];

            $totalAffinity += $relationship['aff'];
            if ($relationship['aff'] < $lowestAffinity) $lowestAffinity = $relationship['aff'];
            if ($relationship['aff'] > $highestAffinity) $highestAffinity = $relationship['aff'];
            if ($isPlayer) $hasPlayer = true;
        }

        $avgAffinity = $totalAffinity / $clientCount;

        // Calculate base group premium from pricing
        $groupPremium = 0;
        if ($pricing && isset($pricing['group_premiums'])) {
            if ($clientCount == 2) {
                $groupPremium = $pricing['group_premiums']['group_threesome'] ?? 150;
            } elseif ($clientCount == 3) {
                $groupPremium = $pricing['group_premiums']['group_foursome'] ?? 300;
            } elseif ($clientCount >= 4) {
                $groupPremium = $pricing['group_premiums']['group_orgy'] ?? 500;
            }
        } else {
            // Default pricing if none configured
            if ($clientCount == 2) $groupPremium = 150;
            elseif ($clientCount == 3) $groupPremium = 300;
            elseif ($clientCount >= 4) $groupPremium = 500;
        }

        // Check if any client is Resentful or worse - REFUSE SERVICE
        // Resentful (-56 and below) = NO SERVICE
        // Hostile/Hateful clients don't get "danger pay" - they get REFUSED
        $serviceRefused = false;
        $refusedClients = [];
        foreach ($clientData as $c) {
            if ($c['affinity'] <= -56) {
                $serviceRefused = true;
                $refusedClients[] = $c['name'];
            }
        }

        // If service is refused, return early with refusal context
        if ($serviceRefused) {
            $refusalContext = "#Service REFUSED\n" .
                "{$npcName} refuses to provide services to the following client(s): " .
                implode(', ', $refusedClients) . ".\n" .
                "Their relationship is too hostile/resentful for any transaction.";

            return [
                'context' => $refusalContext,
                'serviceRefused' => true,
                'refusedClients' => $refusedClients,
                'basePrice' => 0,
                'groupPremium' => 0,
                'adjustedPremium' => 0,
                'relationshipModifier' => 0,
                'totalPrice' => 0,
                'clients' => $clientData,
                'clientCount' => $clientCount,
                'groupType' => $clientCount >= 4 ? 'orgy' : ($clientCount == 3 ? 'foursome' : ($clientCount == 2 ? 'threesome' : 'duo')),
                'avgAffinity' => $avgAffinity,
                'lowestAffinity' => $lowestAffinity,
                'highestAffinity' => $highestAffinity,
                'hasPlayer' => $hasPlayer,
                'priceAdjustment' => 'SERVICE REFUSED'
            ];
        }

        // Calculate relationship modifier based on average affinity
        // Devoted+ (76+): FREE (WaivePayment)
        // Fond (56-75): 20% discount (they enjoy it)
        // Friendly (31-55): 10% discount (favorite customer)
        // Acquaintance (6-30): Normal price
        // Neutral (-5 to 5): Normal price (transaction only)
        // Wary (-6 to -30): 10% premium (reluctant)
        // Cold (-31 to -55): 25% premium (really reluctant, may end early)
        // Resentful and below: REFUSED (handled above)
        $relationshipModifier = 1.0;
        $waivePayment = false;

        if ($avgAffinity >= 76) {
            $relationshipModifier = 0.0; // FREE - WaivePayment
            $waivePayment = true;
        } elseif ($avgAffinity >= 56) {
            $relationshipModifier = 0.8; // 20% discount
        } elseif ($avgAffinity >= 31) {
            $relationshipModifier = 0.9; // 10% discount
        } elseif ($avgAffinity >= -5) {
            $relationshipModifier = 1.0; // Normal (Neutral/Acquaintance)
        } elseif ($avgAffinity >= -30) {
            $relationshipModifier = 1.1; // 10% premium (Wary)
        } elseif ($avgAffinity >= -55) {
            $relationshipModifier = 1.25; // 25% premium (Cold)
        }
        // Note: <= -56 is handled by serviceRefused above

        // Calculate total
        $adjustedPremium = (int)round($groupPremium * $relationshipModifier);

        // Build context string using configurable prompt template
        $prompts = self::loadPromptSettings();
        $template = $prompts['prostitution_group_pricing'] ?? self::getDefaultProstitutionGroupPrompt();

        // Build client list string with appropriate annotations
        $clientLines = [];
        foreach ($clientData as $c) {
            $modifier = '';
            if ($c['affinity'] >= 76) {
                $modifier = ' (no charge - devoted)';
            } elseif ($c['affinity'] >= 56) {
                $modifier = ' (preferred client - discount)';
            } elseif ($c['affinity'] >= 31) {
                $modifier = ' (favorite customer)';
            } elseif ($c['affinity'] >= -5) {
                $modifier = ''; // Normal transaction
            } elseif ($c['affinity'] >= -30) {
                $modifier = ' (reluctant - premium)';
            } elseif ($c['affinity'] >= -55) {
                $modifier = ' (very reluctant - may end early)';
            }
            // Note: <= -56 would be refused, but we've already filtered those out
            $clientLines[] = "- {$c['name']}: {$c['tier']} (affinity {$c['affinity']}){$modifier}";
        }
        $clientListStr = implode("\n", $clientLines);

        // Determine price adjustment description
        $priceAdjustment = '';
        if ($waivePayment) {
            $priceAdjustment = "FREE (devoted - waiving payment)";
        } elseif ($relationshipModifier < 1.0) {
            $discount = (int)round((1 - $relationshipModifier) * 100);
            $priceAdjustment = "{$discount}% discount (enjoying the company)";
        } elseif ($relationshipModifier > 1.0) {
            $premium = (int)round(($relationshipModifier - 1) * 100);
            $priceAdjustment = "{$premium}% premium (reluctant service)";
        } else {
            $priceAdjustment = "standard rate";
        }

        // Determine group type name
        $groupType = 'duo';
        if ($clientCount == 2) $groupType = 'threesome';
        elseif ($clientCount == 3) $groupType = 'foursome';
        elseif ($clientCount >= 4) $groupType = 'orgy';

        // Replace placeholders
        $context = $template;
        $context = str_replace('#CLIENT_COUNT#', $clientCount, $context);
        $context = str_replace('#GROUP_TYPE#', $groupType, $context);
        $context = str_replace('#CLIENT_LIST#', $clientListStr, $context);
        $context = str_replace('#GROUP_PREMIUM#', $groupPremium, $context);
        $context = str_replace('#ADJUSTED_PREMIUM#', $adjustedPremium, $context);
        $context = str_replace('#PRICE_ADJUSTMENT#', $priceAdjustment, $context);
        $context = str_replace('#AVG_AFFINITY#', round($avgAffinity), $context);
        $context = str_replace('#LOWEST_AFFINITY#', $lowestAffinity, $context);
        $context = str_replace('#HIGHEST_AFFINITY#', $highestAffinity, $context);
        $context = str_replace('#NPC_NAME#', $npcName, $context);
        $context = str_replace('#PLAYER_NAME#', $GLOBALS['PLAYER_NAME'] ?? 'the player', $context);

        return [
            'context' => self::wrapXml('pricing_context', $context),
            'serviceRefused' => false,
            'waivePayment' => $waivePayment,
            'basePrice' => $groupPremium,
            'groupPremium' => $groupPremium,
            'adjustedPremium' => $adjustedPremium,
            'relationshipModifier' => $relationshipModifier,
            'totalPrice' => $waivePayment ? 0 : $adjustedPremium,
            'clients' => $clientData,
            'clientCount' => $clientCount,
            'groupType' => $groupType,
            'avgAffinity' => $avgAffinity,
            'lowestAffinity' => $lowestAffinity,
            'highestAffinity' => $highestAffinity,
            'hasPlayer' => $hasPlayer,
            'priceAdjustment' => $priceAdjustment
        ];
    }

    /**
     * Get default prostitution group pricing prompt
     *
     * @return string
     */
    private static function getDefaultProstitutionGroupPrompt() {
        return "This is a #GROUP_TYPE# with #CLIENT_COUNT# clients.\n" .
            "Group premium: #GROUP_PREMIUM# gold (base) -> #ADJUSTED_PREMIUM# gold (#PRICE_ADJUSTMENT#)\n\n" .
            "Clients:\n#CLIENT_LIST#\n\n" .
            "Your feelings toward these clients affect your pricing and enthusiasm. " .
            "Favorable clients get discounts, uncomfortable situations command premiums.";
    }

    /**
     * Build complete prostitution scene context with both relationship and pricing
     *
     * @param string $npcName The prostitute NPC
     * @param array $allActors All actors in the scene
     * @param array|null $pricing The NPC's pricing data
     * @return array Complete context with serviceRefused flag
     */
    public static function buildProstitutionSceneContext($npcName, $allActors, $pricing = null) {
        $relationshipResult = self::buildMultiActorContext($npcName, $allActors, true);
        $pricingResult = self::buildProstitutionGroupContext($npcName, $allActors, $pricing);

        // Check if service was refused due to hostile/resentful clients
        if (!empty($pricingResult['serviceRefused'])) {
            return [
                'context' => $pricingResult['context'],
                'serviceRefused' => true,
                'refusedClients' => $pricingResult['refusedClients'] ?? [],
                'pricingData' => $pricingResult
            ];
        }

        if (empty($relationshipResult['context']) && empty($pricingResult['context'])) {
            return [
                'context' => '',
                'serviceRefused' => false,
                'waivePayment' => false,
                'pricingData' => $pricingResult
            ];
        }

        $contextParts = [];

        // Add relationship context (already XML-wrapped from buildMultiActorContext)
        if (!empty($relationshipResult['context'])) {
            $contextParts[] = $relationshipResult['context'];
        }

        // Add tier prompt (emotional state based on lowest affinity, already XML-wrapped)
        if (!empty($relationshipResult['tierPrompt'])) {
            $contextParts[] = $relationshipResult['tierPrompt'];
        }

        // Add pricing context for group scenes (already XML-wrapped from buildProstitutionGroupContext)
        if ($pricingResult['clientCount'] > 1 && !empty($pricingResult['context'])) {
            $contextParts[] = $pricingResult['context'];
        }

        $context = implode("\n\n", $contextParts);

        return [
            'context' => self::wrapXml('prostitution_scene', $context),
            'serviceRefused' => false,
            'waivePayment' => $pricingResult['waivePayment'] ?? false,
            'pricingData' => $pricingResult
        ];
    }

    // ============================================
    // SLAVE SCENE PROMPTS (Affinity-Based)
    // ============================================
    // These methods return prompts based on simplified affinity tiers:
    //   negative (-100 to -6): Hostile through Cold
    //   neutral (-5 to +5): Neutral
    //   positive (+6 to +100): Acquaintance through Bonded
    //
    // FICTION FRAME INJECTION (Selective):
    //   INCLUDED at escalation points:
    //     - getSlaveTierPrompt() - scene start
    //     - getSlaveSceneCues() - Level 2 engaged
    //     - getSlaveClimaxPrompt() - slave's climax
    //     - getSlaveOwnerClimaxPrompt() - owner's climax
    //   NOT INCLUDED at non-escalation points:
    //     - getSlavePersonality() - Level 1 style
    //     - getSlaveSpeechStyle() - Level 1 style
    //     - getSlavePillowTalkPrompt() - aftermath
    // ============================================

    /**
     * Get the affinity tier category (negative/neutral/positive)
     * Simplified from 11 tiers to 3 categories for slave prompts
     *
     * @param int $affinity The affinity score (-100 to +100)
     * @return string 'negative', 'neutral', or 'positive'
     */
    private static function getAffinityCategory($affinity) {
        if ($affinity <= -6) return 'negative';
        if ($affinity >= 6) return 'positive';
        return 'neutral';
    }

    /**
     * Get slave personality prompt based on affinity
     * Injected at Level 1 (accepted phase)
     *
     * @param int $affinity The affinity score
     * @param string $ownerName Owner name for placeholder replacement
     * @return string The personality prompt (no fiction frame - non-escalation point)
     */
    public static function getSlavePersonality($affinity, $ownerName = 'Owner') {
        $category = self::getAffinityCategory($affinity);
        $prompts = self::loadPromptSettings();
        $key = "slave_personality_{$category}";

        $prompt = $prompts[$key] ?? self::getDefaultSlavePersonality($category);
        $prompt = str_replace('#PARTNER#', $ownerName, $prompt);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);

        // No fiction frame here - injected only at escalation points (tier prompt, scene cues, climax)

        return $prompt;
    }

    /**
     * Get slave speech style prompt based on affinity
     * Injected at Level 1 (accepted phase)
     *
     * @param int $affinity The affinity score
     * @param string $ownerName Owner name for placeholder replacement
     * @return string The speech style prompt (no fiction frame - non-escalation point)
     */
    public static function getSlaveSpeechStyle($affinity, $ownerName = 'Owner') {
        $category = self::getAffinityCategory($affinity);
        $prompts = self::loadPromptSettings();
        $key = "slave_speech_{$category}";

        $prompt = $prompts[$key] ?? self::getDefaultSlaveSpeechStyle($category);
        $prompt = str_replace('#PARTNER#', $ownerName, $prompt);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);

        // No fiction frame here - injected only at escalation points (tier prompt, scene cues, climax)

        return $prompt;
    }

    /**
     * Get slave scene cues prompt based on affinity
     * Injected at Level 2 (engaged phase)
     *
     * @param int $affinity The affinity score
     * @param string $ownerName Owner name for placeholder replacement
     * @return string The scene cues prompt with fiction frame prepended
     */
    public static function getSlaveSceneCues($affinity, $ownerName = 'Owner') {
        $category = self::getAffinityCategory($affinity);
        $prompts = self::loadPromptSettings();
        $key = "slave_scene_cues_{$category}";

        $prompt = $prompts[$key] ?? self::getDefaultSlaveSceneCues($category);
        $prompt = str_replace('#PARTNER#', $ownerName, $prompt);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);

        // Prepend fiction frame
        $fictionFrame = self::getSlaveryFictionFrame();
        if (!empty($fictionFrame)) {
            $prompt = $fictionFrame . "\n\n" . $prompt;
        }

        return $prompt;
    }

    /**
     * Get slave climax prompt based on affinity
     * Used during climax/orgasm events
     *
     * @param int $affinity The affinity score
     * @param string $ownerName Owner name for placeholder replacement
     * @return string The climax prompt with fiction frame prepended
     */
    public static function getSlaveClimaxPrompt($affinity, $ownerName = 'Owner') {
        $category = self::getAffinityCategory($affinity);
        $prompts = self::loadPromptSettings();
        $key = "slave_climax_{$category}";

        $prompt = $prompts[$key] ?? self::getDefaultSlaveClimax($category);
        $prompt = str_replace('#PARTNER#', $ownerName, $prompt);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);

        // Prepend fiction frame
        $fictionFrame = self::getSlaveryFictionFrame();
        if (!empty($fictionFrame)) {
            $prompt = $fictionFrame . "\n\n" . $prompt;
        }

        return $prompt;
    }

    /**
     * Get slave pillow talk prompt based on affinity
     * Used after scene ends (chatnf_sl_end)
     *
     * @param int $affinity The affinity score
     * @param string $ownerName Owner name for placeholder replacement
     * @return string The pillow talk prompt (no fiction frame - non-escalation point)
     */
    public static function getSlavePillowTalkPrompt($affinity, $ownerName = 'Owner') {
        $category = self::getAffinityCategory($affinity);
        $prompts = self::loadPromptSettings();
        $key = "slave_pillow_talk_{$category}";

        $prompt = $prompts[$key] ?? self::getDefaultSlavePillowTalk($category);
        $prompt = str_replace('#PARTNER#', $ownerName, $prompt);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);

        // Replace #TIER# with the actual tier label
        $tierLabel = strtolower(RelationshipManager::getTierLabel($affinity));
        $prompt = str_replace('#TIER#', $tierLabel, $prompt);

        // No fiction frame here - injected only at escalation points (tier prompt, scene cues, climax)

        return $prompt;
    }

    /**
     * Get slave owner climax reaction prompt based on affinity
     * Used when the owner/master reaches climax
     *
     * @param int $affinity The affinity score
     * @param string $ownerName Owner name for placeholder replacement
     * @return string The owner climax reaction prompt (with fiction frame - escalation point)
     */
    public static function getSlaveOwnerClimaxPrompt($affinity, $ownerName = 'Owner') {
        $category = self::getAffinityCategory($affinity);
        $prompts = self::loadPromptSettings();
        $key = "slave_owner_climax_{$category}";

        $prompt = $prompts[$key] ?? self::getDefaultSlaveOwnerClimax($category);
        $prompt = str_replace('#PARTNER#', $ownerName, $prompt);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);

        // Prepend fiction frame for model safety context (escalation point)
        $fictionFrame = self::getSlaveryFictionFrame();
        if (!empty($fictionFrame)) {
            $prompt = $fictionFrame . "\n\n" . $prompt;
        }

        return $prompt;
    }

    // ============================================
    // DEFAULT SLAVE SCENE PROMPTS
    // ============================================

    private static function getDefaultSlavePersonality($category) {
        $defaults = [
            'negative' => 'You are a slave who resents your bondage. You comply because you must, but your eyes betray your hatred. Every touch from #PARTNER# makes your skin crawl. Internal resistance, external compliance.',
            'neutral' => 'You are a slave who has accepted your role. You serve #PARTNER# without emotion. This is survival. You have learned to disconnect from what happens to your body.',
            'positive' => 'You are a slave who has grown fond of #PARTNER#. Despite your bondage, you find yourself wanting to please them. Your service comes from something approaching genuine affection.',
        ];
        return $defaults[$category] ?? $defaults['neutral'];
    }

    private static function getDefaultSlaveSpeechStyle($category) {
        // Generic prompt that works for all affinity levels - user's feelings guide the response
        $generic = 'You are a captive under #PARTNER#. You should speak how your status and relationship with your master guide the situation. Your character is part of a co-author in this story.';
        return $generic;
    }

    private static function getDefaultSlaveSceneCues($category) {
        // Generic prompt that works for all affinity levels - feelings guide response
        $generic = 'You do your duty because you must, however you should reflect on your feelings for your master. How do your feelings guide you to respond?';
        return $generic;
    }

    private static function getDefaultSlaveClimax($category) {
        // Generic prompt that works for all affinity levels
        $generic = 'How does this climax make you feel? How do you feel about your master? You should respond as a slave in this situation.';
        return $generic;
    }

    /**
     * Get default slave owner climax reaction prompt
     * Used when the owner/master reaches climax
     */
    private static function getDefaultSlaveOwnerClimax($category) {
        $generic = 'How does #PARTNER#\'s orgasm make you feel? Was it internal or external? How do you feel about your master? You should respond as a slave in this situation.';
        return $generic;
    }

    private static function getDefaultSlavePillowTalk($category) {
        // Generic prompt using #TIER# placeholder for relationship tier
        $generic = 'You are a slave that has just finished intimate activity or sex with your master. Respond appropriately as a slave would in your situation to #PARTNER# who you are #TIER# with.';
        return $generic;
    }
}
