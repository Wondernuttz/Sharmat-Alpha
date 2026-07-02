<?php

function aiagentNsfwContactLedgerPath()
{
    return sys_get_temp_dir() . "/aiagent_nsfw_contact_state.json";
}

function aiagentNsfwContactNormalizeToken($value, $fallback = 'unknown')
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9_-]+/i', '_', $value);
    $value = trim($value, '_');
    return $value !== '' ? $value : $fallback;
}

function aiagentNsfwContactNormalizeActor($actor)
{
    return trim((string)$actor);
}

function aiagentNsfwContactSettingSeconds($setting, $default, $min = 1, $max = 120)
{
    $value = $default;
    if (function_exists('_getNsfwSetting')) {
        $value = (int)_getNsfwSetting($setting, $default);
    }
    return max($min, min($max, (int)$value));
}

function aiagentNsfwContactTtlForAction($action)
{
    $action = strtolower(trim((string)$action));
    if ($action === 'grab') {
        $base = aiagentNsfwContactSettingSeconds('PHYSICS_GRAB_COOLDOWN', 2, 1, 60);
        return max(5, min(20, $base + 3));
    }
    if ($action === 'spank') {
        $base = aiagentNsfwContactSettingSeconds('PHYSICS_SPANK_COOLDOWN', 5, 1, 60);
        return max(5, min(30, $base));
    }
    if ($action === 'touch') {
        $base = aiagentNsfwContactSettingSeconds('PHYSICS_TOUCH_COOLDOWN', 2, 1, 120);
        return max(5, min(20, $base + 3));
    }
    return 5;
}

function aiagentNsfwContactLoadLedger()
{
    $path = aiagentNsfwContactLedgerPath();
    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data)) {
        $data = [];
    }

    $now = time();
    $changed = false;
    foreach ($data as $key => $row) {
        $expires = (int)($row['expires_at'] ?? 0);
        $updated = (int)($row['updated_at'] ?? 0);
        if (($expires > 0 && $expires < $now) || ($updated > 0 && ($now - $updated) > 3600)) {
            unset($data[$key]);
            $changed = true;
        }
    }
    if ($changed) {
        aiagentNsfwContactSaveLedger($data);
    }
    return $data;
}

