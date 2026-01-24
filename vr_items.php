<?php
/**
 * ============================================================================
 * VR Item Awareness Handler
 * ============================================================================
 *
 * MIGRATION STATUS: ✅ READY FOR CHIM CORE
 * NSFW CONTENT: ❌ NONE - This is general VR immersion
 *
 * This file should be moved to core CHIM/AIAgent when ready.
 * No dependencies on NSFW-specific code.
 *
 * ============================================================================
 * FOR RANGAROO/TYLER - MIGRATION INSTRUCTIONS:
 * ============================================================================
 * 1. Copy this entire file to: ext/aiagent/vr_items.php (or similar)
 * 2. Add require_once in core common.php (or wherever event handlers live)
 * 3. Add wrapper function (see common.php for example):
 *    function processInfoVRItems() {
 *        require_once(__DIR__ . '/vr_items.php');
 *        VRItems::processEvent();
 *    }
 * 4. Register event handler for "ext_vr_item_raw" game events
 * 5. Database: Uses conf_opts table with key 'vr_player_hand_state'
 *
 * ============================================================================
 * WHAT THIS DOES:
 * ============================================================================
 * - Tracks what items are in player's left/right hands (HIGGS VR mod)
 * - Persists hand state to database for context injection
 * - Formats pickup/drop events for LLM consumption
 * - Categorizes items (weapon, drink, food, valuable, etc.)
 *
 * Raw data format from PSC (uses ^ delimiter to avoid CHIM pipe conflict):
 * - Pickup: itemname^pickup^hand
 * - Drop:   itemname^drop^hand
 *
 * USAGE:
 *   VRItems::processEvent()       - Main entry point (handles gameRequest rewriting)
 *   VRItems::processItemEvent()   - Low-level message formatting only
 *   VRItems::getHandState()       - Get current items in each hand from DB
 *   VRItems::getHeldItemsContext() - Get formatted context for LLM injection
 * ============================================================================
 */

class VRItems {

    // Database key for storing hand state
    private static $HAND_STATE_KEY = 'vr_player_hand_state';

    /**
     * Get current hand state from database
     * Returns: ['left' => 'itemName or null', 'right' => 'itemName or null']
     */
    public static function getHandState() {
        if (!isset($GLOBALS["db"])) {
            return ['left' => null, 'right' => null];
        }

        $key = $GLOBALS["db"]->escape(self::$HAND_STATE_KEY);
        $result = $GLOBALS["db"]->fetchOne(
            "SELECT value FROM conf_opts WHERE id = '$key'"
        );

        if ($result && !empty($result['value'])) {
            $state = json_decode($result['value'], true);
            if (is_array($state)) {
                return [
                    'left' => $state['left'] ?? null,
                    'right' => $state['right'] ?? null
                ];
            }
        }

        return ['left' => null, 'right' => null];
    }

    /**
     * Update hand state in database
     */
    private static function updateHandState($hand, $itemName) {
        if (!isset($GLOBALS["db"])) {
            return;
        }

        $state = self::getHandState();
        $state[$hand] = $itemName;

        // Upsert the hand state using the proper CHIM method
        $GLOBALS["db"]->upsertRowOnConflict(
            "conf_opts",
            [
                "id" => self::$HAND_STATE_KEY,
                "value" => json_encode($state)
            ],
            "id"
        );

        error_log("[VRItems] Hand state updated: " . json_encode($state));
    }

    /**
     * Clear item from hand (on drop)
     */
    private static function clearHand($hand) {
        self::updateHandState($hand, null);
    }

    /**
     * Get formatted string of what player is currently holding
     * For injection into context
     */
    public static function getHeldItemsContext() {
        $state = self::getHandState();

        $parts = [];
        if (!empty($state['left'])) {
            $parts[] = "left hand: " . $state['left'];
        }
        if (!empty($state['right'])) {
            $parts[] = "right hand: " . $state['right'];
        }

        if (empty($parts)) {
            return null;
        }

        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";
        return "<VR_HELD_ITEMS>\n{$playerName} is currently holding: " . implode(", ", $parts) . "\n</VR_HELD_ITEMS>";
    }

    /**
     * Main entry point - Process VR Item Events (HIGGS pickup/drop)
     * Converts raw item events to formatted messages for the LLM
     *
     * This is the orchestration method called from common.php wrapper.
     * Handles gameRequest rewriting.
     */
    public static function processEvent() {
        global $gameRequest;

        // Only process VR item events
        if ($gameRequest[0] != "ext_vr_item_raw") {
            return;
        }

        // Get raw item data from event
        $rawData = $gameRequest[3] ?? '';
        if (empty($rawData)) {
            return;
        }

        // Process through internal handler
        $result = self::processItemEvent($rawData);
        if (!$result) {
            return;
        }

        $itemName = $result['itemName'];
        $action = $result['action'];
        $hand = $result['hand'];

        // Update persistent hand state
        if ($action === 'pickup') {
            self::updateHandState($hand, $itemName);
        } else {
            self::clearHand($hand);
        }

        // Check if item is noteworthy (weapons, drinks, valuables, etc)
        $isNoteworthy = self::isNoteworthyItem($itemName);

        // Build context message
        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";
        if ($action === 'pickup') {
            $message = "{$playerName} picked up {$itemName} with their {$hand} hand";
        } else {
            $message = "{$playerName} put down {$itemName}";
        }

        // Add item category context for noteworthy items
        if ($isNoteworthy) {
            $category = self::getItemCategory($itemName);
            $message .= " ({$category})";
        }

        // Wrap in XML tag
        $formattedMessage = "<VR_ITEM>\n" . $message . "\n</VR_ITEM>";

        // Rewrite the game request to use the appropriate prompt
        $promptKey = ($action === 'pickup') ? 'ext_vr_item_pickup' : 'ext_vr_item_drop';
        $gameRequest[0] = $promptKey;
        $gameRequest[3] = $formattedMessage;

        error_log("[AIAGENTNSFW] VR Item: {$action} - {$itemName} (noteworthy: " . ($isNoteworthy ? 'yes' : 'no') . ")");
    }

