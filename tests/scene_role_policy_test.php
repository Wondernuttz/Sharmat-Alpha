<?php

require_once __DIR__ . '/../scene_role_policy.php';

function assertSceneRole($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$defeatRoles = [
    'Dragonborn' => ['victim', 'forced'],
    'Bandit Chief' => ['aggressor', 'forced'],
];

assertSceneRole(aiagentNsfwFrameworkPlayerIsVictim($defeatRoles, 'dragonborn'), 'player victim lookup must ignore case');
assertSceneRole(aiagentNsfwFrameworkActorIsAggressor($defeatRoles, 'bandit chief', 'DRAGONBORN'), 'aggressor must be recognized in a player-victim scene');
assertSceneRole(!aiagentNsfwFrameworkActorIsAggressor($defeatRoles, 'Dragonborn', 'Dragonborn'), 'victim must not be classified as aggressor');

$ordinaryRoles = [
    'Dragonborn' => ['sub'],
    'Sofia' => ['dom'],
];
assertSceneRole(!aiagentNsfwFrameworkPlayerIsVictim($ordinaryRoles, 'Dragonborn'), 'ordinary consensual role tags must not trigger defeat handling');
assertSceneRole(!aiagentNsfwFrameworkActorIsAggressor($ordinaryRoles, 'Sofia', 'Dragonborn'), 'dom alone must not bypass relationship gating');

echo "scene_role_policy_test: OK\n";
