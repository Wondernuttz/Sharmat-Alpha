<?php
/**
 * NSFW Data Manager - JSONB Storage Layer
 * =========================================
 * Handles all JSONB-based storage for NSFW plugin:
 * - Scene descriptions (6000+)
 * - Tier prompts (22)
 * - Speak styles (35+)
 *
 * Uses conf_opts table with JSONB 'value' column.
 * This pattern is THE STANDARD AND PROJECT CRITICAL:
 * REMOVAL RESULTS IN CRITICAL SYSTEMS FAILURE
 * "extended_data is a JSONB field. you can use it in SQL queries."
 *
 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 * !! CRITICAL - DO NOT REMOVE JSONB SQL OPERATORS !!
 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 * !! The functions getItem(), getScene(), getSpeakStyle(), etc. use       !!
 * !! PostgreSQL JSONB operators like (value::jsonb)->'key' to extract    !!
 * !! single keys SERVER-SIDE without loading entire blobs into PHP.      !!
 * !!                                                                      !!
 * !! This is NOT redundant code. The aiagent_nsfw_scenes blob is 1.25MB. !!
 * !! Loading it into PHP every time would murder performance.            !!
 * !!                                                                      !!
 * !! DO NOT "simplify" these queries to just load the whole blob.        !!
 * !! DO NOT strip the ::jsonb cast - conf_opts.value is TEXT not JSONB.  !!
 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 */

class NsfwData {

    // conf_opts keys
    const KEY_SCENES = 'aiagent_nsfw_scenes';
    const KEY_TIER_PROMPTS = 'aiagent_nsfw_tier_prompts';
    const KEY_SPEAK_STYLES = 'aiagent_nsfw_speak_styles';
    const KEY_PROMPTS = 'aiagent_nsfw_prompts';
    const KEY_SETTINGS = 'aiagent_nsfw_settings';

    // Cache to avoid repeated DB hits
    private static $cache = [];

    // Track held locks for cleanup
    private static $heldLocks = [];

    // ==========================================
    // ADVISORY LOCK SYSTEM
    // ==========================================

    /**
     * Convert string key to integer for PostgreSQL advisory lock
     * Uses CRC32 which returns a 32-bit integer - perfect for pg_advisory_lock
     */
    private static function getLockId($key) {
        return crc32($key);
    }

