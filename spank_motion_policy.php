<?php

/**
 * Validate the controller-motion evidence attached to a SHARMAT spank event.
 *
 * The bridge only emits a spank after the hand crosses into the butt contact
 * zone. SHARMAT still validates the reported controller speed so malformed or
 * legacy events cannot bypass the configured threshold by omitting it.
 */
function aiagentNsfwSpankMinimumSpeed($configuredMinimum = null)
{
    if ($configuredMinimum === null && function_exists('_getNsfwSetting')) {
        $configuredMinimum = _getNsfwSetting('PHYSICS_SPANK_MIN_SPEED', 100);
    }

    if (!is_numeric($configuredMinimum)) {
        $configuredMinimum = 100;
    }

    // Speeds below 80 include the incidental walk-in contacts seen in user logs.
    return max(80, min(380, (int) $configuredMinimum));
}
function aiagentNsfwValidateSpankMotion(array $parts, $configuredMinimum = null)
{
    $minimum = aiagentNsfwSpankMinimumSpeed($configuredMinimum);
    $rawSpeed = $parts[7] ?? null;

    if ($rawSpeed === null || !is_numeric(trim((string) $rawSpeed))) {
        return [
            'allowed' => false,
            'speed' => 0.0,
            'minimum' => $minimum,
            'reason' => 'missing or invalid measured controller speed',
        ];
    }

    $speed = (float) $rawSpeed;
    if (!is_finite($speed) || $speed <= 0.0) {
        return [
            'allowed' => false,
            'speed' => $speed,
            'minimum' => $minimum,
            'reason' => 'missing or invalid measured controller speed',
        ];
    }

    if ($speed < $minimum) {
        return [
            'allowed' => false,
            'speed' => $speed,
            'minimum' => $minimum,
            'reason' => "controller speed {$speed} below threshold {$minimum}",
        ];
    }

    return [
        'allowed' => true,
        'speed' => $speed,
        'minimum' => $minimum,
        'reason' => '',
    ];
}
