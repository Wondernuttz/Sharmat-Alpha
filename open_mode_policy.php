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

    $playerName = trim((string)$playerName);
    $sceneActors = is_array($intimacyStatus['scene_actors'] ?? null)
        ? $intimacyStatus['scene_actors']
        : [];
    if ($playerName === '' || empty($sceneActors)) {
        return false;
    }
    foreach ($sceneActors as $sceneActor) {
        if (strcasecmp(trim((string)$sceneActor), $playerName) === 0) {
            return true;
        }
    }
    return false;
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
