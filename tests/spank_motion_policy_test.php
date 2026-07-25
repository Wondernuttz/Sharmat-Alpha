<?php

require_once dirname(__DIR__) . '/spank_motion_policy.php';

$failures = [];
$assertions = 0;
function checkSpankMotionPolicy($condition, $message)
{
    global $failures, $assertions;
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
}

$partsWithSpeed = function ($speed) {
    return ['Lydia', 'Butt', 'spank', 'false', '', 'right', '', $speed];
};

checkSpankMotionPolicy(!aiagentNsfwValidateSpankMotion([])['allowed'], 'missing speed must fail closed');
checkSpankMotionPolicy(!aiagentNsfwValidateSpankMotion($partsWithSpeed('nope'))['allowed'], 'non-numeric speed must fail closed');
checkSpankMotionPolicy(!aiagentNsfwValidateSpankMotion($partsWithSpeed(0))['allowed'], 'zero speed must fail closed');
checkSpankMotionPolicy(aiagentNsfwSpankMinimumSpeed(30) === 80, 'legacy low settings must respect the safety floor');
checkSpankMotionPolicy(!aiagentNsfwValidateSpankMotion($partsWithSpeed(57.7), 30)['allowed'], 'logged 57.7 walk-in contact must be rejected');
checkSpankMotionPolicy(!aiagentNsfwValidateSpankMotion($partsWithSpeed(73.5), 30)['allowed'], 'logged 73.5 walk-in contact must be rejected');
checkSpankMotionPolicy(aiagentNsfwValidateSpankMotion($partsWithSpeed(80), 30)['allowed'], 'speed at the safety floor may pass');
checkSpankMotionPolicy(!aiagentNsfwValidateSpankMotion($partsWithSpeed(99))['allowed'], 'default threshold must reject speed below 100');
checkSpankMotionPolicy(aiagentNsfwValidateSpankMotion($partsWithSpeed(100))['allowed'], 'default threshold must accept speed at 100');
checkSpankMotionPolicy(aiagentNsfwSpankMinimumSpeed(500) === 380, 'configured threshold must retain its upper clamp');

if ($failures) {
    fwrite(STDERR, "Spank motion policy regression failures:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Spank motion policy regression tests passed ({$assertions} assertions).\n";
