<?php
/**
 * ============================================================================
 * NSFW Physics Handler - VR Touch/Grope Detection
 * ============================================================================
 *
 * MIGRATION STATUS: ❌ NSFW ONLY - DO NOT MOVE TO CORE
 * NSFW CONTENT: ✅ YES - Body part groping, sexual touch, penetration
 *
 * This file handles sexually explicit VR touch detection and should remain
 * in the NSFW extension only.
 *
 * ============================================================================
 * DEPENDENCIES:
 * ============================================================================
 * - nsfw_relationship.php (tier prompts for grope responses)
 * - NpcMaster class (from core CHIM)
 * - getIntimacyForActor() / updateIntimacyForActor() (from this ext)
 *
 * ============================================================================
 * WHAT THIS DOES:
 * ============================================================================
 * - Detects VR controller touches on NPC body parts (CBPC mod)
 * - Detects intentional grabs (HIGGS mod grip button)
 * - Distinguishes accidental touch vs intentional grope
 * - Injects tier-based relationship prompts for sensitive grabs
 * - Handles penetration detection for sex acts
 *
 * MESSAGE DISTINCTION:
 * - Touch (CBPC): "accidentally brushed" - could be incidental contact
 * - Grab (HIGGS): "groped" - INTENTIONAL action
 * - Penetration: Explicit sexual act language
 *
 * Raw data format from PSC (uses ^ delimiter to avoid CHIM pipe conflict):
 * - Touch: actor^bodypart^touch^blocked^blockedby^penetration^playersex
 * - Grab:  actor^bodypart^grab^blocked^blockedby^hand^helditem
 * - Release: actor^bodypart^release^duration^hand
 *
 * USAGE:
 *   NsfwPhysics::processEvent()       - Main entry point (handles gameRequest)
 *   NsfwPhysics::processPhysicsEvent() - Low-level message formatting only
 * ============================================================================
 */

// Include Logger for CHIM log output
require_once(__DIR__ . '/../../lib/logger.php');

class NsfwPhysics {

    // Sexually sensitive body parts - used for message formatting
    private static $sensitiveParts = ['Breasts', 'Butt', 'Pussy', 'Anal', 'Penis'];

    /**
     * Main entry point - Process VR physics events (HIGGS grab, CBPC touch)
     * SENSITIVE GRAB = triggers tier prompt immediately (same as scene start)
     * Pattern: Slave → Prostitute → Affair → Regular
     *
     * This is the orchestration method called from common.php wrapper.
     * Handles gameRequest rewriting and tier prompt injection.
     */
    public static function processEvent() {
        global $gameRequest;

        // Handle both raw AND already-processed physics events
        // Raw events need full processing; processed events just need tier prompt injection
        $isRawEvent = ($gameRequest[0] == "ext_nsfw_physics_raw");
        $isProcessedEvent = ($gameRequest[0] == "ext_nsfw_physics");

        if (!$isRawEvent && !$isProcessedEvent) {
            return;
        }

        // For raw events: process and rewrite (fallback if preprocessing didn't handle it)
        if ($isRawEvent) {
            $rawData = $gameRequest[3] ?? '';
            if (empty($rawData)) {
                return;
            }

            $result = self::processPhysicsEvent($rawData);
            if (!$result) {
                return;
            }

            // Rewrite the game request
            $gameRequest[0] = "ext_nsfw_physics";
            $gameRequest[3] = $result['message'];

            // Update PROMPTS array if it exists
            if (isset($GLOBALS["PROMPTS"]["ext_nsfw_physics"])) {
                $GLOBALS["PROMPTS"]["ext_nsfw_physics"]["player_request"] = [$result['message']];
            }

            // LOG TO CHIM
            $action = $result['action'];
            $actorName = $result['actorName'];
            $bodyPart = $result['bodyPart'] ?? 'Body';
            $verb = ($action === 'grab') ? 'grabbed' : 'touched';
            Logger::info("[VR] Player {$verb} {$actorName}'s {$bodyPart}");

            // Inject tier prompt for sensitive grabs
            $isSensitive = $result['isSensitive'] ?? false;
            $isBlocked = $result['isBlocked'] ?? false;
            if ($action === 'grab' && $isSensitive && !$isBlocked) {
                self::injectTierPromptForGrab($result);
            }
        }
        // For already-processed events: just update the prompt (preprocessing already did the rewrite)
        else if ($isProcessedEvent) {
            // The message is already in gameRequest[3], just ensure PROMPTS is updated
            if (isset($GLOBALS["PROMPTS"]["ext_nsfw_physics"])) {
                $GLOBALS["PROMPTS"]["ext_nsfw_physics"]["player_request"] = [$gameRequest[3]];
            }

            // For tier prompt injection on processed events, we need to re-parse the message
            // to determine if it was a sensitive grab. Check for grab verbs in the message.
            $message = $gameRequest[3] ?? '';
            $isSensitiveGrab = (
                strpos($message, 'groped') !== false ||
                strpos($message, 'grabbed between the legs') !== false ||
                strpos($message, 'grabbed the ass') !== false ||
                strpos($message, 'grabbed the crotch') !== false
            );

            if ($isSensitiveGrab) {
                // Extract actor name from message format "Player groped ActorName's BodyPart"
                if (preg_match("/Player (?:groped|grabbed[^']+) ([^']+)'s/", $message, $matches)) {
                    $actorName = trim($matches[1]);
                    // Create minimal result for tier prompt injection
                    $result = [
                        'actorName' => $actorName,
                        'action' => 'grab',
                        'isSensitive' => true,
                        'isBlocked' => false
                    ];
                    self::injectTierPromptForGrab($result);
                }
            }
        }
    }