function aiagentNsfwContactSaveLedger($ledger)
{
    @file_put_contents(aiagentNsfwContactLedgerPath(), json_encode($ledger, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function aiagentNsfwContactKey($actorName, $action, $bodyPart = 'Body', $handSide = '')
{
    $actorKey = aiagentNsfwContactNormalizeToken($actorName, 'unknown_actor');
    $actionKey = aiagentNsfwContactNormalizeToken($action, 'contact');
    $bodyKey = aiagentNsfwContactNormalizeToken($bodyPart, 'body');
    $handKey = aiagentNsfwContactNormalizeToken($handSide, 'any');
    return $actorKey . "_" . $actionKey . "_" . $bodyKey . "_" . $handKey;
}

function aiagentNsfwContactPlainMessage($message)
{
    $message = aiagentNsfwContactStripTags((string)$message);
    return trim(preg_replace('/\s+/', ' ', strip_tags($message)));
}

function aiagentNsfwContactUpsert($result)
{
    if (!is_array($result)) {
        return '';
    }
    $actorName = aiagentNsfwContactNormalizeActor($result['actorName'] ?? '');
    if ($actorName === '') {
        return '';
    }

    $action = strtolower(trim((string)($result['action'] ?? 'contact')));
    if ($action === 'release') {
        return '';
    }

    $bodyPart = trim((string)($result['bodyPart'] ?? 'Body'));
    $handSide = trim((string)($result['handSide'] ?? ''));
    $key = aiagentNsfwContactKey($actorName, $action, $bodyPart, $handSide);
    $now = time();
    $ttl = aiagentNsfwContactTtlForAction($action);
    $isActiveHold = in_array($action, ['grab', 'touch'], true) && empty($result['isBlocked']);

    $ledger = aiagentNsfwContactLoadLedger();
    $ledger[$key] = [
        'key' => $key,
        'actorName' => $actorName,
        'actor_key' => strtolower($actorName),
        'action' => $action,
        'bodyPart' => $bodyPart,
        'rawBodyPart' => $result['rawBodyPart'] ?? $bodyPart,
        'handSide' => $handSide,
        'isSensitive' => !empty($result['isSensitive']),
        'isBlocked' => !empty($result['isBlocked']),
        'isCurrent' => $isActiveHold,
        'message' => aiagentNsfwContactPlainMessage($result['message'] ?? ''),
        'created_at' => (int)($ledger[$key]['created_at'] ?? $now),
        'updated_at' => $now,
        'expires_at' => $now + $ttl,
    ];
    aiagentNsfwContactSaveLedger($ledger);
    return $key;
}

function aiagentNsfwContactRelease($result)
{
    if (!is_array($result)) {
        return 0;
    }
    $actorName = aiagentNsfwContactNormalizeActor($result['actorName'] ?? '');
    if ($actorName === '') {
        return 0;
    }

    $actorKey = strtolower($actorName);
    $bodyPart = strtolower(trim((string)($result['bodyPart'] ?? '')));
    $handSide = strtolower(trim((string)($result['handSide'] ?? '')));
    $ledger = aiagentNsfwContactLoadLedger();
    $removed = 0;

    foreach ($ledger as $key => $row) {
        if (strtolower((string)($row['actorName'] ?? '')) !== $actorKey) {
            continue;
        }
        if (($row['action'] ?? '') !== 'grab') {
            continue;
        }
        $rowBody = strtolower(trim((string)($row['bodyPart'] ?? '')));
        $rowHand = strtolower(trim((string)($row['handSide'] ?? '')));
        $bodyMatches = ($bodyPart === '' || $rowBody === '' || $rowBody === $bodyPart);
        $handMatches = ($handSide === '' || $rowHand === '' || $rowHand === $handSide);
        if ($bodyMatches && $handMatches) {
            unset($ledger[$key]);
            $removed++;
        }
    }

    if ($removed > 0) {
        aiagentNsfwContactSaveLedger($ledger);
    }
    return $removed;
}

function aiagentNsfwContactBuildContext($actorName)
{
    $actorName = aiagentNsfwContactNormalizeActor($actorName);
    if ($actorName === '') {
        return '';
    }

    $ledger = aiagentNsfwContactLoadLedger();
    $now = time();
    $rows = [];
    foreach ($ledger as $row) {
        if (strtolower((string)($row['actorName'] ?? '')) !== strtolower($actorName)) {
            continue;
        }
        $expires = (int)($row['expires_at'] ?? 0);
        if ($expires <= $now) {
            continue;
        }
        $rows[] = $row;
    }

    if (empty($rows)) {
        return '';
    }

    usort($rows, function ($a, $b) {
        return (int)($b['updated_at'] ?? 0) <=> (int)($a['updated_at'] ?? 0);
    });
    $rows = array_slice($rows, 0, 4);

    $lines = [];
    foreach ($rows as $row) {
        $message = trim((string)($row['message'] ?? ''));
        if ($message === '') {
            continue;
        }
        $action = (string)($row['action'] ?? 'contact');
        $bodyPart = (string)($row['bodyPart'] ?? 'Body');
        $expiresIn = max(0, (int)($row['expires_at'] ?? $now) - $now);
        $state = !empty($row['isCurrent']) ? 'current short-lived contact' : 'recent one-shot contact';
        $lines[] = "## {$state}: {$message} (action: {$action}; body part: {$bodyPart}; expires in {$expiresIn}s).";
    }

    if (empty($lines)) {
        return '';
    }

    $context = "<physical_contact_state>\n";
    $context .= "# AUTHORITATIVE LIVE VR PHYSICAL CONTACT\n";
    $context .= "## These rows are short-lived HIGGS/CBPC contact state for {$actorName}. Use them over stale memory or old chat history for where the player is touching/grabbing/slapping this NPC.\n";
    $context .= "## This is context only; do not treat it as a new action unless the current request is itself a physical-contact event. If no row says a grab is current, the NPC is not currently being grabbed.\n";
    $context .= implode("\n", $lines) . "\n";
    $context .= "</physical_contact_state>";
    return $context;
}

function aiagentNsfwContactTag($text, $key)
{
    $key = aiagentNsfwContactNormalizeToken($key, '');
    $text = (string)$text;
    if ($key === '' || strpos($text, '#SHARMAT_CONTACT_STATE:') !== false) {
        return $text;
    }
    return rtrim($text) . "\n#SHARMAT_CONTACT_STATE:" . $key;
}

function aiagentNsfwContactStripTags($text)
{
    return preg_replace('/\s*#SHARMAT_CONTACT_STATE:[A-Za-z0-9_-]+/', '', (string)$text);
}

function aiagentNsfwContactContextKey($text)
{
    if (preg_match('/#SHARMAT_CONTACT_STATE:([A-Za-z0-9_-]+)/', (string)$text, $m)) {
        return $m[1];
    }
    return '';
}

function aiagentNsfwContactIsActive($key)
{
    $key = aiagentNsfwContactNormalizeToken($key, '');
    if ($key === '') {
        return false;
    }
    $ledger = aiagentNsfwContactLoadLedger();
    return isset($ledger[$key]) && (int)($ledger[$key]['expires_at'] ?? 0) > time();
}

function aiagentNsfwContactIsLegacyPhysicsHistoryRow($row, $contentField = 'content')
{
    if (!is_array($row)) {
        return false;
    }
    $role = strtolower(trim((string)($row['role'] ?? '')));
    $content = (string)($row[$contentField] ?? '');
    if ($content === '' || aiagentNsfwContactContextKey($content) !== '') {
        return false;
    }

    if (in_array($role, ['ext_nsfw_physics', 'chatnf_physics', 'ext_nsfw_physics_blocked'], true)) {
        return true;
    }

    return preg_match('/<(PHYSICS_INFO|SEXUAL_GROPE|SEXUAL_ACT)>/i', $content) === 1
        && preg_match('/\b(touch|touched|grab|grabbed|grope|groped|slap|slaps|spank|release|released)\b/i', $content) === 1;
}

function aiagentNsfwContactCleanContextArray(&$rows, $contentField = 'content')
{
    if (!is_array($rows)) {
        return 0;
    }
    $taggedByContact = [];
    foreach ($rows as $idx => $row) {
        $content = is_array($row) ? (string)($row[$contentField] ?? '') : '';
        $key = aiagentNsfwContactContextKey($content);
        if ($key === '') {
            if (aiagentNsfwContactIsLegacyPhysicsHistoryRow($row, $contentField)) {
                unset($rows[$idx]);
            }
            continue;
        }
        if (!aiagentNsfwContactIsActive($key)) {
            unset($rows[$idx]);
            continue;
        }
        $taggedByContact[$key][] = $idx;
    }
    foreach ($taggedByContact as $key => $idxs) {
        array_pop($idxs);
        foreach ($idxs as $idx) {
            unset($rows[$idx]);
        }
    }
    foreach ($rows as $idx => $row) {
        if (is_array($row) && isset($row[$contentField])) {
            $rows[$idx][$contentField] = aiagentNsfwContactStripTags($row[$contentField]);
        }
    }
    $rows = array_values($rows);
    return count($taggedByContact);
}