    /**
     * Process raw VR item event from PSC
     * Returns: [promptKey, formattedMessage]
     */
    public static function processItemEvent($rawData) {
        // Use ^ delimiter to avoid conflict with CHIM's pipe-delimited message format
        $parts = explode('^', $rawData);

        if (count($parts) < 3) {
            error_log("[VR Items] Invalid raw data: " . $rawData);
            return null;
        }

        $itemName = $parts[0];
        $action = $parts[1]; // pickup, drop
        $handSide = $parts[2]; // left, right

        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";

        // Build message
        if ($action === 'pickup') {
            $message = "{$playerName} picked up {$itemName} with their {$handSide} hand";
            $promptKey = 'ext_vr_item_pickup';
        } else {
            $message = "{$playerName} put down {$itemName}";
            $promptKey = 'ext_vr_item_drop';
        }

        // Wrap in XML
        $formattedMessage = "<VR_ITEM>\n" . $message . "\n</VR_ITEM>";

        return [
            'promptKey' => $promptKey,
            'message' => $formattedMessage,
            'itemName' => $itemName,
            'action' => $action,
            'hand' => $handSide
        ];
    }

    /**
     * Check if item is noteworthy (worth mentioning to NPCs)
     * Can be used to filter out mundane items
     */
    public static function isNoteworthyItem($itemName) {
        // Items that are generally noteworthy
        $noteworthy = [
            'weapon' => ['sword', 'axe', 'mace', 'dagger', 'bow', 'staff', 'warhammer', 'greatsword'],
            'drink' => ['wine', 'ale', 'mead', 'beer', 'brandy', 'whiskey', 'skooma'],
            'food' => ['bread', 'cheese', 'apple', 'meat', 'sweetroll'],
            'valuable' => ['gold', 'gem', 'jewel', 'amulet', 'ring', 'necklace', 'crown'],
            'potion' => ['potion', 'elixir', 'philter'],
            'book' => ['book', 'tome', 'journal', 'scroll'],
            'key' => ['key'],
        ];

        $itemLower = strtolower($itemName);

        foreach ($noteworthy as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($itemLower, $keyword) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get item category for context
     */
    public static function getItemCategory($itemName) {
        $itemLower = strtolower($itemName);

        // Check categories
        if (preg_match('/(sword|axe|mace|dagger|bow|staff|warhammer|greatsword)/i', $itemLower)) {
            return 'weapon';
        }
        if (preg_match('/(wine|ale|mead|beer|brandy|skooma)/i', $itemLower)) {
            return 'drink';
        }
        if (preg_match('/(bread|cheese|apple|meat|sweetroll|food)/i', $itemLower)) {
            return 'food';
        }
        if (preg_match('/(gold|gem|jewel|amulet|ring|necklace|crown)/i', $itemLower)) {
            return 'valuable';
        }
        if (preg_match('/(potion|elixir|philter)/i', $itemLower)) {
            return 'potion';
        }
        if (preg_match('/(book|tome|journal|scroll)/i', $itemLower)) {
            return 'book';
        }
        if (preg_match('/key/i', $itemLower)) {
            return 'key';
        }

        return 'item';
    }

    /**
     * Build context for item interaction
     * Can be used to add item-specific context to prompts
     */
    public static function buildItemContext($itemName, $action, $hand) {
        $category = self::getItemCategory($itemName);

        $context = "<VR_ITEM_CONTEXT>\n";
        $context .= "Item: {$itemName}\n";
        $context .= "Category: {$category}\n";
        $context .= "Action: {$action}\n";
        $context .= "Hand: {$hand}\n";

        // Add category-specific context
        switch ($category) {
            case 'weapon':
                $context .= "The player has armed themselves.\n";
                break;
            case 'drink':
                $context .= "The player is holding a beverage. They may offer it or drink it.\n";
                break;
            case 'food':
                $context .= "The player is holding food. They may offer it or eat it.\n";
                break;
            case 'valuable':
                $context .= "The player is holding something valuable.\n";
                break;
            case 'potion':
                $context .= "The player is holding a potion.\n";
                break;
        }

        $context .= "</VR_ITEM_CONTEXT>";

        return $context;
    }
}

// Add raw item handler prompt to PROMPTS if not already defined
if (!isset($GLOBALS["PROMPTS"]["ext_vr_item_raw"])) {
    $GLOBALS["PROMPTS"]["ext_vr_item_raw"] = [
        "cue" => [""],  // Will be replaced by VRItems handler
        "player_request" => [""]
    ];
}