    /**
     * Inject tier prompt for sensitive grab events
     * Separated for clarity and testability
     */
    private static function injectTierPromptForGrab($result) {
        error_log("[AIAGENTNSFW] Sensitive grab detected on {$result['bodyPart']} - triggering tier prompt");

        $actor = $GLOBALS["HERIKA_NAME"];
        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";

        // Load NPC data for type detection
        $npcManager = new NpcMaster();
        $npcData = $npcManager->getByName($actor);
        // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
        require_once __DIR__ . "/nsfw_data.php";
        $extended_data = NsfwNpcData::get($actor);
        $metadata = $npcManager->getMetadata($npcData);

        // Detect NPC type (same logic as processInfoSexScene)
        $isSlave = isNpcSlave($actor);

        $isCourtesan = false;
        $modsToCheck = ["The Naked DragonSSE.esp", "prostitutes.esp"];
        if (is_array($metadata["mods"])) {
            foreach ($modsToCheck as $mod) {
                $isCourtesan = $isCourtesan || in_array($mod, $metadata["mods"]);
            }
        }
        $isProstitute = !empty($extended_data['is_prostitute']) ||
                        !empty($extended_data['profession_prostitute']) ||
                        $isCourtesan;

        // Get affinity for tier selection
        $affinity = getNpcAffinity($actor);

        // ============================================
        // TIER PROMPT SELECTION - Priority Order:
        // 1. Slave → Slave tier prompts (comply, emotions vary)
        // 2. Prostitute → Prostitute tier prompts
        // 3. Affair (married + non-spouse) → Marriage tier prompts
        // 4. Regular → Regular tier prompts
        // ============================================
        $tierPrompt = null;

        if ($isSlave) {
            $tierPrompt = NsfwRelationship::getSlaveTierPrompt($affinity, $playerName);
            error_log("[AIAGENTNSFW] Slave grab - using slave tier prompt");
        } elseif ($isProstitute) {
            $tierPrompt = NsfwRelationship::getTierPromptByAffinity($affinity, true, $playerName, $actor);
            error_log("[AIAGENTNSFW] Prostitute grab - using prostitute tier prompt");
        } else {
            // Regular path - getTierPromptByAffinity handles affair detection internally
            $tierPrompt = NsfwRelationship::getTierPromptByAffinity($affinity, false, $playerName, $actor);

            // Log whether it's an affair or regular
            if (NsfwRelationship::isAffairScenario($actor, $playerName)) {
                error_log("[AIAGENTNSFW] Affair grab - using marriage tier prompt");
            } else {
                error_log("[AIAGENTNSFW] Regular grab - using regular tier prompt");
            }
        }

        // Inject tier prompt immediately
        if (!empty($tierPrompt)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $tierPrompt;
            error_log("[AIAGENTNSFW] IMMEDIATE tier prompt injection for $actor on sensitive grab");
        }

        // Set up intimacy state for scene flow
        $intimacyStatus = getIntimacyForActor($actor);
        $intimacyStatus["level"] = 0;
        $intimacyStatus["scene_phase"] = "tier_prompt";
        $intimacyStatus["scene_start_time"] = time();

        // For slaves and prostitutes, auto-accept
        if ($isSlave || $isProstitute) {
            $intimacyStatus["scene_phase"] = "accepted";
            error_log("[AIAGENTNSFW] Auto-accepting for $actor (slave/prostitute) on grab");
        } else {
            $intimacyStatus["tier_prompt_sent"] = true;
        }

        updateIntimacyForActor($actor, $intimacyStatus);
    }

