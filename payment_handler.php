<?php
/**
 * Payment Handler for NSFW Extension
 * Handles gold and item transactions for prostitution services
 *
 * Uses SkyrimCommandBuilder to execute Papyrus commands
 */

require_once(__DIR__ . '/../../lib/scriptproxy_papyrus.php');
require_once(__DIR__ . '/nsfw_data.php');  // NsfwNpcData class for per-NPC NSFW storage

define('GOLD_FORM_ID', '0x0000000F'); // Skyrim Gold form ID

class PaymentHandler {

    private $skyrim;
    private $db;

    public function __construct() {
        $this->skyrim = new SkyrimCommandBuilder();
        $this->db = $GLOBALS['db'];
    }

    /**
     * Process a payment from player to NPC
     *
     * @param string $npcName The NPC receiving payment
     * @param int $amount The gold amount
     * @param string $paymentType Type of payment (gold, favors, goods, mixed)
     * @param string $serviceType What service was purchased
     * @return array Result with success status and message
     */
    public function processPayment($npcName, $amount, $paymentType = 'gold', $serviceType = 'service') {

        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Invalid payment amount'];
        }

        // Get NPC refid
        $npcRefId = $this->getNpcRefId($npcName);

        if (!$npcRefId) {
            // NPC not found in database, log but continue (gold just removed from player)
            error_log("PaymentHandler: Could not find refid for NPC: $npcName");
        }

        $result = ['success' => true, 'transactions' => []];

        switch ($paymentType) {
            case 'gold':
                $result = $this->transferGold(PLAYER_REFID, $npcRefId, $amount);
                break;

            case 'favors':
                // Favors don't involve gold transfer, just record the debt
                $result = $this->recordFavorDebt($npcName, $amount, $serviceType);
                break;

            case 'goods':
                // For goods, we'd need to know what items - for now just record
                $result = $this->recordGoodsPayment($npcName, $amount, $serviceType);
                break;

            case 'mixed':
                // Mixed payment - half gold, half favor
                $goldAmount = (int)floor($amount / 2);
                $favorAmount = $amount - $goldAmount;

                if ($goldAmount > 0) {
                    $goldResult = $this->transferGold(PLAYER_REFID, $npcRefId, $goldAmount);
                    $result['transactions'][] = $goldResult;
                }

                if ($favorAmount > 0) {
                    $favorResult = $this->recordFavorDebt($npcName, $favorAmount, $serviceType);
                    $result['transactions'][] = $favorResult;
                }
                break;
        }

        // Log the transaction
        $this->logTransaction($npcName, $amount, $paymentType, $serviceType, $result['success']);

