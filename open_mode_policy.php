<?php

/**
 * Pure Open Mode policy helpers.
 *
 * Keep this file free of CHIM/database dependencies so the consent transition can
 * be regression-tested without bootstrapping the full server.
 */

function aiagentNsfwShouldAutoAcceptOpenModePlayerScene(
    $intimacyStatus,
    $openMode,
    $isChild,
    $isProstitute,
    $isSlave,
    $isSkoomaBargain,
    $isSceneEvent,
    $playerName
) {
    if (!$openMode || !$isSceneEvent || $isChild || $isProstitute || $isSlave || $isSkoomaBargain) {
        return false;
    }
    if (!is_array($intimacyStatus) || !empty($intimacyStatus['is_npc_scene'])) {
        return false;
    }
    if (!empty($intimacyStatus['accepted_sex'])
        || (int)($intimacyStatus['intensity_tier'] ?? 0) < 3
        || !empty($intimacyStatus['scene_is_idle'])) {
        return false;
    }

    $phase = strtolower(trim((string)($intimacyStatus['scene_phase'] ?? '')));
    $refusalLatched = ($phase === 'rejected')
        || !empty($intimacyStatus['refusal_expressed'])
        || !empty($intimacyStatus['refused_until_scene_end'])
        || !empty($intimacyStatus['request_scene_stop'])
        || !empty($intimacyStatus['stop_command_sent'])
        || !empty($intimacyStatus['forced_scene']);
    if ($refusalLatched) {
        return false;
    }

    $sceneActors = is_array($intimacyStatus['scene_actors'] ?? null)
        ? $intimacyStatus['scene_actors']
        : [];
    if (empty($sceneActors)) {
        return false;
    }
    return aiagentNsfwSceneActorsContainPlayer($sceneActors, $playerName);
}

/**
 * Scene actor lists are not consistent about the player label. Depending on
 * which CHIM/OStim route produced the event, the same player may arrive as the
 * resolved character name or as the canonical literal "Player".
 */
