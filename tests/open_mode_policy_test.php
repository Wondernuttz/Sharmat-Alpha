<?php

require_once dirname(__DIR__) . '/open_mode_policy.php';

$failures = [];
$assertions = 0;
function checkOpenModePolicy($condition, $message)
{
    global $failures, $assertions;
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
}

$base = [
    'scene_phase' => 'tier_prompt',
    'intensity_tier' => 3,
    'scene_is_idle' => false,
    'scene_actors' => ['Fruki', 'Yoshua'],
    'accepted_sex' => false,
    'refusal_expressed' => false,
    'refused_until_scene_end' => false,
    'request_scene_stop' => false,
    'stop_command_sent' => false,
    'forced_scene' => false,
];

$mayAccept = function ($status, $openMode = true, $isChild = false, $isProstitute = false, $isSlave = false, $isSkooma = false, $isSceneEvent = true) {
    return aiagentNsfwShouldAutoAcceptOpenModePlayerScene(
        $status,
        $openMode,
        $isChild,
        $isProstitute,
        $isSlave,
        $isSkooma,
        $isSceneEvent,
        'Yoshua'
    );
};

checkOpenModePolicy($mayAccept($base), 'eligible explicit Open Mode player scene should auto-accept');
checkOpenModePolicy(!$mayAccept($base, false), 'closed mode must retain the normal decision machine');
checkOpenModePolicy(!$mayAccept($base, true, false, false, false, false, false), 'non-scene requests must not revive stale scene state');
checkOpenModePolicy(!$mayAccept($base, true, true), 'child protection must override Open Mode');
checkOpenModePolicy(!$mayAccept($base, true, false, true), 'prostitute payment path must override Open Mode');
checkOpenModePolicy(!$mayAccept($base, true, false, false, true), 'slave path must retain its own state machine');
checkOpenModePolicy(!$mayAccept($base, true, false, false, false, true), 'skooma bargain must retain its own state machine');

foreach ([
    'rejected phase' => ['scene_phase' => 'rejected'],
    'spoken refusal' => ['refusal_expressed' => true],
    'sticky refusal' => ['refused_until_scene_end' => true],
    'stop request' => ['request_scene_stop' => true],
    'queued stop' => ['stop_command_sent' => true],
    'forced continuation' => ['forced_scene' => true],
] as $label => $changes) {
    checkOpenModePolicy(!$mayAccept(array_replace($base, $changes)), "{$label} must dominate Open Mode");
}

checkOpenModePolicy(!$mayAccept(array_replace($base, ['intensity_tier' => 2, 'scene_phase' => 'affection'])), 'affection tiers must not become accepted sex');
checkOpenModePolicy(!$mayAccept(array_replace($base, ['scene_is_idle' => true])), 'idle/intro tier must not become accepted sex');
checkOpenModePolicy(!$mayAccept(array_replace($base, ['is_npc_scene' => true])), 'NPC-only scenes must retain their own route');
checkOpenModePolicy(!$mayAccept(array_replace($base, ['scene_actors' => ['Fruki', 'Lydia']])), 'scene must actually include the player');
checkOpenModePolicy(!$mayAccept(array_replace($base, ['scene_actors' => []])), 'missing scene actors must fail closed');
checkOpenModePolicy(!$mayAccept(array_replace($base, ['accepted_sex' => true])), 'already accepted state must not transition twice');

$acceptedLow = aiagentNsfwApplyOpenModeAcceptedState($base, 20);
checkOpenModePolicy(($acceptedLow['scene_phase'] ?? '') === 'accepted', 'transition must set accepted phase');
checkOpenModePolicy(!empty($acceptedLow['accepted_sex']), 'transition must set accepted_sex');
checkOpenModePolicy(!empty($acceptedLow['tier_prompt_sent']), 'transition must mark the legacy decision phase handled');
checkOpenModePolicy(empty($acceptedLow['request_scene_stop']), 'transition must not request a scene stop');
checkOpenModePolicy(empty($acceptedLow['show_normal_kinks']) && empty($acceptedLow['show_secret_kinks']), 'low affinity must not unlock kinks');
checkOpenModePolicy(!array_key_exists('last_accept_sex_time', $acceptedLow), 'direct state acceptance must not fake AcceptSex timestamps');
checkOpenModePolicy(!array_key_exists('payment_confirmed', $acceptedLow), 'direct state acceptance must not fake payment');

$acceptedHigh = aiagentNsfwApplyOpenModeAcceptedState($base, 80);
checkOpenModePolicy(!empty($acceptedHigh['show_normal_kinks']), 'Devoted affinity should unlock normal kinks');
checkOpenModePolicy(!empty($acceptedHigh['show_secret_kinks']), 'Devoted affinity should unlock secret kinks');

$prompt = aiagentNsfwBuildOpenModeScenePrompt('Fruki', 'Yoshua', 42, 'Friendly');
checkOpenModePolicy(strpos($prompt, 'do not call AcceptSex') !== false, 'Open Mode prompt must suppress the redundant decision tool');
checkOpenModePolicy(strpos($prompt, 'relationship type') !== false && strpos($prompt, 'arousal rules') !== false, 'Open Mode prompt must neutralize the disabled gates');
checkOpenModePolicy(strpos($prompt, 'standing or intro beat') !== false, 'Open Mode prompt must keep intro beats non-explicit');

if ($failures) {
    fwrite(STDERR, "Open Mode policy regression failures:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Open Mode policy regression tests passed ({$assertions} assertions).\n";