        return $result;
    }

    /**
     * Transfer gold from one entity to another
     */
    private function transferGold($fromRefId, $toRefId, $amount) {
        error_log("PaymentHandler: refusing direct PHP gold transfer; Papyrus TakeGold must verify player gold first");
        return [
            'success' => false,
            'from' => $fromRefId,
            'to' => $toRefId,
            'amount' => $amount,
            'error' => 'Gold transfer requires Papyrus GetItemCount/RemoveItem confirmation via TakeGold',
        ];
    }

    /**
     * Record a favor debt (non-gold payment)
     */
    private function recordFavorDebt($npcName, $value, $serviceType) {
        // Store in NPC's NSFW data (nsfw_npc_data table)
        $extended = NsfwNpcData::get($npcName);

        if (!isset($extended['favor_debts'])) {
            $extended['favor_debts'] = [];
        }

        $extended['favor_debts'][] = [
            'value' => $value,
            'service' => $serviceType,
            'timestamp' => time(),
            'collected' => false
        ];

        NsfwNpcData::save($npcName, $extended);

        return [
            'success' => true,
            'type' => 'favor',
            'value' => $value,
            'message' => "Player owes $npcName a favor worth $value gold"
        ];
    }

    /**
     * Record a goods payment
     */
    private function recordGoodsPayment($npcName, $value, $serviceType) {
        // Store in NPC's NSFW data (nsfw_npc_data table)
        $extended = NsfwNpcData::get($npcName);

        if (!isset($extended['goods_owed'])) {
            $extended['goods_owed'] = [];
        }

        $extended['goods_owed'][] = [
            'value' => $value,
            'service' => $serviceType,
            'timestamp' => time(),
            'delivered' => false
        ];

        NsfwNpcData::save($npcName, $extended);

        return [
            'success' => true,
            'type' => 'goods',
            'value' => $value,
            'message' => "Player owes $npcName goods worth $value gold"
        ];
    }

    /**
     * Calculate total price for a session based on acts performed
     *
     * @param string $npcName The NPC
     * @param array $acts Array of act identifiers that occurred
     * @param string $bookingType 'per_act' or time-based ('time_1hr', etc)
     * @param array $addons Array of addon identifiers
     * @param int $groupSize Number of participants (1 = solo, 2+ = group)
     * @return array Price breakdown
     */
    public function calculateSessionPrice($npcName, $acts = [], $bookingType = 'per_act', $addons = [], $groupSize = 1) {

        // DEPRECATION CANDIDATE (user directive 2026-06-29): the elaborate per-act / style-addon / group-premium /
        // time-booking pricing below is only half-wired and rarely invoked - and now that NPCs initiate their own
        // scenes, it is largely moot. Likely to be REMOVED in favour of a single per-NPC minimum rate, and the
        // pricing form pulled from the UI. Do NOT invest further here without revisiting that decision first.

        // Get NPC pricing data
        $pricing = $this->getNpcPricing($npcName);

        if (!$pricing) {
            return ['success' => false, 'error' => 'NPC has no pricing configured'];
        }

        $breakdown = [
            'base' => 0,
            'acts' => [],
            'addons' => [],
            'group_premium' => 0,
            'total' => 0
        ];

        // If time-based booking, use flat rate
        if ($bookingType !== 'per_act' && isset($pricing['time_bookings'][$bookingType])) {
            $breakdown['base'] = $pricing['time_bookings'][$bookingType];
            $breakdown['booking_type'] = $bookingType;
        } else {
            // Calculate per-act pricing
            foreach ($acts as $act) {
                if (isset($pricing['individual_acts'][$act])) {
                    $actPrice = $pricing['individual_acts'][$act];
                    $breakdown['acts'][$act] = $actPrice;
                    $breakdown['base'] += $actPrice;
                }
            }
        }

        // Add style addons
        foreach ($addons as $addon) {
            if (isset($pricing['style_addons'][$addon])) {
                $addonPrice = $pricing['style_addons'][$addon];
                $breakdown['addons'][$addon] = $addonPrice;
            }
        }

        // Add group premium
        if ($groupSize >= 2) {
            if ($groupSize == 2 && isset($pricing['group_premiums']['group_threesome'])) {
                $breakdown['group_premium'] = $pricing['group_premiums']['group_threesome'];
            } elseif ($groupSize == 3 && isset($pricing['group_premiums']['group_foursome'])) {
                $breakdown['group_premium'] = $pricing['group_premiums']['group_foursome'];
            } elseif ($groupSize >= 4 && isset($pricing['group_premiums']['group_orgy'])) {
                $breakdown['group_premium'] = $pricing['group_premiums']['group_orgy'];
            }
        }

        // Calculate total
        $breakdown['total'] = $breakdown['base']
            + array_sum($breakdown['addons'])
            + $breakdown['group_premium'];

        $breakdown['success'] = true;

        return $breakdown;
    }

    /**
     * Get NPC's pricing configuration
     */
    public function getNpcPricing($npcName) {
        // Get from nsfw_npc_data table (NOT core_npc_master.extended_data)
        $extended = NsfwNpcData::get($npcName);
        return $extended['prostitute_pricing'] ?? null;
    }

    /**
     * Get NPC refid from database
     */
    private function getNpcRefId($npcName) {
        // Try combined_bio_templates first
        $result = $this->db->fetchOne(
            "SELECT refid FROM combined_bio_templates WHERE npc_name = :name",
            ['name' => $npcName]
        );

        if ($result && !empty($result['refid'])) {
            return $result['refid'];
        }

        // Try nsfw_npc_data for stored refid
        $extended = NsfwNpcData::get($npcName);
        if (!empty($extended['refid'])) {
            return $extended['refid'];
        }

        return null;
    }

    /**
     * Log a transaction to eventlog
     */
    private function logTransaction($npcName, $amount, $paymentType, $serviceType, $success) {
        $this->db->insert('eventlog', [
            'gamets' => $GLOBALS["gameRequest"][2] ?? time(),
            'localts' => time(),
            'type' => 'nsfw_payment',
            'data' => json_encode([
                'npc' => $npcName,
                'amount' => $amount,
                'payment_type' => $paymentType,
                'service' => $serviceType,
                'success' => $success
            ])
        ]);
    }

    /**
     * Get transaction history for an NPC
     */
    public function getTransactionHistory($npcName, $limit = 20) {
        return $this->db->fetchAll(
            "SELECT * FROM eventlog WHERE type = 'nsfw_payment' AND (data::jsonb)->>'npc' = :name ORDER BY localts DESC LIMIT :limit",
            ['name' => $npcName, 'limit' => $limit]
        );
    }
}