function aiagentNsfwSceneActorsContainPlayer($sceneActors, $playerName)
{
    if (!is_array($sceneActors) || empty($sceneActors)) {
        return false;
    }

    $playerAliases = ['player'];
    $resolvedPlayer = strtolower(trim((string)$playerName));
    if ($resolvedPlayer !== '') {
        $playerAliases[] = $resolvedPlayer;
    }

    foreach ($sceneActors as $sceneActor) {
        if (in_array(strtolower(trim((string)$sceneActor)), $playerAliases, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Normalize historical/model relationship labels before any SHARMAT gate reads
 * them. Core CHIM now enforces the same aliases, but keeping this boundary here
 * protects players whose server has not received that core update yet.
 */
function aiagentNsfwCanonicalRelationshipType($type)
{
    $type = strtolower(trim((string)$type));
    $aliases = [
        'romance' => 'romantic',
        'marriage' => 'romantic',
        'married' => 'romantic',
        'lover' => 'romantic',
        'lovers' => 'romantic',
        'betrayal' => 'betrayed',
        'enemies' => 'enemy',
    ];
    return $aliases[$type] ?? $type;
}

/**
 * Normalize the stored relationship-type policy at its JSON/DB boundary.
 * Callers should decode JSON without associative mode so a top-level list
 * cannot be mistaken for a config object.
 */
function aiagentNsfwNormalizeRelTypeConfig($config, $readStatus = 'present')
{
    $defaults = [
        'status' => 'missing',
        'valid' => true,
        'enabled' => true,
        'eligible_types' => ['romantic', 'crush', 'ex'],
        'non_romantic_types' => [],
        'use_intimacy_arrangements' => false,
    ];
    $invalid = static function ($status) {
        return [
            'status' => $status,
            'valid' => false,
            'enabled' => true,
            'eligible_types' => [],
            'non_romantic_types' => [],
            'use_intimacy_arrangements' => false,
        ];
    };

    if ($readStatus === 'missing') { return $defaults; }
    if ($readStatus === 'error') { return $invalid('error'); }
    if ($readStatus === 'malformed') { return $invalid('malformed'); }
    if ($readStatus !== 'present') { return $invalid('error'); }

    if ($config instanceof stdClass) {
        $values = get_object_vars($config);
    } elseif (is_array($config) && $config !== [] && !array_is_list($config)) {
        $values = $config;
    } else {
        return $invalid('malformed');
    }

    if (array_key_exists('enabled', $values) && !is_bool($values['enabled'])) {
        return $invalid('malformed');
    }
    if (array_key_exists('use_intimacy_arrangements', $values) && !is_bool($values['use_intimacy_arrangements'])) {
        return $invalid('malformed');
    }

    $normalizeList = static function ($items, &$valid) {
        if (!is_array($items) || !array_is_list($items)) {
            $valid = false;
            return [];
        }
        $normalized = [];
        foreach ($items as $item) {
            if (!is_string($item) || trim($item) === '') {
                $valid = false;
                return [];
            }
            $type = aiagentNsfwCanonicalRelationshipType($item);
            if ($type === '') {
                $valid = false;
                return [];
            }
            if (!in_array($type, $normalized, true)) { $normalized[] = $type; }
        }
        return $normalized;
    };

    $valid = true;
    $eligible = array_key_exists('eligible_types', $values)
        ? $normalizeList($values['eligible_types'], $valid)
        : ['romantic', 'crush', 'ex'];
    $nonRomantic = array_key_exists('non_romantic_types', $values)
        ? $normalizeList($values['non_romantic_types'], $valid)
        : [];
    if (!$valid) { return $invalid('malformed'); }

    return [
        'status' => 'present',
        'valid' => true,
        'enabled' => array_key_exists('enabled', $values) ? $values['enabled'] : true,
        'eligible_types' => $eligible,
        'non_romantic_types' => $nonRomantic,
        'use_intimacy_arrangements' => array_key_exists('use_intimacy_arrangements', $values)
            ? $values['use_intimacy_arrangements']
            : false,
    ];
}

/**
 * Open Mode and a disabled relationship-type gate must not reuse legacy saved
 * overhead text. Older installations can retain directives such as "not into
 * romance or intimacy" in those editable fields, which silently recreates the
 * gate even after the user turned it off.
 */
function aiagentNsfwBuildNonGatingRelationshipOverhead(
    $npcName,
    $playerName,
    $relationship,
    $openMode = false
) {
    $npcName = trim((string)$npcName);
    $playerName = trim((string)$playerName);
    if ($npcName === '') { $npcName = 'the NPC'; }
    if ($playerName === '') { $playerName = 'the player'; }
    $relationship = is_array($relationship) ? $relationship : [];
    $type = trim((string)($relationship['type'] ?? 'Unknown'));
    $tier = trim((string)($relationship['tier'] ?? 'Neutral'));
    $affinity = (int)($relationship['aff'] ?? 0);

    $prompt = "SHARMAT RELATIONSHIP CONTEXT: You are {$npcName}. Your current relationship with {$playerName} is {$type}; tier {$tier} ({$affinity} affinity). ";
    if ($openMode) {
        $prompt .= "Open Mode is active: these relationship facts are emotional history and characterization only, never a permission gate or a reason to ignore the current request. Do not infer that a non-romantic label forbids affection or intimacy.";
    } else {
        $prompt .= "Relationship-type gating is disabled: the type label is descriptive only and must not create a sexual or romantic restriction. Use the current affinity, personality, and scene context normally.";
    }
    return $prompt;
}

function aiagentNsfwApplyOpenModeAcceptedState($intimacyStatus, $affinity)
{
    $intimacyStatus = is_array($intimacyStatus) ? $intimacyStatus : [];
    $affinity = (int)$affinity;

    // This is a state transition, not a synthetic AcceptSex tool call. In
    // particular, it must not grant arousal, crushes, payments, or timestamps.
    $intimacyStatus['scene_phase'] = 'accepted';
    $intimacyStatus['accepted_sex'] = true;
    $intimacyStatus['tier_prompt_sent'] = true;
    $intimacyStatus['request_scene_stop'] = false;
    $intimacyStatus['arousal_pacing_decline_pending'] = false;
    $intimacyStatus['show_normal_kinks'] = ($affinity >= 56);
    $intimacyStatus['show_secret_kinks'] = ($affinity >= 76);

    return $intimacyStatus;
}

function aiagentNsfwBuildOpenModeScenePrompt($npcName, $partnerName, $affinity = 0, $tier = '')
{
    $npcName = trim((string)$npcName);
    $partnerName = trim((string)$partnerName);
    $tier = trim((string)$tier);
    $emotionalState = $tier !== ''
        ? "{$tier} (affinity " . (int)$affinity . ')'
        : 'the current relationship state';

    return "OPEN MODE is active for this adult, non-commercial player scene. "
        . "If the scene is explicit and active, the server treats it as already accepted; do not call AcceptSex or create a separate consent-decision turn. "
        . "If this is only a standing or intro beat, keep it non-explicit but open and available instead of inventing a refusal. "
        . "Use {$emotionalState} only as emotional flavor for how {$npcName} participates. "
        . "Do not refuse, pause, slow down, or end the scene because of affinity, relationship type, courtship, marriage, orientation, or arousal rules: those framework gates are disabled in Open Mode. "
        . "Continue from the current physical action in character. RefuseSex or StopScene is reserved only for a concrete, immediate in-character reason arising now, never for a disabled framework rule.";
}