    /**
     * Process raw physics event from PSC
     * Returns metadata for processInfoPhysics() to handle tier prompts
     */
    public static function processPhysicsEvent($rawData) {
        // Use ^ delimiter to avoid conflict with CHIM's pipe-delimited message format
        $parts = explode('^', $rawData);

        if (count($parts) < 4) {
            error_log("[NSFW Physics] Invalid raw data: " . $rawData);
            return null;
        }

        $action = $parts[2]; // touch, grab, release

        // Route to appropriate handler
        switch ($action) {
            case 'touch':
                return self::handleTouch($parts);
            case 'grab':
                return self::handleGrab($parts);
            case 'release':
                return self::handleRelease($parts);
            default:
                error_log("[NSFW Physics] Unknown action: " . $action);
                return null;
        }
    }

    /**
     * Handle CBPC touch event
     * Touch = could be accidental (brush, bump)
     * Format: actor^bodypart^touch^blocked^blockedby^penetration^playersex
     *
     * Returns metadata - tier prompts handled by processInfoPhysics()
     */
    private static function handleTouch($parts) {
        $actorName = $parts[0];
        $bodyPart = $parts[1];
        $isBlocked = filter_var($parts[3] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $blockedBy = $parts[4] ?? '';
        $isPenetration = filter_var($parts[5] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $playerSex = intval($parts[6] ?? 0);

        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";
        $isSensitive = self::isSensitiveBodyPart($bodyPart);

        // Build message with appropriate language
        $message = self::buildTouchMessage($playerName, $actorName, $bodyPart, $isBlocked, $blockedBy, $isPenetration, $playerSex);

        // Wrap in appropriate XML tag
        if ($isPenetration && !$isBlocked) {
            $formattedMessage = "<SEXUAL_ACT>\n" . $message . "\n</SEXUAL_ACT>";
        } else {
            $formattedMessage = "<PHYSICS_INFO>\n" . $message . "\n</PHYSICS_INFO>";
        }

        // Return metadata - processInfoPhysics() handles tier prompts
        return [
            'message' => $formattedMessage,
            'actorName' => $actorName,
            'bodyPart' => $bodyPart,
            'action' => 'touch',
            'isSensitive' => $isSensitive,
            'isPenetration' => $isPenetration,
            'isBlocked' => $isBlocked
        ];
    }

    /**
     * Handle HIGGS grab event
     * Grab = INTENTIONAL - player deliberately grabbed NPC
     * Format: actor^bodypart^grab^blocked^blockedby^hand^helditem
     *
     * Returns metadata - tier prompts handled by processInfoPhysics()
     */
    private static function handleGrab($parts) {
        $actorName = $parts[0];
        $bodyPart = $parts[1];
        $isBlocked = filter_var($parts[3] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $blockedBy = $parts[4] ?? '';
        $handSide = $parts[5] ?? 'right';
        $heldItem = $parts[6] ?? '';  // What's in the OTHER hand

        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";
        $isSensitive = self::isSensitiveBodyPart($bodyPart);

        // Check if we're in an active scene (for context-aware messages)
        $sceneActiveFile = sys_get_temp_dir() . "/nsfw_scene_active.txt";
        $sceneActive = @file_get_contents($sceneActiveFile);
        $inScene = ($sceneActive !== false && (time() - (int)$sceneActive) < 600);

        // Build message - GRAB is INTENTIONAL (unlike touch which could be accidental)
        if ($isBlocked) {
            $message = "{$playerName} tried to grab {$actorName}'s {$bodyPart} but was prevented by the {$blockedBy}";
        } else {
            // Intentional grab - use stronger language for sensitive areas
            if ($isSensitive) {
                $grabVerb = self::getSensitiveGrabVerb($bodyPart);
                $message = "{$playerName} {$grabVerb} {$actorName}'s {$bodyPart}";
            } elseif ($bodyPart === 'Head' && $inScene) {
                // During scene: head grab = pulling hair
                $message = "{$playerName} grabbed {$actorName}'s hair and pulled";
            } else {
                $message = "{$playerName} grabbed {$actorName}'s {$bodyPart}";
            }
        }

        // Add held item context if player is holding something in other hand
        if (!empty($heldItem) && !$isBlocked) {
            $message .= " (while holding {$heldItem} in other hand)";
        }

        // Wrap in appropriate XML tag
        if ($isSensitive && !$isBlocked) {
            $formattedMessage = "<SEXUAL_GROPE>\n" . $message . "\n</SEXUAL_GROPE>";
        } else {
            $formattedMessage = "<PHYSICS_INFO>\n" . $message . "\n</PHYSICS_INFO>";
        }

        // Return metadata - processInfoPhysics() handles tier prompts
        return [
            'message' => $formattedMessage,
            'actorName' => $actorName,
            'bodyPart' => $bodyPart,
            'action' => 'grab',
            'isSensitive' => $isSensitive,
            'isBlocked' => $isBlocked,
            'heldItem' => $heldItem
        ];
    }

    /**
     * Get appropriate verb for sensitive body part grabs
     * Makes the action description more explicit/sexual
     */
    private static function getSensitiveGrabVerb($bodyPart) {
        switch ($bodyPart) {
            case 'Breasts':
                return 'groped';
            case 'Butt':
                return 'grabbed';
            case 'Pussy':
                return 'grabbed between the legs of';
            case 'Anal':
                return 'grabbed the ass of';
            case 'Penis':
                return 'grabbed the crotch of';
            default:
                return 'grabbed';
        }
    }

    /**
     * Handle HIGGS release event
     * Release = just event info, no tier prompt
     * Format: actor^bodypart^release^duration^hand
     */
    private static function handleRelease($parts) {
        $actorName = $parts[0];
        $bodyPart = $parts[1];
        $duration = floatval($parts[3] ?? 0);
        $handSide = $parts[4] ?? 'right';

        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";

        // Only report significant holds
        if ($duration < 1.0) {
            return null;
        }

        $durationInt = intval($duration);
        $message = "{$playerName} released {$actorName}'s {$bodyPart} after holding for {$durationInt} seconds";

        // Wrap in XML
        $formattedMessage = "<PHYSICS_INFO>\n" . $message . "\n</PHYSICS_INFO>";

        return [
            'message' => $formattedMessage,
            'actorName' => $actorName,
            'bodyPart' => $bodyPart,
            'action' => 'release',
            'duration' => $duration
        ];
    }

    /**
     * Check if body part is sexually sensitive
     */
    private static function isSensitiveBodyPart($bodyPart) {
        return in_array($bodyPart, self::$sensitiveParts);
    }

    /**
     * Build touch message based on context
     * TOUCH = could be accidental (brush, bump, incidental contact)
     * PENETRATION = sexual insertion
     */
    private static function buildTouchMessage($playerName, $actorName, $bodyPart, $isBlocked, $blockedBy, $isPenetration, $playerSex) {
        // PENETRATION - this is a sexual act, use explicit language
        if ($isPenetration) {
            if ($isBlocked) {
                return "{$playerName} tried to penetrate {$actorName} but was blocked by the {$blockedBy}";
            }

            // playerSex: 0 = male, 1 = female
            if ($playerSex == 0) {
                // Male player penetrating
                if ($bodyPart == 'Pussy') {
                    return "{$playerName} inserted his cock into {$actorName}'s pussy";
                } elseif ($bodyPart == 'Anal') {
                    return "{$playerName} inserted his cock into {$actorName}'s ass";
                } else {
                    return "{$playerName} penetrated {$actorName}'s {$bodyPart}";
                }
            } else {
                // Female player being penetrated
                if ($bodyPart == 'Penis') {
                    return "{$actorName} inserted their cock into {$playerName}";
                } else {
                    return "{$actorName}'s {$bodyPart} penetrated {$playerName}";
                }
            }
        }

        // BLOCKED touch attempt
        if ($isBlocked) {
            return "{$playerName} tried to touch {$actorName}'s {$bodyPart} but was prevented by the {$blockedBy}";
        }

        // REGULAR TOUCH - could be accidental
        // Use softer language that implies it might not be intentional
        $isSensitive = self::isSensitiveBodyPart($bodyPart);

        if ($isSensitive) {
            // Sensitive area touch - phrase as potentially accidental
            // This is different from GRAB which is intentional
            $touchVerb = self::getAccidentalTouchVerb($bodyPart);
            return "{$playerName} {$touchVerb} {$actorName}'s {$bodyPart}";
        } else {
            // Non-sensitive area - just a regular touch
            return "{$playerName} touched {$actorName}'s {$bodyPart}";
        }
    }

    /**
     * Get verb that implies touch could be accidental
     * Used for CBPC touches (not intentional grabs)
     */
    private static function getAccidentalTouchVerb($bodyPart) {
        switch ($bodyPart) {
            case 'Breasts':
                return 'accidentally brushed against';
            case 'Butt':
                return 'bumped into';
            case 'Pussy':
                return 'accidentally touched';
            case 'Anal':
                return 'accidentally touched';
            case 'Penis':
                return 'accidentally brushed against';
            default:
                return 'touched';
        }
    }
}