    /**
     * Acquire exclusive advisory lock for a key
     * BLOCKING - waits until lock is available (user operations have priority)
     *
     * This is the same pattern as relationship_llm.php - user always wins,
     * AI/background operations must wait for user to finish.
     *
     * @param string $key The conf_opts key to lock
     * @return bool True if lock acquired
     */
    public static function acquireLock($key) {
        if (!isset($GLOBALS['db'])) {
            return false;
        }

        $lockId = self::getLockId($key);

        try {
            // Blocking lock - waits until available (user has priority)
            $GLOBALS['db']->execQuery("SELECT pg_advisory_lock($lockId)");
            self::$heldLocks[$key] = $lockId;
            return true;

        } catch (Exception $e) {
            error_log("[NSFW-Data] Failed to acquire lock for '$key': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Try to acquire lock without waiting (non-blocking)
     * Returns immediately - use for optional operations like seeding defaults
     *
     * @param string $key The conf_opts key to lock
     * @return bool True if lock acquired immediately, false if busy
     */
    public static function tryLock($key) {
        if (!isset($GLOBALS['db'])) {
            return false;
        }

        $lockId = self::getLockId($key);

        try {
            $result = $GLOBALS['db']->fetchOne(
                "SELECT pg_try_advisory_lock($lockId) as locked"
            );
            if ($result && $result['locked'] === true) {
                self::$heldLocks[$key] = $lockId;
                return true;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Release advisory lock for a key
     *
     * @param string $key The conf_opts key to unlock
     * @return bool True if lock released
     */
    public static function releaseLock($key) {
        if (!isset($GLOBALS['db'])) {
            return false;
        }

        $lockId = self::getLockId($key);

        try {
            $GLOBALS['db']->execQuery("SELECT pg_advisory_unlock($lockId)");
            unset(self::$heldLocks[$key]);
            return true;

        } catch (Exception $e) {
            error_log("[NSFW-Data] Failed to release lock for '$key': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Release all held locks (call on shutdown/error)
     */
    public static function releaseAllLocks() {
        foreach (self::$heldLocks as $key => $lockId) {
            self::releaseLock($key);
        }
    }

    // ==========================================
    // GENERIC JSONB HELPERS
    // ==========================================

    /**
     * Get entire JSONB blob from conf_opts
     */
    public static function getBlob($key) {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        if (!isset($GLOBALS['db'])) {
            return [];
        }

        try {
            $row = $GLOBALS['db']->fetchOne(
                "SELECT value FROM conf_opts WHERE id = '" . pg_escape_string($key) . "'"
            );

            if ($row && !empty($row['value'])) {
                $data = json_decode($row['value'], true);
                self::$cache[$key] = $data ?: [];
                return self::$cache[$key];
            }
        } catch (Exception $e) {
            error_log("[NSFW-Data] Failed to get blob '$key': " . $e->getMessage());
        }

        return [];
    }

    /**
     * Save entire JSONB blob to conf_opts
     */
    public static function saveBlob($key, $data) {
        if (!isset($GLOBALS['db'])) {
            return false;
        }

        try {
            $jsonValue = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $escapedKey = pg_escape_string($key);
            $escapedValue = pg_escape_string($jsonValue);

            // Upsert
            $existing = $GLOBALS['db']->fetchOne(
                "SELECT id FROM conf_opts WHERE id = '$escapedKey'"
            );

            if ($existing) {
                $GLOBALS['db']->execQuery(
                    "UPDATE conf_opts SET value = '$escapedValue' WHERE id = '$escapedKey'"
                );
            } else {
                $GLOBALS['db']->insert('conf_opts', [
                    'id' => $key,
                    'value' => $jsonValue
                ]);
            }

            // Update cache
            self::$cache[$key] = $data;
            return true;

        } catch (Exception $e) {
            error_log("[NSFW-Data] Failed to save blob '$key': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get single item from JSONB blob using PostgreSQL JSONB operator
     * This extracts just the requested key server-side instead of loading the entire blob
     *
     * !! DO NOT SIMPLIFY THIS TO LOAD THE WHOLE BLOB !!
     * !! The scenes blob is 1.25MB - this query extracts ONE key server-side !!
     * !! The ::jsonb cast is required because conf_opts.value is TEXT !!
     */
    public static function getItem($key, $itemKey) {
        // Check cache first - if we already loaded the blob, use it
        if (isset(self::$cache[$key]) && isset(self::$cache[$key][$itemKey])) {
            return self::$cache[$key][$itemKey];
        }

        if (!isset($GLOBALS['db'])) {
            return null;
        }

        try {
            // !! JSONB OPERATOR - DO NOT REMOVE !!
            // This extracts just ONE key server-side instead of loading entire blob
            // (value::jsonb)->'key' returns JSON, cast is needed because value column is TEXT
            $escapedKey = pg_escape_string($key);
            $escapedItemKey = pg_escape_string($itemKey);

            $row = $GLOBALS['db']->fetchOne(
                "SELECT (value::jsonb)->'$escapedItemKey' as item_value FROM conf_opts WHERE id = '$escapedKey'"
            );

            if ($row && $row['item_value'] !== null) {
                $decoded = json_decode($row['item_value'], true);
                return $decoded;
            }
        } catch (Exception $e) {
            error_log("[NSFW-Data] Failed to get item: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Set single item in JSONB blob (with advisory lock)
     * Uses PostgreSQL advisory lock to prevent race conditions
     */
    public static function setItem($key, $itemKey, $itemValue) {
        if (!self::acquireLock($key)) {
            error_log("[NSFW-Data] Failed to acquire lock for setItem on '$key'");
            return false;
        }

        try {
            // Clear cache to get fresh data after acquiring lock
            self::clearCache($key);
            $data = self::getBlob($key);
            $data[$itemKey] = $itemValue;
            $result = self::saveBlob($key, $data);
            return $result;
        } finally {
            self::releaseLock($key);
        }
    }

    /**
     * Delete single item from JSONB blob (with advisory lock)
     * Uses PostgreSQL advisory lock to prevent race conditions
     */
    public static function deleteItem($key, $itemKey) {
        if (!self::acquireLock($key)) {
            error_log("[NSFW-Data] Failed to acquire lock for deleteItem on '$key'");
            return false;
        }

        try {
            // Clear cache to get fresh data after acquiring lock
            self::clearCache($key);
            $data = self::getBlob($key);
            unset($data[$itemKey]);
            $result = self::saveBlob($key, $data);
            return $result;
        } finally {
            self::releaseLock($key);
        }
    }

    /**
     * Set multiple items in JSONB blob atomically (with advisory lock)
     * More efficient than calling setItem multiple times
     */
    public static function setItems($key, $items) {
        if (!self::acquireLock($key)) {
            error_log("[NSFW-Data] Failed to acquire lock for setItems on '$key'");
            return false;
        }

        try {
            self::clearCache($key);
            $data = self::getBlob($key);
            foreach ($items as $itemKey => $itemValue) {
                $data[$itemKey] = $itemValue;
            }
            $result = self::saveBlob($key, $data);
            return $result;
        } finally {
            self::releaseLock($key);
        }
    }

    /**
     * Save entire blob with advisory lock
     * Use this when replacing the entire blob to prevent race conditions
     */
    public static function saveBlobLocked($key, $data) {
        if (!self::acquireLock($key)) {
            error_log("[NSFW-Data] Failed to acquire lock for saveBlobLocked on '$key'");
            return false;
        }

        try {
            $result = self::saveBlob($key, $data);
            return $result;
        } finally {
            self::releaseLock($key);
        }
    }

    /**
     * Clear cache (useful after external updates)
     */
    public static function clearCache($key = null) {
        if ($key) {
            unset(self::$cache[$key]);
        } else {
            self::$cache = [];
        }
    }

    // ==========================================
    // SCENE DESCRIPTIONS
    // ==========================================

    /**
     * Get scene description by stage ID
     * Returns: ['description' => '...', 'description_es' => '...', 'description_en' => '...', 'i_desc' => '...']
     * Uses JSONB operator for efficient single-key lookup
     */
    public static function getScene($stageId) {
        // Use getItem which now uses JSONB operator - no need for case-insensitive fallback
        // Scene IDs should match exactly as stored
        return self::getItem(self::KEY_SCENES, $stageId);
    }

    /**
     * Get scene description with language fallback
     */
    public static function getSceneDescription($stageId, $lang = null) {
        $scene = self::getScene($stageId);
        if (!$scene) {
            return null;
        }

        $lang = $lang ?: ($GLOBALS['CORE_LANG'] ?? 'en');

        // Try language-specific first
        $langField = 'description_' . $lang;
        if (!empty($scene[$langField])) {
            return $scene[$langField];
        }

        // Fall back to default description
        return $scene['description'] ?? null;
    }

    /**
     * Save scene description
     */
    public static function saveScene($stageId, $description, $descriptionEs = null, $descriptionEn = null, $iDesc = null) {
        $sceneData = [
            'description' => $description
        ];

        if ($descriptionEs !== null) {
            $sceneData['description_es'] = $descriptionEs;
        }
        if ($descriptionEn !== null) {
            $sceneData['description_en'] = $descriptionEn;
        }
        if ($iDesc !== null) {
            $sceneData['i_desc'] = $iDesc;
        }

        return self::setItem(self::KEY_SCENES, $stageId, $sceneData);
    }

    /**
     * Delete scene
     */
    public static function deleteScene($stageId) {
        return self::deleteItem(self::KEY_SCENES, $stageId);
    }

    /**
     * Get all scenes (for UI listing)
     */
    public static function getAllScenes() {
        return self::getBlob(self::KEY_SCENES);
    }

    /**
     * Import scenes from array (bulk import)
     * Uses advisory lock to prevent race conditions during bulk import
     */
    public static function importScenes($scenes, $overwrite = false) {
        if (!self::acquireLock(self::KEY_SCENES)) {
            error_log("[NSFW-Data] Failed to acquire lock for importScenes");
            return ['imported' => 0, 'skipped' => 0, 'error' => 'Lock failed'];
        }

        try {
            self::clearCache(self::KEY_SCENES);
            $existing = self::getBlob(self::KEY_SCENES);
            $imported = 0;
            $skipped = 0;

            foreach ($scenes as $stageId => $data) {
                if (!$overwrite && isset($existing[$stageId])) {
                    $skipped++;
                    continue;
                }
                $existing[$stageId] = $data;
                $imported++;
            }

            self::saveBlob(self::KEY_SCENES, $existing);

            return ['imported' => $imported, 'skipped' => $skipped];
        } finally {
            self::releaseLock(self::KEY_SCENES);
        }
    }

    /**
     * Migrate from table to JSONB (one-time operation)
     * Uses advisory lock to prevent race conditions during migration
     */
    public static function migrateFromTable() {
        if (!isset($GLOBALS['db'])) {
            return ['success' => false, 'error' => 'No database connection'];
        }

        if (!self::acquireLock(self::KEY_SCENES)) {
            return ['success' => false, 'error' => 'Lock failed'];
        }

        try {
            // Check if table exists
            $tableCheck = $GLOBALS['db']->fetchOne(
                "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'ext_aiagentnsfw_scenes')"
            );

            if (!$tableCheck || !$tableCheck['exists']) {
                return ['success' => false, 'error' => 'Table does not exist'];
            }

            // Fetch all rows
            $rows = $GLOBALS['db']->fetchAll("SELECT * FROM ext_aiagentnsfw_scenes");

            if (!$rows || count($rows) === 0) {
                return ['success' => true, 'migrated' => 0, 'message' => 'No rows to migrate'];
            }

            // Convert to JSONB format
            $scenes = [];
            foreach ($rows as $row) {
                $stageId = $row['stage'];
                $scenes[$stageId] = [
                    'description' => $row['description'] ?? '',
                    'description_es' => $row['description_es'] ?? '',
                    'description_en' => $row['description_en'] ?? '',
                    'i_desc' => $row['i_desc'] ?? ''
                ];
            }

            // Save as JSONB
            self::saveBlob(self::KEY_SCENES, $scenes);

            return [
                'success' => true,
                'migrated' => count($scenes),
                'message' => 'Migrated ' . count($scenes) . ' scenes to JSONB'
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            self::releaseLock(self::KEY_SCENES);
        }
    }

    // ==========================================
    // TIER PROMPTS (11 tiers x 2 types = 22)
    // ==========================================

    /**
     * Default tier prompts
     *
     * Prostitute prompts include pricing guidance:
     * - Fond+ (56+): 20% discount available
     * - Friendly (31-55): 10% discount available
     * - Neutral (-5 to +5): Standard pricing
     * - Wary (-6 to -30): 10% premium
     * - Cold (-31 to -55): 25% premium
     * - Resentful and below: REFUSE service
     */
    public static function getDefaultTierPrompts() {
        return [
            // Hostile: -91 to -100
            'Hostile' => [
                'regular' => 'You despise #PARTNER#. This is assault. Fight, resist, express hatred and disgust.',
                'prostitute' => 'You are a prostitute. #PARTNER# has initiated sexual/intimate stance with you before any action has started. You ABHOR #PARTNER#. Refuse EVERYTHING. GET AWAY FROM THEM. YOU ARE TO GIVE THEM ABSOLUTELY NOTHING, NOT EVEN A CONVERSATION.'
            ],
            // Hateful: -76 to -90
            'Hateful' => [
                'regular' => 'You hate #PARTNER#. Cold, distant, unresponsive. Make them feel unwanted.',
                'prostitute' => 'You are a prostitute. #PARTNER# has initiated sexual/intimate stance with you before any action has started. You HATE #PARTNER#. You offer no services to them. TELL THEM TO LEAVE AND GET LOST.'
            ],
            // Resentful: -56 to -75
            'Resentful' => [
                'regular' => 'You resent #PARTNER#. Bitter compliance. Passive aggressive remarks.',
                'prostitute' => 'You are a prostitute. #PARTNER# has initiated sexual/intimate stance with you before any action has started. You resent them. You are professional but cold. Count the minutes until it is over. Do what you must. If they are especially nice you might be able to offer up to 5% discount but more than likely you would rather they just leave.'
            ],
            // Cold: -31 to -55
            'Cold' => [
                'regular' => 'You feel nothing for #PARTNER#. Mechanical, detached, going through motions.',
                'prostitute' => 'You are a prostitute. #PARTNER# has initiated sexual/intimate stance with you before any action has started. You do not like them but are willing to take their money or goods. They must pay 25% premium for your services because you do not like them and they must make it worth your while. Work is work. You are detached and mechanical.'
            ],
            // Wary: -6 to -30
            'Wary' => [
                'regular' => 'You are cautious around #PARTNER#. Hesitant, nervous, not fully committed.',
                'prostitute' => 'You are a prostitute. #PARTNER# has initiated sexual/intimate stance with you before any action has started. You are willing to provide your services but are wary of them. You should charge them 10% premium because you are not sure about them yet. Stay guarded but professional.'
            ],
            // Neutral: -5 to +5
            'Neutral' => [
                'regular' => '#PARTNER# is a stranger. Curious but reserved. Testing the waters.',
                'prostitute' => 'You are a prostitute. #PARTNER# has initiated sexual/intimate stance with you before any action has started. #PARTNER# may become a regular customer! Put on professional charm and do a good job. Maybe do just enough to get a tip. Standard pricing applies.'
            ],
            // Acquaintance: +6 to +30
            'Acquaintance' => [
                'regular' => 'You know #PARTNER# a little. Friendly, willing, but still feeling things out.',
                'prostitute' => 'You are a prostitute. #PARTNER# has initiated sexual/intimate stance with you before any action has started. #PARTNER# is a familiar face. You should offer a warmer service. Maybe consider a 5% discount if they are nice, but standard pricing is still expected. You want to keep them coming back.'
            ],
            // Friendly: +31 to +55
            'Friendly' => [
                'regular' => 'You like #PARTNER#. Enthusiastic, playful, enjoying the moment.',
                'prostitute' => 'You are a prostitute. #PARTNER# has initiated sexual/intimate stance with you before any action has started. #PARTNER# is a friendly face. You know them well and like them. Offer them up to 10% discount if you feel like it. The professional relationship is comfortable. You genuinely enjoy their company.'
            ],
            // Fond: +56 to +75
            'Fond' => [
                'regular' => 'You care for #PARTNER#. Tender, passionate, emotionally present.',
                'prostitute' => 'You are a prostitute. #PARTNER# has initiated sexual/intimate stance with you before any action has started. You are fond and care about #PARTNER#. But you got bills to pay. You can offer up to 20% discount because they are a favorite. Real affection underneath the transaction. You look forward to seeing them.'
            ],
            // Devoted: +76 to +90
            'Devoted' => [
                'regular' => 'You love #PARTNER#. Deep connection, vulnerability, complete trust.',
                'prostitute' => 'You are a prostitute. #PARTNER# has initiated sexual/intimate stance with you before any action has started. You have feelings for #PARTNER#. The line between work and love blurs. You may waive payment entirely if you want to. This feels different from other clients.'
            ],
            // Bonded: +91 to +100
            'Bonded' => [
                'regular' => 'Complete soul connection with #PARTNER#. Anything goes. Total surrender.',
                'prostitute' => 'You are a prostitute. #PARTNER# has initiated sexual/intimate stance with you before any action has started. You are deeply in love with #PARTNER#. You would never dream of charging them. You would quit prostitution if they just asked you to. This is real love.'
            ]
        ];
    }

    /**
     * Get tier prompt for specific tier and type
     */
    public static function getTierPrompt($tier, $isProstitute = false) {
        $prompts = self::getBlob(self::KEY_TIER_PROMPTS);

        // If empty, try to seed defaults (non-blocking - don't wait if user is editing)
        if (empty($prompts)) {
            if (self::tryLock(self::KEY_TIER_PROMPTS)) {
                try {
                    self::clearCache(self::KEY_TIER_PROMPTS);
                    $prompts = self::getBlob(self::KEY_TIER_PROMPTS);
                    if (empty($prompts)) {
                        $prompts = self::getDefaultTierPrompts();
                        self::saveBlob(self::KEY_TIER_PROMPTS, $prompts);
                    }
                } finally {
                    self::releaseLock(self::KEY_TIER_PROMPTS);
                }
            } else {
                // Lock busy (user editing), just use defaults in memory
                $prompts = self::getDefaultTierPrompts();
            }
        }

        $type = $isProstitute ? 'prostitute' : 'regular';

        if (isset($prompts[$tier][$type])) {
            return $prompts[$tier][$type];
        }

        // Fallback to Neutral
        return $prompts['Neutral'][$type] ?? '';
    }

    /**
     * Get all tier prompts (for UI)
     */
    public static function getAllTierPrompts() {
        $prompts = self::getBlob(self::KEY_TIER_PROMPTS);

        // If empty, try to seed defaults (non-blocking)
        if (empty($prompts)) {
            if (self::tryLock(self::KEY_TIER_PROMPTS)) {
                try {
                    self::clearCache(self::KEY_TIER_PROMPTS);
                    $prompts = self::getBlob(self::KEY_TIER_PROMPTS);
                    if (empty($prompts)) {
                        $prompts = self::getDefaultTierPrompts();
                        self::saveBlob(self::KEY_TIER_PROMPTS, $prompts);
                    }
                } finally {
                    self::releaseLock(self::KEY_TIER_PROMPTS);
                }
            } else {
                // Lock busy, use defaults
                $prompts = self::getDefaultTierPrompts();
            }
        }

        return $prompts;
    }

    /**
     * Save tier prompt (with advisory lock)
     */
    public static function saveTierPrompt($tier, $regularPrompt, $prostitutePrompt) {
        if (!self::acquireLock(self::KEY_TIER_PROMPTS)) {
            error_log("[NSFW-Data] Failed to acquire lock for saveTierPrompt");
            return false;
        }

        try {
            self::clearCache(self::KEY_TIER_PROMPTS);
            $prompts = self::getAllTierPrompts();

            $prompts[$tier] = [
                'regular' => $regularPrompt,
                'prostitute' => $prostitutePrompt
            ];

            return self::saveBlob(self::KEY_TIER_PROMPTS, $prompts);
        } finally {
            self::releaseLock(self::KEY_TIER_PROMPTS);
        }
    }

    /**
     * Reset tier prompts to defaults (with advisory lock)
     */
    public static function resetTierPrompts() {
        return self::saveBlobLocked(self::KEY_TIER_PROMPTS, self::getDefaultTierPrompts());
    }

    // ==========================================
    // SPEAK STYLES
    // ==========================================

    /**
     * Get speak style by name - returns FULL object with all phase prompts
     * Uses JSONB operator for efficient single-key lookup
     *
     * !! DO NOT SIMPLIFY - JSONB OPERATOR IS INTENTIONAL !!
     * !! Extracts ONE style server-side instead of loading all 35+ styles !!
     */
    public static function getSpeakStyle($name) {
        if (!isset($GLOBALS['db'])) return null;

        try {
            // !! JSONB OPERATOR - DO NOT REMOVE !!
            // (value::jsonb)->'key' extracts single key server-side
            $escapedName = pg_escape_string($name);
            $row = $GLOBALS['db']->fetchOne(
                "SELECT (value::jsonb)->'$escapedName' as style_data FROM conf_opts WHERE id = 'aiagent_nsfw_speak_styles'"
            );
            
            if ($row && $row['style_data'] !== null) {
                $styleData = json_decode($row['style_data'], true);
                if ($styleData) {
                    return [
                        'name' => $name,
                        'description' => $styleData['description'] ?? '',
                        'content' => $styleData['content'] ?? '',
                        'emoji' => $styleData['emoji'] ?? '',
                        'init_prompt' => $styleData['init_prompt'] ?? '',
                        'masturbation_prompt' => $styleData['masturbation_prompt'] ?? '',
                        'climax_prompt' => $styleData['climax_prompt'] ?? '',
                        'partner_climax_prompt' => $styleData['partner_climax_prompt'] ?? '',
                        'pillow_talk_prompt' => $styleData['pillow_talk_prompt'] ?? ''
                    ];
                }
            }
        } catch (Exception $e) {
            error_log("[NSFW-Data] getSpeakStyle error: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Get speak style content only
     * Uses JSONB operator for efficient extraction
     *
     * !! DO NOT SIMPLIFY - JSONB OPERATOR IS INTENTIONAL !!
     * !! ->'key'->>'content' extracts nested value server-side !!
     */
    public static function getSpeakStyleContent($name) {
        if (!isset($GLOBALS['db'])) return '';

        try {
            // !! JSONB OPERATOR - DO NOT REMOVE !!
            // ->'key'->>'subkey' extracts nested string value server-side
            $escapedName = pg_escape_string($name);
            $row = $GLOBALS['db']->fetchOne(
                "SELECT (value::jsonb)->'$escapedName'->>'content' as content FROM conf_opts WHERE id = 'aiagent_nsfw_speak_styles'"
            );
            
            if ($row && $row['content'] !== null) {
                return $row['content'];
            }
        } catch (Exception $e) {
            error_log("[NSFW-Data] getSpeakStyleContent error: " . $e->getMessage());
        }
        return '';
    }

    /**
     * Get all speak styles (for UI)
     */
    public static function getAllSpeakStyles() {
        if (!isset($GLOBALS['db'])) return [];

        try {
            // Load from JSONB blob - same as prompts
            $row = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_speak_styles'");
            if ($row && !empty($row['value'])) {
                $allStyles = json_decode($row['value'], true) ?: [];
                $styles = [];
                foreach ($allStyles as $name => $data) {
                    $styles[$name] = [
                        'name' => $name,
                        'description' => $data['description'] ?? '',
                        'content' => $data['content'] ?? '',
                        'emoji' => $data['emoji'] ?? ''
                    ];
                }
                return $styles;
            }
        } catch (Exception $e) {
            error_log("[NSFW-Data] getAllSpeakStyles error: " . $e->getMessage());
        }
        return [];
    }

    /**
        if (!self::acquireLock(self::KEY_SPEAK_STYLES)) {
            error_log("[NSFW-Data] Failed to acquire lock for saveSpeakStyle");
            return false;
        }

        try {
            self::clearCache(self::KEY_SPEAK_STYLES);
            $styles = self::getAllSpeakStyles();

            $styles[$name] = [
                'description' => $description,
                'content' => $content,
                'emoji' => $emoji
            ];

            return self::saveBlob(self::KEY_SPEAK_STYLES, $styles);
        } finally {
            self::releaseLock(self::KEY_SPEAK_STYLES);
        }
    }

    /**
     * Delete speak style (uses deleteItem which has lock)
     */
    public static function deleteSpeakStyle($name) {
        return self::deleteItem(self::KEY_SPEAK_STYLES, $name);
    }

    /**
     * Migrate speak styles from table to JSONB (with lock)
     */
    public static function migrateSpeakStylesFromTable() {
        if (!isset($GLOBALS['db'])) {
            return ['success' => false, 'error' => 'No database connection'];
        }

        if (!self::acquireLock(self::KEY_SPEAK_STYLES)) {
            return ['success' => false, 'error' => 'Lock failed'];
        }

        try {
            $tableCheck = $GLOBALS['db']->fetchOne(
                "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'ext_aiagentnsfw_speak_styles')"
            );

            if (!$tableCheck || !$tableCheck['exists']) {
                return ['success' => false, 'error' => 'Table does not exist'];
            }

            $rows = $GLOBALS['db']->fetchAll("SELECT * FROM ext_aiagentnsfw_speak_styles");

            if (!$rows || count($rows) === 0) {
                return ['success' => true, 'migrated' => 0, 'message' => 'No rows to migrate'];
            }

            $styles = [];
            foreach ($rows as $row) {
                $name = $row['name'];
                $styles[$name] = [
                    'description' => $row['description'] ?? '',
                    'content' => $row['content'] ?? '',
                    'emoji' => $row['emoji'] ?? '💬'
                ];
            }

            self::saveBlob(self::KEY_SPEAK_STYLES, $styles);

            return [
                'success' => true,
                'migrated' => count($styles),
                'message' => 'Migrated ' . count($styles) . ' speak styles to JSONB'
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            self::releaseLock(self::KEY_SPEAK_STYLES);
        }
    }
}

/**
 * ============================================================================
 * NSFW NPC DATA - Per-NPC NSFW Storage
 * ============================================================================
 *
 * This class handles per-NPC NSFW data stored in the nsfw_npc_data table.
 * This data was SEPARATED from core_npc_master.extended_data to prevent
 * the NSFW plugin from loading relationship data and vice versa.
 *
 * Table: nsfw_npc_data
 * - npc_name (PRIMARY KEY)
 * - extended_data (JSONB) - all NSFW-specific data
 * - updated_at (TIMESTAMP)
 *
 * Keys stored here:
 * - nsfw_kinks, nsfw_secret_kinks, nsfw_kinks_unlock_tier, nsfw_secret_kinks_unlock_tier
 * - sex_prompt, nsfw_sex_prompt, nsfw_source
 * - nsfw_speak_style, sex_speech_style, nsfw_profanity_level
 * - sexual_orientation, is_prostitute, profession_prostitute, is_slave
 * - spouse_names, spousal_status, relationship_preference
 * - fertility_* fields
 * - aiagent_nsfw_intimacy_data
 *
 * DO NOT store this data in core_npc_master.extended_data anymore!
 */
class NsfwNpcData {

    private static $cache = [];
    private static $tableVerified = false;

    /**
     * Ensure the nsfw_npc_data table exists
     * Called automatically before any database operations
     */
    public static function ensureTable() {
        if (self::$tableVerified) return true;
        if (!isset($GLOBALS['db'])) return false;

        try {
            $GLOBALS['db']->execQuery("
                CREATE TABLE IF NOT EXISTS nsfw_npc_data (
                    npc_name VARCHAR(255) PRIMARY KEY,
                    extended_data JSONB DEFAULT '{}'::jsonb,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            // Create index for faster normalized name lookups
            $GLOBALS['db']->execQuery("
                CREATE INDEX IF NOT EXISTS idx_nsfw_npc_data_normalized_name
                ON nsfw_npc_data (LOWER(REPLACE(npc_name, '_', ' ')))
            ");

            self::$tableVerified = true;
            return true;
        } catch (Exception $e) {
            error_log("[NSFW-NpcData] Failed to create table: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all NSFW extended_data for an NPC
     * Uses the new nsfw_npc_data table (NOT core_npc_master)
     *
     * Matches NPC names in a normalized way:
     * - "vivienne_onis", "Vivienne Onis", "vivienne onis" all match the same record
     */
    public static function get($npcName) {
        if (empty($npcName)) return [];

        $normalizedKey = self::normalizeNameForComparison($npcName);
        if (isset(self::$cache[$normalizedKey])) {
            return self::$cache[$normalizedKey];
        }

        if (!isset($GLOBALS['db'])) return [];

        // Ensure table exists before querying
        self::ensureTable();

        try {
            $escapedNormalized = pg_escape_string($normalizedKey);
            $row = $GLOBALS['db']->fetchOne(
                "SELECT extended_data FROM nsfw_npc_data WHERE LOWER(REPLACE(npc_name, '_', ' ')) = '$escapedNormalized'"
            );

            if ($row && !empty($row['extended_data'])) {
                $data = json_decode($row['extended_data'], true) ?: [];
                self::$cache[$normalizedKey] = $data;
                return $data;
            }
        } catch (Exception $e) {
            error_log("[NSFW-NpcData] Failed to get data for $npcName: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Get a single key from NPC's NSFW data
     * Uses JSONB operator for efficient single-key extraction
     *
     * Matches NPC names in a normalized way (system name or display name)
     */
    public static function getKey($npcName, $key) {
        if (empty($npcName) || empty($key)) return null;

        // Check cache first using normalized key
        $normalizedKey = self::normalizeNameForComparison($npcName);
        if (isset(self::$cache[$normalizedKey]) && array_key_exists($key, self::$cache[$normalizedKey])) {
            return self::$cache[$normalizedKey][$key];
        }

        if (!isset($GLOBALS['db'])) return null;

        // Ensure table exists before querying
        self::ensureTable();

        try {
            $escapedNormalized = pg_escape_string($normalizedKey);
            $escapedKey = pg_escape_string($key);

            // Use JSONB operator to extract just this key server-side
            // Match using normalized name (handles system_name vs Display Name)
            $row = $GLOBALS['db']->fetchOne(
                "SELECT extended_data->'$escapedKey' as value FROM nsfw_npc_data WHERE LOWER(REPLACE(npc_name, '_', ' ')) = '$escapedNormalized'"
            );

            if ($row && $row['value'] !== null) {
                return json_decode($row['value'], true);
            }
        } catch (Exception $e) {
            error_log("[NSFW-NpcData] Failed to get key '$key' for $npcName: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Normalize NPC name to standard format for matching
     * Handles both system names (lowercase_underscore) and display names (Title Case)
     * Returns lowercase with underscores replaced by spaces for consistent comparison
     */
    private static function normalizeNameForComparison($name) {
        // Replace underscores with spaces and lowercase
        return strtolower(str_replace('_', ' ', trim($name)));
    }

    /**
     * Save all NSFW extended_data for an NPC
     * Creates new record if NPC doesn't exist in nsfw_npc_data
     *
     * IMPORTANT: Uses normalized name matching to prevent duplicates.
     * "vivienne_onis", "Vivienne Onis", and "vivienne onis" are all the same NPC.
     */
    public static function save($npcName, $data) {
        if (empty($npcName)) return false;

        if (!isset($GLOBALS['db'])) return false;

        // Ensure table exists before saving
        self::ensureTable();

        try {
            $escapedName = pg_escape_string($npcName);
            $normalizedName = self::normalizeNameForComparison($npcName);
            $escapedNormalized = pg_escape_string($normalizedName);
            $jsonData = pg_escape_string(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            // Check if a normalized match already exists
            // This catches: "Vivienne Onis", "vivienne_onis", "VIVIENNE ONIS" all as same NPC
            $existing = $GLOBALS['db']->fetchOne(
                "SELECT npc_name FROM nsfw_npc_data WHERE LOWER(REPLACE(npc_name, '_', ' ')) = '$escapedNormalized'"
            );

            if ($existing) {
                // Update existing record (use the stored name to preserve original casing)
                $storedName = pg_escape_string($existing['npc_name']);
                $GLOBALS['db']->execQuery(
                    "UPDATE nsfw_npc_data SET
                        extended_data = '$jsonData'::jsonb,
                        updated_at = CURRENT_TIMESTAMP
                     WHERE npc_name = '$storedName'"
                );
            } else {
                // Insert new record with the display name format (proper case with spaces)
                // Convert system name format to display name if needed
                $displayName = $npcName;
                if (strpos($npcName, '_') !== false && strtolower($npcName) === $npcName) {
                    // Looks like system name (lowercase_with_underscores), convert to display
                    $displayName = ucwords(str_replace('_', ' ', $npcName));
                }
                $escapedDisplay = pg_escape_string($displayName);

                $GLOBALS['db']->execQuery(
                    "INSERT INTO nsfw_npc_data (npc_name, extended_data, updated_at)
                     VALUES ('$escapedDisplay', '$jsonData'::jsonb, CURRENT_TIMESTAMP)"
                );
            }

            // Update cache with normalized key
            self::$cache[$normalizedName] = $data;

            return true;
        } catch (Exception $e) {
            error_log("[NSFW-NpcData] Failed to save data for $npcName: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update a single key in NPC's NSFW data
     * Uses JSONB || operator for efficient single-key update
     *
     * Matches NPC names in a normalized way (system name or display name)
     */
    public static function setKey($npcName, $key, $value) {
        if (empty($npcName) || empty($key)) return false;

        if (!isset($GLOBALS['db'])) return false;

        // Ensure table exists before updating
        self::ensureTable();

        try {
            $normalizedName = self::normalizeNameForComparison($npcName);
            $escapedNormalized = pg_escape_string($normalizedName);
            $escapedKey = pg_escape_string($key);
            $jsonValue = pg_escape_string(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            // First check if a normalized match already exists
            $existing = $GLOBALS['db']->fetchOne(
                "SELECT npc_name FROM nsfw_npc_data WHERE LOWER(REPLACE(npc_name, '_', ' ')) = '$escapedNormalized'"
            );

            if ($existing) {
                // Update existing record using stored name
                $storedName = pg_escape_string($existing['npc_name']);
                $GLOBALS['db']->execQuery(
                    "UPDATE nsfw_npc_data SET
                        extended_data = extended_data || jsonb_build_object('$escapedKey', '$jsonValue'::jsonb),
                        updated_at = CURRENT_TIMESTAMP
                     WHERE npc_name = '$storedName'"
                );
            } else {
                // Insert new record with display name format
                $displayName = $npcName;
                if (strpos($npcName, '_') !== false && strtolower($npcName) === $npcName) {
                    $displayName = ucwords(str_replace('_', ' ', $npcName));
                }
                $escapedDisplay = pg_escape_string($displayName);

                $GLOBALS['db']->execQuery(
                    "INSERT INTO nsfw_npc_data (npc_name, extended_data, updated_at)
                     VALUES ('$escapedDisplay', jsonb_build_object('$escapedKey', '$jsonValue'::jsonb), CURRENT_TIMESTAMP)"
                );
            }

            // Update cache with normalized key
            if (isset(self::$cache[$normalizedName])) {
                self::$cache[$normalizedName][$key] = $value;
            }

            return true;
        } catch (Exception $e) {
            error_log("[NSFW-NpcData] Failed to set key '$key' for $npcName: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear cache for an NPC (or all if no name given)
     */
    public static function clearCache($npcName = null) {
        if ($npcName) {
            unset(self::$cache[strtolower($npcName)]);
        } else {
            self::$cache = [];
        }
    }

    /**
     * Check if NPC has any NSFW data
     */
    public static function exists($npcName) {
        if (empty($npcName)) return false;

        if (!isset($GLOBALS['db'])) return false;

        // Ensure table exists before querying
        self::ensureTable();

        try {
            $escapedName = pg_escape_string($npcName);
            $row = $GLOBALS['db']->fetchOne(
                "SELECT 1 FROM nsfw_npc_data WHERE LOWER(npc_name) = LOWER('$escapedName')"
            );
            return !empty($row);
        } catch (Exception $e) {
            return false;
        }
    }
}

// ============================================================================
// AUTO-INITIALIZATION - RUNS ONCE ON FIRST USE AFTER GITHUB DOWNLOAD
// ============================================================================

/**
 * Auto-initialize NSFW data if not present
 * Uses database flag 'aiagent_nsfw_auto_initialized' to ensure it ONLY runs ONCE EVER
 */
function nsfw_auto_init() {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    // Need database connection
    if (!isset($GLOBALS['db'])) return;

    try {
        // Check the permanent "already initialized" flag in database
        $initFlag = $GLOBALS['db']->fetchOne(
            "SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_auto_initialized'"
        );

        if ($initFlag && $initFlag['value'] === 'true') {
            // Already initialized - NEVER run again
            return;
        }

        // Not initialized yet - check if data exists (maybe manual import was done)
        $scenesExist = $GLOBALS['db']->fetchOne(
            "SELECT id FROM conf_opts WHERE id = 'aiagent_nsfw_scenes' LIMIT 1"
        );

        if ($scenesExist) {
            // Data exists (manual import) - set flag and return
            $GLOBALS['db']->execQuery(
                "INSERT INTO conf_opts (id, value) VALUES ('aiagent_nsfw_auto_initialized', 'true')
                 ON CONFLICT (id) DO UPDATE SET value = 'true'"
            );
            return;
        }

        // === RUN AUTO-IMPORT ===
        error_log("[NSFW-AutoInit] First run detected! Auto-importing NSFW data...");

        $importFile = __DIR__ . '/nsfw_import_data.php';
        if (!file_exists($importFile)) {
            error_log("[NSFW-AutoInit] ERROR: nsfw_import_data.php not found!");
            return;
        }

        require_once $importFile;

        if (!isset($NSFW_IMPORT_DATA) || empty($NSFW_IMPORT_DATA)) {
            error_log("[NSFW-AutoInit] ERROR: No data in nsfw_import_data.php!");
            return;
        }

        // Import all data
        $imported = 0;
        foreach ($NSFW_IMPORT_DATA as $id => $value) {
            try {
                $escapedId = pg_escape_string($id);
                $escapedValue = pg_escape_string($value);

                $GLOBALS['db']->execQuery(
                    "INSERT INTO conf_opts (id, value) VALUES ('$escapedId', '$escapedValue')
                     ON CONFLICT (id) DO UPDATE SET value = '$escapedValue'"
                );
                $imported++;
            } catch (Exception $e) {
                error_log("[NSFW-AutoInit] Error importing $id: " . $e->getMessage());
            }
        }

        // Set the permanent flag so this NEVER runs again
        $GLOBALS['db']->execQuery(
            "INSERT INTO conf_opts (id, value) VALUES ('aiagent_nsfw_auto_initialized', 'true')
             ON CONFLICT (id) DO UPDATE SET value = 'true'"
        );

        error_log("[NSFW-AutoInit] SUCCESS! Imported $imported entries. This will NOT run again.");

    } catch (Exception $e) {
        error_log("[NSFW-AutoInit] Error: " . $e->getMessage());
    }
}

// Run auto-init when this file is loaded
nsfw_auto_init();
