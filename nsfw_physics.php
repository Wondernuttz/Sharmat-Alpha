<?php
/**
 * ============================================================================
 * NSFW Physics Handler - VR Touch/Grope Detection
 * ============================================================================
 *
 * MIGRATION STATUS: NSFW ONLY - DO NOT MOVE TO CORE
 * NSFW CONTENT: YES - Body part groping, sexual touch, penetration
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
 * - Injects tier-based relationship prompts for sexual-area contact
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
require_once(__DIR__ . '/contact_state.php');

class NsfwPhysics {

    // Sexual body areas used for message formatting. Keep isSensitive as the legacy event flag.
    private static $sensitiveParts = ['Breasts', 'Chest', 'Butt', 'Pussy', 'Anal', 'Penis'];
    private const LAST_CONTACT_TTL_SECONDS = 90;
    private const LAST_CONTACT_FILE_PREFIX = 'nsfw_last_physics_contact_';
    private const SUSTAINED_TOUCH_FILE_PREFIX = 'nsfw_sustained_touch_';
    private const VR_PHYSICS_LOG = 'sharmat_vr_physics.log';

    public static function getLastContactContext($actorName = null) {
        $actorName = trim((string)($actorName ?? ($GLOBALS["HERIKA_NAME"] ?? "")));
        if ($actorName === '') {
            return '';
        }

        if (function_exists('aiagentNsfwContactBuildContext')) {
            return aiagentNsfwContactBuildContext($actorName);
        }

        $path = self::lastContactPath($actorName);
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false || trim($raw) === '') {
            return '';
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return '';
        }

        $timestamp = intval($data['timestamp'] ?? 0);
        $age = time() - $timestamp;
        if ($timestamp <= 0 || $age > self::LAST_CONTACT_TTL_SECONDS) {
            return '';
        }

        $message = trim((string)($data['plain_message'] ?? ''));
        if ($message === '') {
            $message = self::stripPhysicsTags((string)($data['message'] ?? ''));
        }
        if ($message === '') {
            return '';
        }

        $action = trim((string)($data['action'] ?? 'contact'));
        $bodyPart = trim((string)($data['bodyPart'] ?? 'Body'));

        $context = "<physical_contact_state>\n";
        $context .= "# AUTHORITATIVE LIVE VR PHYSICAL CONTACT\n";
        $context .= "## This is the most recent HIGGS/CBPC physical contact state for {$actorName}. Use it over memory, guesses, relationship assumptions, scene narration, or visual assumptions when asked where the player touched/grabbed/released this NPC.\n";
        $context .= "## This is informational context only; do not treat it as a new action unless the current request is itself a physical-contact event.\n";
        $context .= "## {$message}\n";
        $context .= "## Last contact action: {$action}; body part: {$bodyPart}; age: {$age} seconds.\n";
        $context .= "</physical_contact_state>";

        return $context;
    }

    private static function saveLastContact($result) {
        if (!is_array($result) || empty($result['actorName']) || empty($result['message'])) {
            return;
        }

        $actorName = trim((string)$result['actorName']);
        if ($actorName === '') {
            return;
        }

        $payload = [
            'timestamp' => time(),
            'actorName' => $actorName,
            'action' => $result['action'] ?? 'contact',
            'bodyPart' => $result['bodyPart'] ?? 'Body',
            'isSensitive' => $result['isSensitive'] ?? false,
            'isBlocked' => $result['isBlocked'] ?? false,
            'duration' => $result['duration'] ?? null,
            'heldItem' => $result['heldItem'] ?? null,
            'handSide' => $result['handSide'] ?? null,
            'sustainedTouch' => !empty($result['sustainedTouch']),
            'sustainedSeconds' => $result['sustainedSeconds'] ?? null,
            'sustainedCount' => $result['sustainedCount'] ?? null,
            'sustainedPromptAllowed' => !empty($result['sustainedPromptAllowed']),
            'suppressModelRoute' => !empty($result['suppressModelRoute']),
            'message' => $result['message'],
            'plain_message' => self::stripPhysicsTags((string)$result['message'])
        ];

        @file_put_contents(self::lastContactPath($actorName), json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function stripPhysicsTags($message) {
        return trim(preg_replace('/\s+/', ' ', strip_tags((string)$message)));
    }

    private static function normalizeHandSide($handSide) {
        $hand = strtolower(trim((string)$handSide));
        if ($hand === '') {
            return '';
        }
        if (strpos($hand, 'left') !== false) {
            return 'left';
        }
        if (strpos($hand, 'right') !== false) {
            return 'right';
        }
        return $hand;
    }

    private static function handSideFromRawParts($action, $parts) {
        $action = strtolower(trim((string)$action));
        if ($action === 'grab' || $action === 'spank') {
            return self::normalizeHandSide($parts[5] ?? '');
        }
        if ($action === 'release') {
            return self::normalizeHandSide($parts[4] ?? '');
        }
        return '';
    }

    public static function logRawPhysicsEvent($rawData, $status = 'blocked', $reason = '') {
        $parts = explode('^', (string)$rawData);
        if (count($parts) < 3) {
            self::writePhysicsLog([
                'status' => $status,
                'reason' => $reason,
                'action' => 'invalid',
                'raw' => (string)$rawData,
            ]);
            return;
        }

        $actorName = trim((string)($parts[0] ?? ''));
        $rawBodyPart = self::normalizeBodyPart($parts[1] ?? 'Body');
        $bodyPart = self::displayBodyPartForActor($rawBodyPart, $actorName);
        $action = strtolower(trim((string)($parts[2] ?? '')));
        $isBlocked = filter_var($parts[3] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $handSide = self::handSideFromRawParts($action, $parts);

        self::writePhysicsLog([
            'status' => $status,
            'reason' => $reason,
            'actor' => $actorName,
            'action' => $action !== '' ? $action : 'unknown',
            'bodyPart' => $bodyPart,
            'rawBodyPart' => $rawBodyPart,
            'sexualArea' => self::isSensitiveBodyPart($rawBodyPart),
            'blocked' => $isBlocked,
            'blockedBy' => $parts[4] ?? '',
            'handSide' => $handSide !== '' ? $handSide : null,
            'speed' => isset($parts[7]) ? floatval($parts[7]) : null,
            'raw' => (string)$rawData,
        ]);
    }

    private static function logPhysicsResult($result, $rawData, $status = 'routed', $reason = '') {
        if (!is_array($result)) {
            self::logRawPhysicsEvent($rawData, $status, $reason);
            return;
        }

        self::writePhysicsLog([
            'status' => $status,
            'reason' => $reason,
            'actor' => $result['actorName'] ?? '',
            'action' => $result['action'] ?? 'unknown',
            'bodyPart' => $result['bodyPart'] ?? 'Body',
            'rawBodyPart' => $result['rawBodyPart'] ?? ($result['bodyPart'] ?? 'Body'),
            'sexualArea' => !empty($result['isSensitive']),
            'blocked' => !empty($result['isBlocked']),
            'penetration' => !empty($result['isPenetration']),
            'rawPenetration' => !empty($result['rawPenetration']),
            'penetrationRejected' => !empty($result['penetrationRejected']),
            'duration' => $result['duration'] ?? null,
            'heldItem' => $result['heldItem'] ?? null,
            'handSide' => $result['handSide'] ?? null,
            'sustainedTouch' => !empty($result['sustainedTouch']),
            'sustainedSeconds' => $result['sustainedSeconds'] ?? null,
            'sustainedCount' => $result['sustainedCount'] ?? null,
            'sustainedPromptAllowed' => !empty($result['sustainedPromptAllowed']),
            'touchSequenceAccumulating' => !empty($result['touchSequenceAccumulating']),
            'suppressModelRoute' => !empty($result['suppressModelRoute']),
            'speed' => $result['speed'] ?? null,
            'message' => self::stripPhysicsTags((string)($result['message'] ?? '')),
            'raw' => (string)$rawData,
        ]);
    }

    private static function writePhysicsLog($payload) {
        try {
            $payload['ts'] = date('Y-m-d H:i:s');
            $payload['localts'] = time();
            @file_put_contents(
                self::physicsLogPath(),
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (Exception $e) {
            error_log("[NSFW Physics] Could not write SHARMAT VR physics log: " . $e->getMessage());
        }
    }

    private static function physicsLogPath() {
        return __DIR__ . "/../../log/" . self::VR_PHYSICS_LOG;
    }

    private static function lastContactPath($actorName) {
        $key = strtolower(trim((string)$actorName));
        return sys_get_temp_dir() . "/" . self::LAST_CONTACT_FILE_PREFIX . md5($key) . ".json";
    }

    private static function sustainedTouchPath($actorName, $bodyPart) {
        $key = strtolower(trim((string)$actorName)) . '|' . strtolower(trim((string)$bodyPart));
        return sys_get_temp_dir() . "/" . self::SUSTAINED_TOUCH_FILE_PREFIX . md5($key) . ".json";
    }

    private static function normalizeBodyPart($bodyPart) {
        $raw = trim((string)$bodyPart);
        if ($raw === '') {
            return 'Body';
        }

        $key = strtolower($raw);
        switch ($key) {
            case 'breast':
            case 'breasts':
            case 'boob':
            case 'boobs':
                return 'Breasts';
            case 'chest':
            case 'pec':
            case 'pecs':
                return 'Chest';
            case 'butt':
            case 'ass':
            case 'rear':
            case 'bottom':
                return 'Butt';
            case 'pussy':
            case 'vagina':
            case 'vaginal':
            case 'pelvis':
                return 'Pussy';
            case 'anal':
            case 'anus':
                return 'Anal';
            case 'penis':
            case 'cock':
            case 'genitals':
            case 'crotch':
                return 'Penis';
            case 'head':
                return 'Head';
            case 'hair':
            case 'scalp':
                return 'Head';
            case 'face':
            case 'cheek':
            case 'cheeks':
            case 'jaw':
            case 'chin':
            case 'nose':
            case 'mouth':
            case 'lips':
            case 'forehead':
                return 'Face';
            case 'neck':
            case 'throat':
                return 'Neck';
            case 'body':
            case 'other':
                return 'Body';
            default:
                return ucfirst($key);
        }
    }

    private static function displayBodyPartForActor($bodyPart, $actorName) {
        $normalized = self::normalizeBodyPart($bodyPart);
        $gender = self::actorGender($actorName);
        if ($normalized === 'Breasts' && $gender === 'male') {
            return 'Chest';
        }
        if ($normalized === 'Chest' && $gender === 'female') {
            return 'Breasts';
        }
        if ($normalized === 'Pussy' && $gender === 'male') {
            return 'Penis';
        }
        if ($normalized === 'Penis' && $gender === 'female') {
            return 'Pussy';
        }
        return $normalized;
    }

    private static function actorGender($actorName) {
        try {
            if (!class_exists('NpcMaster')) {
                return '';
            }
            $npcManager = new NpcMaster();
            $npcData = $npcManager->getByName($actorName);
            $gender = strtolower(trim((string)($npcData['gender'] ?? '')));
            if (in_array($gender, ['m', 'man'], true) || strpos($gender, 'male') === 0) {
                return 'male';
            }
            if (in_array($gender, ['f', 'woman'], true) || strpos($gender, 'female') === 0) {
                return 'female';
            }
        } catch (Exception $e) {
            error_log("[NSFW Physics] Could not resolve actor gender for {$actorName}: " . $e->getMessage());
        }
        return '';
    }

    private static function actorLooksMale($actorName) {
        return self::actorGender($actorName) === 'male';
    }

    private static function touchBodyPartCanBePenetration($bodyPart, $playerSex) {
        $normalized = self::normalizeBodyPart($bodyPart);
        if ((int)$playerSex === 0) {
            return in_array($normalized, ['Pussy', 'Anal'], true);
        }
        return $normalized === 'Penis';
    }

    /**
     * Main entry point - Process VR physics events (HIGGS grab, CBPC touch)
     * Sexual-area contact can inject REL prompt context when allowed by the prompt gate.
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
            if (!$result || !empty($result['suppressModelRoute'])) {
                return;
            }

            $cleanMessage = function_exists('aiagentNsfwContactStripTags')
                ? aiagentNsfwContactStripTags($result['message'])
                : $result['message'];
            $eventLogMessage = $cleanMessage;
            if (function_exists('aiagentNsfwContactTag') && !empty($result['contactKey'])) {
                $eventLogMessage = aiagentNsfwContactTag($cleanMessage, $result['contactKey']);
            }

            // Rewrite the game request
            $gameRequest[0] = "ext_nsfw_physics";
            $gameRequest[3] = $eventLogMessage;

            // Update PROMPTS array if it exists
            if (isset($GLOBALS["PROMPTS"]["ext_nsfw_physics"])) {
                $GLOBALS["PROMPTS"]["ext_nsfw_physics"]["player_request"] = [$cleanMessage];
            }

            // LOG TO CHIM
            $action = $result['action'];
            $actorName = $result['actorName'];
            $bodyPart = $result['bodyPart'] ?? 'Body';
            $verb = ($action === 'grab') ? 'grabbed' : (($action === 'spank') ? 'smacked' : 'touched');
            Logger::info("[VR] Player {$verb} {$actorName}'s {$bodyPart}");

            // Inject VR Physics REL prompt for sexual area contact.
            if (self::shouldInjectVrPhysicsPrompt($result)) {
                self::injectVrPhysicsPrompt($result);
            }
        }
        // For already-processed events: just update the prompt (preprocessing already did the rewrite)
        else if ($isProcessedEvent) {
            // The message is already in gameRequest[3], just ensure PROMPTS is updated
            $cleanMessage = function_exists('aiagentNsfwContactStripTags')
                ? aiagentNsfwContactStripTags($gameRequest[3] ?? '')
                : ($gameRequest[3] ?? '');
            if (isset($GLOBALS["PROMPTS"]["ext_nsfw_physics"])) {
                $GLOBALS["PROMPTS"]["ext_nsfw_physics"]["player_request"] = [$cleanMessage];
            }

            // For prompt injection on processed events, re-parse the message enough to recover
            // sexual-area grab/spank events. Raw events keep better body-part data.
            $message = $cleanMessage;
            $isSpank = (
                strpos($message, 'slaps') !== false ||
                strpos($message, 'smacked') !== false
            );
            $isSexualGrab = (
                strpos($message, 'groped') !== false ||
                strpos($message, 'grabbed between the legs') !== false ||
                strpos($message, 'grabbed the ass') !== false ||
                strpos($message, 'grabbed the crotch') !== false
            );

            if ($isSpank || $isSexualGrab) {
                // Extract actor name from processed messages.
                $actorPattern = $isSpank
                    ? "/Player (?:slaps|smacked) (.+?) on their ass/"
                    : "/Player (?:groped|grabbed[^']+) ([^']+)'s/";
                if (preg_match($actorPattern, $message, $matches)) {
                    $actorName = trim($matches[1]);
                    // Create minimal result for tier prompt injection
                    $result = [
                        'actorName' => $actorName,
                        'action' => $isSpank ? 'spank' : 'grab',
                        'bodyPart' => $isSpank ? 'Butt' : 'Body',
                        'isSensitive' => true,
                        'isBlocked' => false
                    ];
                    self::injectVrPhysicsPrompt($result);
                }
            }
        }
    }

    /**
     * Backward-compatible wrapper for older callers.
     */
    public static function injectTierPromptForGrab($result) {
        self::injectVrPhysicsPrompt($result);
    }
    public static function shouldInjectVrPhysicsPrompt($result) {
        if (!is_array($result)) {
            return false;
        }
        if (!empty($result['suppressModelRoute'])) {
            return false;
        }
        $action = $result['action'] ?? '';
        if (!in_array($action, ['touch', 'grab', 'spank'], true)) {
            return false;
        }
        if (!empty($result['isBlocked'])) {
            return false;
        }
        return !empty($result['isSensitive']);
    }

    /**
     * Inject the UI-configured VR Physics prompt selected by REL tier.
     * This is context only. It must not set sex-scene state or fake OStim/SexLab acceptance.
     */
    public static function injectVrPhysicsPrompt($result) {
        if (!self::shouldInjectVrPhysicsPrompt($result)) {
            return;
        }

        $actor = trim((string)($result['actorName'] ?? ($GLOBALS["HERIKA_NAME"] ?? '')));
        if ($actor === '') {
            return;
        }

        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";
        $action = $result['action'] ?? 'touch';
        $bodyPart = self::displayBodyPartForActor($result['bodyPart'] ?? 'Body', $actor);
        $actionLabel = (!empty($result['sustainedTouch']) && $action === 'touch')
            ? self::sustainedTouchActionLabel($bodyPart)
            : self::physicsActionLabel($action);
        $affinity = function_exists('getNpcAffinity') ? getNpcAffinity($actor) : 0;
        $tierLabel = class_exists('RelationshipManager') ? RelationshipManager::getTierLabel($affinity) : 'Neutral';
        $tierKey = strtolower($tierLabel);
        $promptKey = "vr_{$action}_{$tierKey}";

        $prompts = self::loadPromptSettings();
        $prompt = trim((string)($prompts[$promptKey] ?? ''));
        if ($prompt === '') {
            error_log("[AIAGENTNSFW] VR Physics prompt missing for {$promptKey}; no REL context injected");
            return;
        }

        $prompt = strtr($prompt, [
            '#PLAYER_NAME#' => $playerName,
            '#NPC_NAME#' => $actor,
            '#BODY_PART#' => $bodyPart,
            '#ACTION#' => $actionLabel,
            '#TIER#' => $tierLabel,
            '#AFFINITY#' => (string)$affinity,
        ]);

        $GLOBALS["HERIKA_PERSONALITY"] = ($GLOBALS["HERIKA_PERSONALITY"] ?? '') .
            "\n<vr_physics_relationship_prompt>\n" . $prompt . "\n</vr_physics_relationship_prompt>";
        $GLOBALS["AIAGENTNSFW_LAST_VR_PHYSICS_PROMPT"] = [
            'actor' => $actor,
            'action' => $action,
            'bodyPart' => $bodyPart,
            'tier' => $tierLabel,
            'affinity' => $affinity,
            'key' => $promptKey,
        ];
        error_log("[AIAGENTNSFW] Injected VR Physics prompt {$promptKey} for {$actor} {$action} {$bodyPart}");
    }

    private static function loadPromptSettings() {
        static $promptCache = null;
        if ($promptCache !== null) {
            return $promptCache;
        }

        $defaults = function_exists('nsfw_default_vr_physics_prompt_overrides') ? nsfw_default_vr_physics_prompt_overrides() : [];
        $settings = [];
        try {
            $row = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_prompts'");
            if ($row && !empty($row['value'])) {
                $decoded = json_decode($row['value'], true);
                if (is_array($decoded)) {
                    $settings = $decoded;
                }
            }
        } catch (Exception $e) {
            error_log("[NSFW Physics] ERROR loading prompt settings: " . $e->getMessage());
        }

        $promptCache = array_replace($defaults, $settings);
        return $promptCache;
    }

    private static function physicsActionLabel($action) {
        switch ($action) {
            case 'grab':
                return 'sexual area grab';
            case 'spank':
                return 'ass spanking';
            case 'touch':
            default:
                return 'sexual area touch';
        }
    }

    private static function sustainedTouchActionLabel($bodyPart) {
        switch (self::normalizeBodyPart($bodyPart)) {
            case 'Breasts':
                return 'playing with titties';
            case 'Butt':
                return 'touching and playing with ass';
            default:
                return 'sustained sexual area touch';
        }
    }

    private static function sustainedBreastTouchThresholdSeconds() {
        $threshold = function_exists('_getNsfwSetting') ? (int)_getNsfwSetting('PHYSICS_SUSTAINED_BREAST_TOUCH_SECONDS', 5) : 5;
        return max(1, min(60, $threshold));
    }

    private static function updateSustainedTouchState($actorName, $bodyPart, $isBlocked, $isPenetration, $routeCandidate = true, $consumeSuppressedSustained = false) {
        $normalized = self::normalizeBodyPart($bodyPart);
        if (!in_array($normalized, ['Breasts', 'Butt'], true) || $isBlocked || $isPenetration) {
            return [
                'sustained' => false,
                'seconds' => 0,
                'count' => 0,
                'firstTouch' => false,
                'accumulating' => false,
                'sustainedPromptAllowed' => false,
                'suppressModelRoute' => false
            ];
        }

        $now = time();
        $threshold = self::sustainedBreastTouchThresholdSeconds();
        $touchCooldown = function_exists('_getNsfwSetting') ? max(1, (int)_getNsfwSetting('PHYSICS_TOUCH_COOLDOWN', 2)) : 2;
        $continuityWindow = max($threshold, $touchCooldown + 1);
        $path = self::sustainedTouchPath($actorName, $normalized);

        $state = [];
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw !== false && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }

        $startedAt = intval($state['startedAt'] ?? 0);
        $lastAt = intval($state['lastAt'] ?? 0);
        $count = intval($state['count'] ?? 0);
        $sustainedPrompted = !empty($state['sustainedPrompted']);
        $sustainedPromptedAt = intval($state['sustainedPromptedAt'] ?? 0);

        if ($startedAt <= 0 || $lastAt <= 0 || ($now - $lastAt) > $continuityWindow) {
            $startedAt = $now;
            $count = 0;
            $sustainedPrompted = false;
            $sustainedPromptedAt = 0;
        }

        $count++;
        $seconds = max(0, $now - $startedAt);
        $sustained = ($seconds >= $threshold && $count >= 2);
        $firstTouch = ($count === 1);
        $accumulating = (!$sustained && !$firstTouch);
        $sustainedPromptAllowed = false;

        if ($sustained && !$sustainedPrompted && ($routeCandidate || $consumeSuppressedSustained)) {
            if ($routeCandidate) {
                $sustainedPromptAllowed = true;
            }
            $sustainedPrompted = true;
            $sustainedPromptedAt = $now;
        }

        $suppressModelRoute = false;
        if ($routeCandidate) {
            $suppressModelRoute = $accumulating || ($sustained && !$sustainedPromptAllowed);
        }

        @file_put_contents($path, json_encode([
            'startedAt' => $startedAt,
            'lastAt' => $now,
            'count' => $count,
            'threshold' => $threshold,
            'continuityWindow' => $continuityWindow,
            'sustainedPrompted' => $sustainedPrompted,
            'sustainedPromptedAt' => $sustainedPromptedAt,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'sustained' => $sustained,
            'seconds' => $seconds,
            'count' => $count,
            'firstTouch' => $firstTouch,
            'accumulating' => $accumulating,
            'sustainedPromptAllowed' => $sustainedPromptAllowed,
            'suppressModelRoute' => $suppressModelRoute
        ];
    }

    /**
     * GAZE (2026-07-05): the Sharmat.cpp DLL detects the player staring at an NPC's body region/eyes and
     * emits {actor}^{region}^gaze^false^^{seconds}^{playerSex}. This turns it into an in-character reaction
     * modulated by relationship. Self-contained: injects into HERIKA_PERSONALITY, no scene state, no tools.
     * Regions: eyes | tits | ass | crotch | person. The DLL already gates lewd regions to male-player->female-NPC.
     */
    private static function handleGaze($parts) {
        $actorName = trim((string)($parts[0] ?? ''));
        $region    = strtolower(trim((string)($parts[1] ?? 'person')));
        if ($actorName === '' || !isset($GLOBALS["HERIKA_PERSONALITY"])) { return; }
        if (function_exists('_getNsfwSetting') && !_getNsfwSetting('NSFW_GAZE_ENABLED', true)) { return; }

        require_once __DIR__ . '/common.php';
        // Never react to staring DURING an active scene (you are supposed to look then), and never for children.
        if (function_exists('getIntimacyForActor')) {
            $ix = getIntimacyForActor($actorName);
            if ((int)($ix['level'] ?? 0) >= 1 || !empty($ix['sex_started']) || (int)($ix['intensity_tier'] ?? 0) >= 3) { return; }
        }
        if (function_exists('aiagentNsfwIsChildNpc') && aiagentNsfwIsChildNpc($actorName)) { return; }

        // Server-side per-actor cooldown (on top of the DLL's) so reactions do not spam.
        $cd = function_exists('_getNsfwSetting') ? (int)_getNsfwSetting('NSFW_GAZE_COOLDOWN_SECONDS', 25) : 25;
        $cdFile = sys_get_temp_dir() . '/nsfw_gaze_' . md5(strtolower($actorName)) . '.txt';
        if ($cd > 0 && is_file($cdFile) && (time() - (int)@file_get_contents($cdFile)) < $cd) { return; }

        $affinity  = function_exists('getNpcAffinity') ? (int)getNpcAffinity($actorName) : 0;
        $tierLabel = class_exists('RelationshipManager') ? RelationshipManager::getTierLabel($affinity) : 'Neutral';
        $playerName = $GLOBALS["PLAYER_NAME"] ?? 'Player';

        $validRegions = ['eyes', 'tits', 'ass', 'crotch', 'person'];
        if (!in_array($region, $validRegions, true)) { $region = 'person'; }

        $prompts = self::loadPromptSettings();
        $prompt  = trim((string)($prompts["gaze_{$region}"] ?? ''));
        if ($prompt === '') { $prompt = self::defaultGazePrompt($region); }   // fresh-install fallback
        if ($prompt === '') { $prompt = self::defaultGazePrompt('person'); }
        if ($prompt === '') { return; }

        $prompt = strtr($prompt, [
            '#PLAYER_NAME#' => $playerName,
            '#NPC_NAME#'    => $actorName,
            '#TIER#'        => $tierLabel,
            '#AFFINITY#'    => (string)$affinity,
        ]);
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n<gaze_reaction>\n" . $prompt . "\n</gaze_reaction>";
        @file_put_contents($cdFile, (string)time());
        error_log("[AIAGENTNSFW] GAZE reaction injected for {$actorName}: region={$region} tier={$tierLabel}");
    }

    // Fresh-install fallbacks; the UI-editable gaze_* prompt keys override these.
    private static function defaultGazePrompt($region) {
        switch ($region) {
            case 'eyes':
                return "#PLAYER_NAME# has been gazing into your eyes for a long moment. React the way YOUR feelings for them (#TIER#) dictate: a stranger or someone you dislike finds the staring intense or unsettling; a friend finds it curious, warm, or a little flustering; if you love or desire #PLAYER_NAME#, this is a charged, tender, romantic moment. Respond in character - do not start a scene.";
            case 'tits':
                return "#PLAYER_NAME# has been openly staring at your chest. React per how you feel about them (#TIER#): a stranger or someone you dislike is uncomfortable, offended, or calls it out sharply; a friend is awkward, teasing, or amused; if you desire #PLAYER_NAME#, you might be flustered, flattered, or lean into it. Do NOT start a scene - just react to being ogled.";
            case 'ass':
                return "#PLAYER_NAME# has been openly staring at your backside. React per how you feel about them (#TIER#): a stranger or someone you dislike is uncomfortable, offended, or snaps at them; a friend is awkward, teasing, or amused; if you desire #PLAYER_NAME#, you might be flustered, flattered, or playful about it. Do NOT start a scene - just react to being ogled.";
            case 'crotch':
                return "#PLAYER_NAME#'s eyes have been fixed below your waist. React per how you feel about them (#TIER#): a stranger or someone you dislike is very uncomfortable, offended, or confronts them; a friend is flustered or awkwardly amused; if you desire #PLAYER_NAME#, you might be bold, teasing, or invite the attention. Do NOT start a scene - just react.";
            case 'person':
            default:
                return "#PLAYER_NAME# has been staring at you for a while now. React the way your feelings for them (#TIER#) dictate: unsettling or rude from a stranger you distrust, warm or curious from a friend, charged and intimate if you love or desire them. Respond in character - do not start a scene.";
        }
    }

    /**
     * Process raw physics event from PSC
     * Returns metadata for processInfoPhysics() to handle tier prompts
     */
    public static function processPhysicsEvent($rawData, $logStatus = 'routed', $logReason = '') {
        // Use ^ delimiter to avoid conflict with CHIM's pipe-delimited message format
        $parts = explode('^', $rawData);

        if (count($parts) < 4) {
            error_log("[NSFW Physics] Invalid raw data: " . $rawData);
            return null;
        }

        $action = $parts[2]; // touch, grab, release

        // VR TOUCH TOGGLE (2026-07-06): flat/2D players get phantom CBPC contact events (no VR hands),
        // and some setups fire collisions through worn armor. This kills the CONTACT lanes only -
        // gaze is crosshair-based and works fine in 2D, so it stays on its own NSFW_GAZE_ENABLED switch.
        if (in_array($action, ['touch', 'grab', 'spank', 'release'], true)
            && function_exists('_getNsfwSetting') && !_getNsfwSetting('NSFW_VR_TOUCH_ENABLED', true)) {
            return null;
        }

        $result = null;

        // Route to appropriate handler
        switch ($action) {
            case 'touch':
                $result = self::handleTouch($parts, $logStatus === 'routed', $logStatus === 'scene_cooldown');
                break;
            case 'grab':
                $result = self::handleGrab($parts);
                break;
            case 'spank':
                $result = self::handleSpank($parts);
                break;
            case 'gaze':
                self::handleGaze($parts);   // self-contained: injects its own reaction, no contact/tier machinery
                return null;
            case 'release':
                $result = self::handleRelease($parts);
                break;
            default:
                error_log("[NSFW Physics] Unknown action: " . $action);
                return null;
        }

        if ($result && $action === 'release') {
            if (function_exists('aiagentNsfwContactRelease')) {
                $cleared = aiagentNsfwContactRelease($result);
                if ($cleared > 0) {
                    error_log("[NSFW Physics] Cleared {$cleared} active contact state row(s) for release on {$result['actorName']}");
                }
            }
            self::saveLastContact($result);
            self::logPhysicsResult($result, $rawData, 'release_context', $logReason !== '' ? $logReason : 'release updates VR contact state only');
            return null;
        }

        if ($result) {
            if (function_exists('aiagentNsfwContactUpsert')) {
                $contactKey = aiagentNsfwContactUpsert($result);
                if ($contactKey !== '') {
                    $result['contactKey'] = $contactKey;
                }
            }
            if ($logStatus === 'routed' && !empty($result['suppressModelRoute'])) {
                $logStatus = !empty($result['touchSequenceAccumulating']) ? 'touch_sequence_accumulating' : 'touch_sequence_suppressed';
                $logReason = $result['suppressReason'] ?? 'touch sequence already handled';
            }
            self::saveLastContact($result);
            self::logPhysicsResult($result, $rawData, $logStatus, $logReason);
        } else {
            self::logRawPhysicsEvent($rawData, $logStatus === 'routed' ? 'ignored' : $logStatus, $logReason);
        }

        return $result;
    }

    /**
     * Handle CBPC touch event
     * Touch = could be accidental (brush, bump)
     * Format: actor^bodypart^touch^blocked^blockedby^penetration^playersex
     *
     * Returns metadata - tier prompts handled by processInfoPhysics()
     */
    private static function handleTouch($parts, $routeCandidate = true, $consumeSuppressedSustained = false) {
        $actorName = $parts[0];
        $rawBodyPart = self::normalizeBodyPart($parts[1] ?? 'Body');
        $bodyPart = self::displayBodyPartForActor($rawBodyPart, $actorName);
        $isBlocked = filter_var($parts[3] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $blockedBy = $parts[4] ?? '';
        $rawPenetration = filter_var($parts[5] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $playerSex = intval($parts[6] ?? 0);
        $isPenetration = $rawPenetration && self::touchBodyPartCanBePenetration($bodyPart, $playerSex);
        $penetrationRejected = $rawPenetration && !$isPenetration;
        if ($penetrationRejected) {
            error_log("[NSFW Physics] Ignored invalid penetration flag for {$actorName} {$bodyPart}; raw body part was {$rawBodyPart}");
        }

        // AFFECTION-OVERLAP GUARD (tester report 2026-07-04): the legacy kiss/hug embrace can stack both
        // actors on the same spot, so CBPC fires a genital "penetration" that is pure pose overlap - the
        // narration then convinces the model sex has started. During an affection beat (recent
        // Hug/Kiss/HoldHands, no consent/sex state) demote it to a neutral closeness line.
        $affectionOverlap = false;
        if ($isPenetration && function_exists('getIntimacyForActor') && isset($GLOBALS['db'])) {
            try {
                $apIx = getIntimacyForActor($actorName);
                $apInSexState = !empty($apIx['accepted_sex']) || !empty($apIx['sex_started']) || (int)($apIx['intensity_tier'] ?? 0) >= 3;
                if (!$apInSexState) {
                    $apRow = $GLOBALS['db']->fetchOne("SELECT 1 AS x FROM actions_issued WHERE actorname='" . $GLOBALS['db']->escape($actorName) . "' AND action IN ('GiveHug','Kiss','HoldHands') AND localts > " . (time() - 90) . " LIMIT 1");
                    if ($apRow) {
                        $isPenetration = false;
                        $affectionOverlap = true;
                        error_log("[NSFW Physics] AFFECTION OVERLAP: demoted genital collision to closeness for {$actorName} (kiss/hug pose stack, no sex state)");
                    }
                }
            } catch (Exception $e) {}
        }

        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";
        $isSensitive = self::isSensitiveBodyPart($rawBodyPart);
        $sustained = self::updateSustainedTouchState($actorName, $bodyPart, $isBlocked, $isPenetration, $routeCandidate, $consumeSuppressedSustained);
        $isSustainedTouch = !empty($sustained['sustained']);

        // Build message with appropriate language
        if ($affectionOverlap) {
            // Pose-overlap contact during an embrace is closeness, never a body-part touch report.
            $message = "{$playerName} and {$actorName} are pressed close together in the embrace. This is affectionate closeness, not a sexual act.";
        } else {
            $message = self::buildTouchMessage($playerName, $actorName, $bodyPart, $isBlocked, $blockedBy, $isPenetration, $playerSex, $isSustainedTouch);
        }

        // Wrap in appropriate XML tag
        if ($isPenetration && !$isBlocked) {
            $formattedMessage = "<SEXUAL_ACT>\n" . $message . "\n</SEXUAL_ACT>";
        } elseif ($isSustainedTouch && !$isBlocked) {
            $formattedMessage = "<SEXUAL_GROPE>\n" . $message . "\n</SEXUAL_GROPE>";
        } else {
            $formattedMessage = "<PHYSICS_INFO>\n" . $message . "\n</PHYSICS_INFO>";
        }

        $suppressReason = '';
        if (!empty($sustained['accumulating'])) {
            $suppressReason = strtolower($bodyPart) . ' touch sequence is accumulating before sustained threshold';
        } elseif ($isSustainedTouch && empty($sustained['sustainedPromptAllowed'])) {
            $suppressReason = 'sustained ' . strtolower($bodyPart) . ' touch was already announced for this contact sequence';
        }

        // Return metadata - processInfoPhysics() handles tier prompts
        return [
            'message' => $formattedMessage,
            'actorName' => $actorName,
            'bodyPart' => $bodyPart,
            'rawBodyPart' => $rawBodyPart,
            'action' => 'touch',
            'isSensitive' => $isSensitive,
            'isPenetration' => $isPenetration,
            'rawPenetration' => $rawPenetration,
            'penetrationRejected' => $penetrationRejected,
            'isBlocked' => $isBlocked,
            'sustainedTouch' => $isSustainedTouch,
            'sustainedPromptAllowed' => !empty($sustained['sustainedPromptAllowed']),
            'sustainedSeconds' => $sustained['seconds'] ?? 0,
            'sustainedCount' => $sustained['count'] ?? 0,
            'touchSequenceFirst' => !empty($sustained['firstTouch']),
            'touchSequenceAccumulating' => !empty($sustained['accumulating']),
            'suppressModelRoute' => !empty($sustained['suppressModelRoute']),
            'suppressReason' => $suppressReason
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
        $rawBodyPart = self::normalizeBodyPart($parts[1] ?? 'Body');
        $bodyPart = self::displayBodyPartForActor($rawBodyPart, $actorName);
        $isBlocked = filter_var($parts[3] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $blockedBy = $parts[4] ?? '';
        $handSide = self::normalizeHandSide($parts[5] ?? 'right');
        $heldItem = $parts[6] ?? '';  // What's in the OTHER hand

        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";
        $isSensitive = self::isSensitiveBodyPart($rawBodyPart);

        // ASSAULT-WITNESS (user directive 2026-07-01): a deliberate breast grab of a Friendly-and-below NPC escalates
        // to a SILENT witness bulletin, sent only to surrounding NPCs who can actually perceive it (spatial-gated).
        // 2 grabs within 10s -> "grabbing breast"; 3rd+ -> "playing with titties". Non-blocked, breasts only.
        if (!$isBlocked && $isSensitive && $bodyPart === 'Breasts' // display-mapped: male chest is 'Chest' (fix 2026-07-01)
            && function_exists('aiagentNsfwRecordBreastGrab') && function_exists('getNpcAffinity')
            && (int)getNpcAffinity($actorName) <= 55) {
            $grabCount = aiagentNsfwRecordBreastGrab($playerName, $actorName);
            if ($grabCount === 2)    { aiagentNsfwEmitWitnessLine('breast_grab', $actorName, $playerName); }
            elseif ($grabCount >= 3) { aiagentNsfwEmitWitnessLine('breast_play', $actorName, $playerName); }
        }

        // Check if we're in an active scene (for context-aware messages)
        $sceneActiveFile = sys_get_temp_dir() . "/nsfw_scene_active.txt";
        $sceneActive = @file_get_contents($sceneActiveFile);
        $inScene = ($sceneActive !== false && (time() - (int)$sceneActive) < 600);

        // Build message - GRAB is INTENTIONAL (unlike touch which could be accidental)
        if ($isBlocked) {
            $message = "{$playerName} tried to grab {$actorName}'s {$bodyPart} but was prevented by the {$blockedBy}";
        } else {
            // Intentional grab - use stronger language for sexual areas
            if ($isSensitive) {
                $grabVerb = self::getSensitiveGrabVerb($bodyPart);
                $message = "{$playerName} {$grabVerb} {$actorName}'s {$bodyPart}";
            } elseif ($bodyPart === 'Head' && $inScene) {
                // During scene: head grab = pulling hair
                $message = "{$playerName} grabbed near {$actorName}'s head/face area and pulled";
            } else {
                $message = "{$playerName} grabbed near {$actorName}'s " . self::approximateBodyAreaLabel($bodyPart) . " (VR contact zone is approximate)";
            }
        }

        // Add held item context if player is holding something in other hand
        if (!empty($heldItem) && !$isBlocked) {
            $message .= " (while holding {$heldItem} in other hand)";
        }
        if ($handSide !== '' && !$isBlocked) {
            $message .= " with the {$handSide} hand";
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
            'rawBodyPart' => $rawBodyPart,
            'action' => 'grab',
            'isSensitive' => $isSensitive,
            'isBlocked' => $isBlocked,
            'handSide' => $handSide,
            'heldItem' => $heldItem
        ];
    }

    /**
     * Handle a spank - an INTENTIONAL swat, velocity-thresholded by the bridge/DLL
     * (above a touch, below a combat hit). Fires in or out of a scene.
     * Format: actor^bodypart^spank^blocked^blockedby^hand^helditem^speed
     */
    private static function handleSpank($parts) {
        $actorName = $parts[0];
        $rawBodyPart = self::normalizeBodyPart($parts[1] ?? 'Butt');
        $bodyPart = self::displayBodyPartForActor($rawBodyPart, $actorName);
        $isBlocked = filter_var($parts[3] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $blockedBy = $parts[4] ?? '';
        $handSide = self::normalizeHandSide($parts[5] ?? '');
        $handSpeed = isset($parts[7]) ? floatval($parts[7]) : 0.0;

        $spankEnabled = function_exists('_getNsfwSetting') ? (bool)_getNsfwSetting('PHYSICS_SPANK_ENABLED', true) : true;
        if (!$spankEnabled) {
            error_log("[NSFW Physics] Spank ignored: PHYSICS_SPANK_ENABLED is off");
            return null;
        }

        $minSpeed = function_exists('_getNsfwSetting') ? max(10, min(380, (int)_getNsfwSetting('PHYSICS_SPANK_MIN_SPEED', 30))) : 30;
        if ($handSpeed > 0 && $handSpeed < $minSpeed) {
            error_log("[NSFW Physics] Spank ignored: speed {$handSpeed} below threshold {$minSpeed}");
            return null;
        }

        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";
        $isSensitive = self::isSensitiveBodyPart($bodyPart);

        if ($isBlocked) {
            $message = "{$playerName} tried to slap {$actorName} on their ass but was prevented by the {$blockedBy}";
        } else {
            $handText = $handSide !== '' ? " with the {$handSide} hand" : "";
            $message = "{$playerName} slaps {$actorName} on their ass{$handText}!";
        }

        $formattedMessage = "<PHYSICS_INFO>\n" . $message . "\n</PHYSICS_INFO>";

        return [
            'message' => $formattedMessage,
            'actorName' => $actorName,
            'bodyPart' => $bodyPart,
            'rawBodyPart' => $rawBodyPart,
            'action' => 'spank',
            'isSensitive' => $isSensitive,
            'isBlocked' => $isBlocked,
            'handSide' => $handSide,
            'speed' => $handSpeed
        ];
    }

    /**
     * Get appropriate verb for sexual-area grabs
     * Makes the action description more explicit/sexual
     */
    private static function getSensitiveGrabVerb($bodyPart) {
        switch ($bodyPart) {
            case 'Breasts':
                return 'groped';
            case 'Chest':
                return 'grabbed';
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
        $rawBodyPart = self::normalizeBodyPart($parts[1] ?? 'Body');
        $bodyPart = self::displayBodyPartForActor($rawBodyPart, $actorName);
        $duration = floatval($parts[3] ?? 0);
        $handSide = self::normalizeHandSide($parts[4] ?? 'right');

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
            'rawBodyPart' => $rawBodyPart,
            'action' => 'release',
            'isSensitive' => self::isSensitiveBodyPart($rawBodyPart),
            'handSide' => $handSide,
            'duration' => $duration
        ];
    }

    /**
     * Check if body part routes through the sexual-area prompt path
     */
    private static function isSensitiveBodyPart($bodyPart) {
        return in_array(self::normalizeBodyPart($bodyPart), self::$sensitiveParts, true);
    }

    /**
     * Build touch message based on context
     * TOUCH = could be accidental (brush, bump, incidental contact)
     * PENETRATION = sexual insertion
     */
    private static function buildTouchMessage($playerName, $actorName, $bodyPart, $isBlocked, $blockedBy, $isPenetration, $playerSex, $isSustainedTouch = false) {
        if ($isPenetration && !self::touchBodyPartCanBePenetration($bodyPart, $playerSex)) {
            $isPenetration = false;
        }

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
                }
            } else {
                // Female player being penetrated
                if ($bodyPart == 'Penis') {
                    return "{$actorName} inserted their cock into {$playerName}";
                }
            }
        }

        // BLOCKED touch attempt
        if ($isBlocked) {
            return "{$playerName} tried to touch {$actorName}'s {$bodyPart} but was prevented by the {$blockedBy}";
        }

        if ($isSustainedTouch && self::normalizeBodyPart($bodyPart) === 'Breasts') {
            return "{$playerName} is playing with {$actorName}'s titties";
        }
        if ($isSustainedTouch && self::normalizeBodyPart($bodyPart) === 'Butt') {
            return "{$playerName} is playing with {$actorName}'s ass";
        }

        // REGULAR TOUCH - could be accidental
        // Use softer language that implies it might not be intentional
        $isSensitive = self::isSensitiveBodyPart($bodyPart);

        if ($isSensitive) {
            if (self::normalizeBodyPart($bodyPart) === 'Butt') {
                return "{$playerName} accidentally touched {$actorName}'s ass";
            }
            // Sexual-area touch - phrase as potentially accidental
            // This is different from GRAB which is intentional
            $touchVerb = self::getAccidentalTouchVerb($bodyPart);
            return "{$playerName} {$touchVerb} {$actorName}'s {$bodyPart}";
        } else {
            // Non-sexual area - just a regular touch
            return "{$playerName} brushed near {$actorName}'s " . self::approximateBodyAreaLabel($bodyPart) . " (VR contact zone is approximate)";
        }
    }

    private static function approximateBodyAreaLabel($bodyPart) {
        switch (self::normalizeBodyPart($bodyPart)) {
            case 'Head':
                return 'head/face area';
            case 'Face':
                return 'face';
            case 'Neck':
                return 'neck/face area';
            case 'Shoulder':
                return 'shoulder/upper chest area';
            case 'Chest':
                return 'chest';
            case 'Back':
                return 'back/shoulder area';
            case 'Belly':
                return 'torso/belly area';
            case 'Arm':
                return 'arm/side area';
            case 'Hand':
                return 'hand';
            case 'Leg':
                return 'leg/thigh area';
            case 'Foot':
                return 'foot';
            case 'Body':
            case 'Other':
                return 'body';
            default:
                return self::normalizeBodyPart($bodyPart);
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
            case 'Chest':
                return 'accidentally brushed against';
            case 'Butt':
                return 'accidentally touched';
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
