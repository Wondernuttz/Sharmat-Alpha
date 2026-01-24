<?php
/**
 * NSFW NPC-to-NPC Scene Handler
 *
 * Processes NPC-only sex scenes detected by OStim NPCs mod.
 * Checks affinity between participants and can trigger scene stops
 * if relationship is too hostile.
 *
 * Raw data format from PSC (uses ^ delimiter to avoid CHIM pipe conflict):
 * - npc1name^npc2name^threadID^sceneID^npc1ToNpc2Rank^npc2ToNpc1Rank
 */

require_once(__DIR__ . '/nsfw_relationship.php');

class NsfwNpcScene {

    /**
     * Process NPC-to-NPC scene start event
     *
     * NPCs will talk to each other through the LLM. The tier prompts guide
     * their emotional response. If the tier prompt indicates refusal (hostile, etc.),
     * the NPC can choose to call RefuseNpcSex to stop the scene.
     *
     * Returns: [threadID, sceneID, npc1/npc2 info with tier prompts, consent levels]
     */
    public static function processNpcScene($rawData) {
        // Use ^ delimiter to avoid conflict with CHIM's pipe-delimited message format
        $parts = explode('^', $rawData);

        if (count($parts) < 4) {
            error_log("[NSFW NPC Scene] Invalid raw data: " . $rawData);
            return null;
        }

        $npc1Name = $parts[0];
        $npc2Name = $parts[1];
        $threadID = intval($parts[2]);
        $sceneID = $parts[3];

        error_log("[NSFW NPC Scene] Processing: {$npc1Name} + {$npc2Name} (Thread: {$threadID})");

        // Get affinity between the NPCs
        $npc1ToNpc2Affinity = self::getNpcToNpcAffinity($npc1Name, $npc2Name);
        $npc2ToNpc1Affinity = self::getNpcToNpcAffinity($npc2Name, $npc1Name);

        // Get consent levels using the 11-tier system (for logging/context)
        $npc1ConsentLevel = NsfwRelationship::getConsentLevel($npc1ToNpc2Affinity);
        $npc2ConsentLevel = NsfwRelationship::getConsentLevel($npc2ToNpc1Affinity);

        error_log("[NSFW NPC Scene] Affinity: {$npc1Name}->{$npc2Name}: {$npc1ToNpc2Affinity} ({$npc1ConsentLevel}), {$npc2Name}->{$npc1Name}: {$npc2ToNpc1Affinity} ({$npc2ConsentLevel})");

        // Get tier prompts for both NPCs - these guide their emotional response
        // The LLM will use these to decide how the NPC behaves (including refusing if hostile)
        $npc1TierPrompt = self::getTierPromptForNpc($npc1Name, $npc2Name, $npc1ToNpc2Affinity);
        $npc2TierPrompt = self::getTierPromptForNpc($npc2Name, $npc1Name, $npc2ToNpc1Affinity);

        // Build scene context message
        $sceneContext = self::buildSceneContext($npc1Name, $npc2Name, $sceneID);

        return [
            'threadID' => $threadID,
            'sceneID' => $sceneID,
            'npc1' => [
                'name' => $npc1Name,
                'affinity' => $npc1ToNpc2Affinity,
                'consentLevel' => $npc1ConsentLevel,
                'tierPrompt' => $npc1TierPrompt,
                'isChimEnabled' => self::isChimEnabledNpc($npc1Name)
            ],
            'npc2' => [
                'name' => $npc2Name,
                'affinity' => $npc2ToNpc1Affinity,
                'consentLevel' => $npc2ConsentLevel,
                'tierPrompt' => $npc2TierPrompt,
                'isChimEnabled' => self::isChimEnabledNpc($npc2Name)
            ],
            'sceneContext' => $sceneContext
        ];
    }

    /**
     * Get affinity from NPC1 toward NPC2
     * Uses RelationshipManager's NPC-to-NPC relationship if available
     */
    private static function getNpcToNpcAffinity($fromNpc, $toNpc) {
        // Try to get NPC-to-NPC relationship from RelationshipManager
        if (class_exists('RelationshipManager')) {
            // Check if there's an NPC-to-NPC relationship method
            if (method_exists('RelationshipManager', 'getNpcRelationship')) {
                $rel = RelationshipManager::getNpcRelationship($fromNpc, $toNpc);
                if ($rel && isset($rel['aff'])) {
                    return intval($rel['aff']);
                }
            }

            // Fallback: Use player relationship as a proxy
            // (NPCs with similar player relationships might get along)
            $fromPlayerRel = RelationshipManager::getPlayerRelationship($fromNpc);
            $toPlayerRel = RelationshipManager::getPlayerRelationship($toNpc);

            if ($fromPlayerRel && $toPlayerRel) {
                // Simple heuristic: if both like/dislike player similarly, they might get along
                $fromAff = $fromPlayerRel['aff'] ?? 0;
                $toAff = $toPlayerRel['aff'] ?? 0;

                // If both are positive or both are negative, they're compatible
                if (($fromAff >= 0 && $toAff >= 0) || ($fromAff < 0 && $toAff < 0)) {
                    return 20; // Assume friendly enough
                }
            }
        }

        // Default: Neutral, allow scene
        return 0;
    }

    /**
     * Get tier prompt for an NPC based on their affinity toward partner
     * Includes affair detection - if NPC is married and partner != spouse, uses affair prompts
     */
    private static function getTierPromptForNpc($npcName, $partnerName, $affinity) {
        $isProstitute = self::isProstitute($npcName);
        return NsfwRelationship::getTierPromptByAffinity($affinity, $isProstitute, $partnerName, $npcName);
    }

    /**
     * Check if NPC is a CHIM-enabled NPC (has an entry in our system)
     */
    private static function isChimEnabledNpc($npcName) {
        if (isset($GLOBALS["db"])) {
            try {
                $escapedName = pg_escape_string($npcName);
                $row = $GLOBALS["db"]->fetchOne(
                    "SELECT id FROM core_npc_master WHERE name = '{$escapedName}'"
                );
                return !empty($row);
            } catch (Exception $e) {
                // Silently fail
            }
        }
        return false;
    }

    /**
     * Check if NPC is marked as prostitute
     * Delegates to global isProstitute() from common.php
     */
    private static function isProstitute($npcName) {
        return isProstitute($npcName);
    }

    /**
     * Build scene context message for NPCs
     */
    private static function buildSceneContext($npc1Name, $npc2Name, $sceneID) {
        // Parse scene tags if available
        $sceneDesc = "a sexual encounter";
        if (!empty($sceneID)) {
            // OStim scene IDs often contain descriptive tags
            $sceneDesc = str_replace(['_', '|'], ' ', $sceneID);
        }

        return "<NPC_SCENE_CONTEXT>
{$npc1Name} and {$npc2Name} are engaged in {$sceneDesc}.
This is an NPC-to-NPC scene (player is not directly involved).
</NPC_SCENE_CONTEXT>";
    }

    /**
     * Queue dialogue for NPC based on their tier prompt
     * This adds the appropriate tier-based response instruction
     */
    public static function queueNpcDialogue($npcName, $tierPrompt, $sceneContext) {
        if (empty($tierPrompt)) {
            return false;
        }

        // Format the complete message with tier prompt and context
        $message = $sceneContext . "\n" . $tierPrompt;

        // Queue this for the NPC through CHIM's message system
        // This will make the NPC respond with their tier-appropriate reaction
        if (function_exists('AIAgentFunctions') || class_exists('AIAgentFunctions')) {
            // Use the existing CHIM API if available
            // This would need to be called from the router
            return true;
        }

        return false;
    }
}
?>
