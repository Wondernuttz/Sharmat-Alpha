<?php
/** Optional DD bridge: factual snapshots, target-specific policy and command validation. */
function sharmatDeviceTypes(): array
{
    return [
        'belt' => 'a chastity belt', 'bra' => 'a chastity bra', 'gag' => 'a gag',
        'collar' => 'a collar', 'armbinder' => 'an armbinder', 'yoke' => 'a yoke',
        'elbowtie' => 'an elbow tie', 'straitjacket' => 'a straitjacket',
        'blindfold' => 'a blindfold', 'hobbleskirt' => 'a hobble skirt',
        'armcuffs' => 'arm cuffs', 'legcuffs' => 'leg cuffs', 'ankleshackles' => 'ankle shackles',
        'plugvaginal' => 'a vaginal plug', 'pluganal' => 'an anal plug', 'clamps' => 'clamps',
        'corset' => 'a corset', 'hood' => 'a hood', 'harness' => 'a harness',
        'gloves' => 'restraint gloves', 'suit' => 'a restrictive suit',
        'piercingsnipple' => 'nipple piercings', 'piercingsvaginal' => 'genital piercings',
    ];
}

function sharmatDevicePromptDefaults(): array
{
    return [
        'device_actions' => 'Use device actions only when the current conversation calls for them, respecting the actual target relationship and established roles. Choose an exact offered target/device. A worn device does not establish ownership, submission, or agreement. Do not narrate an equipment change as completed until the game reports success. If blocked, acknowledge the reason instead of repeating the action.',
        'device_release' => 'Helping someone remove a device does not require romance or change your relationship. Respect their request and respond in your existing voice. A key and a removable device are still required; do not promise to remove protected devices.',
    ];
}

function sharmatDeviceCsv(string $csv): array
{
    return array_values(array_intersect(array_keys(sharmatDeviceTypes()), array_map('trim', explode(',', $csv))));
}

function sharmatDeviceSession(?array $write = null): array
{
    if (empty($GLOBALS['db'])) { return []; }
    try {
        if ($write !== null) {
            $value = $GLOBALS['db']->escape(json_encode($write));
            $GLOBALS['db']->execQuery("INSERT INTO conf_opts (id,value) VALUES ('aiagent_nsfw_dd_session','{$value}') ON CONFLICT (id) DO UPDATE SET value=EXCLUDED.value");
        }
        $row = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id='aiagent_nsfw_dd_session'");
        return json_decode($row['value'] ?? '{}', true) ?: [];
    } catch (Throwable $e) { return []; }
}

function sharmatDeviceReceive(string $event, string $payload): void
{
    $parts = explode('^', $payload);
    if ($event === 'ext_nsfw_ddsession') {
        if (count($parts) === 3 && $parts[0] === '2' && preg_match('/^[0-9.-]{1,80}$/D', $parts[1]) && in_array($parts[2], ['0', '1'], true)) {
            sharmatDeviceSession(['id' => $parts[1], 'available' => $parts[2] === '1']);
        }
        return;
    }
    $name = trim($parts[0] ?? '');
    if ($event !== 'ext_nsfw_devices' || $name === '' || preg_match('/[|@\r\n]/', $name)) { return; }
    $state = ['worn' => sharmatDeviceCsv($parts[1] ?? ''), 'ts' => time(), 'version' => 1];
    if (count($parts) === 6 && $parts[2] === '2') {
        $session = sharmatDeviceSession();
        if (empty($session['available']) || ($parts[3] ?? '') !== ($session['id'] ?? null)) { return; }
        $state += ['session' => $parts[3], 'lock' => sharmatDeviceCsv($parts[4]), 'unlock' => sharmatDeviceCsv($parts[5])];
        $state['version'] = 2;
    } elseif (count($parts) !== 2) { return; }
    $data = NsfwNpcData::get($name);
    $data['aiagent_nsfw_devices'] = implode(',', $state['worn']); // legacy consumers
    $data['aiagent_nsfw_device_state'] = $state;
    NsfwNpcData::save($name, $data);
}

function sharmatDeviceState(string $name): array
{
    $data = NsfwNpcData::get($name);
    $state = $data['aiagent_nsfw_device_state'] ?? [];
    $age = time() - (int)($state['ts'] ?? 0);
    if (!$state || $age < 0 || $age > 600) { return []; }
    $session = sharmatDeviceSession();
    if (($state['version'] ?? 0) === 2) {
        if (empty($session['available']) || ($state['session'] ?? '') !== ($session['id'] ?? null)) { return []; }
    } elseif ($session) { return []; } // a newer bridge loaded; discard earlier-save/legacy reports
    return $state;
}

function sharmatDevicePresentActors(): array
{
    $names = function_exists('getNormalizedNearbyDialogueListenerNames') ? (array)getNormalizedNearbyDialogueListenerNames(true) : [];
    $names[] = $GLOBALS['HERIKA_NAME'] ?? '';
    if (function_exists('aiagentNsfwPlayerPresentForAutonomy') && aiagentNsfwPlayerPresentForAutonomy()) { $names[] = $GLOBALS['PLAYER_NAME'] ?? ''; }
    return array_values(array_unique(array_filter(array_map('trim', $names), static function ($n) {
        return $n !== '' && !preg_match('/[|@^\r\n]| \/ /', $n) && !in_array(strtolower($n), ['the narrator', 'character', '(actor)'], true);
    })));
}

function sharmatDeviceEligible(string $speaker, string $target, bool $locking): bool
{
    if (getGlobalPrompt('dd_lock_unlock') !== '1' || !function_exists('herikaActionCatalogGetConfirmationCommandChannel')) { return false; }
    $present = sharmatDevicePresentActors();
    if (!in_array($speaker, $present, true) || !in_array($target, $present, true) || strcasecmp($speaker, $target) === 0) { return false; }
    if (!function_exists('aiagentNsfwIsProtectedNpc') || aiagentNsfwIsProtectedNpc($speaker) || aiagentNsfwIsProtectedNpc($target)) { return false; }
    if (function_exists('aiagentNsfwPlayerConflictActive') && aiagentNsfwPlayerConflictActive()) { return false; }
    foreach ([$speaker, $target] as $actor) {
        $scene = getIntimacyForActor($actor);
        if (!empty($scene['in_scene']) || !empty($scene['is_npc_scene']) || (int)($scene['level'] ?? 0) > 0) { return false; }
        if ($locking && !empty($scene['refused_until_scene_end'])) { return false; }
    }
    if (!$locking) { return true; }
    // A slave role is player-directed; it never grants authority to restrain the owner/other NPCs.
    if (isNpcSlave($speaker) || isNpcSlave($target)) { return false; }
    if (aiagentNsfwOpenMode()) { return true; }
    if (strcasecmp($target, $GLOBALS['PLAYER_NAME'] ?? '') === 0) {
        if (!aiagentNsfwOrientationAllowsPlayer($speaker)) { return false; }
        if (isProstitute($speaker)) {
            $scene = getIntimacyForActor($speaker);
            if (empty($scene['payment_confirmed']) && empty($scene['free_service']) && !aiagentNsfwProstitutePaymentWaived($speaker)) { return false; }
        }
        return getNpcAffinity($speaker, $target) >= aiagentNsfwSceneCallFloorFor($speaker) && aiagentNsfwRelTypeSexEligible($speaker);
    }
    // Both actors' existing initiation policies must agree; speech-style overrides are irrelevant.
    return aiagentNsfwNpcToNpcSexEligible($speaker, $target) && aiagentNsfwNpcToNpcSexEligible($target, $speaker);
}

function sharmatDeviceOffers(string $speaker, bool $locking): array
{
    if (getGlobalPrompt('dd_lock_unlock') !== '1' || !function_exists('herikaActionCatalogGetConfirmationCommandChannel')) { return []; }
    $code = $locking ? 'ExtCmdLockDevice' : 'ExtCmdUnlockDevice';
    if (!function_exists('herikaActionCatalogIsActionEnabled') || !herikaActionCatalogIsActionEnabled($code)) { return []; }
    if (!herikaActionCatalogRowIsAvailableInCurrentMode(herikaGetActionCatalogRow($code))) { return []; }
    $offers = [];
    foreach (sharmatDevicePresentActors() as $target) {
        $state = sharmatDeviceState($target);
        if (($state['version'] ?? 0) !== 2 || !sharmatDeviceEligible($speaker, $target, $locking)) { continue; }
        foreach ($state[$locking ? 'lock' : 'unlock'] ?? [] as $type) {
            if (isset(sharmatDeviceTypes()[$type])) { $offers[] = $target . ' / ' . $type; }
        }
    }
    return $offers;
}

function sharmatDeviceFilterActions(array $actions): array
{
    foreach ($actions as $index => $action) {
        $parts = explode('|', trim($action));
        $call = explode('@', $parts[2] ?? '');
        if (!in_array($call[0], ['ExtCmdLockDevice', 'ExtCmdUnlockDevice'], true)) { continue; }
        $locking = $call[0] === 'ExtCmdLockDevice';
        // Preserve CHIM's confirmation channel. Never normalize arbitrary names to a player fallback.
        if (count($parts) !== 3 || count($call) !== 2 || ($parts[0] ?? '') !== ($GLOBALS['HERIKA_NAME'] ?? '')
            || !in_array($parts[1], ['confirmcommand', 'approvedcommand'], true)
            || !in_array($call[1], sharmatDeviceOffers($parts[0], $locking), true)) { unset($actions[$index]); }
    }
    return array_values($actions);
}

function sharmatDeviceApplyTools(bool $register = false): void
{
    $speaker = $GLOBALS['HERIKA_NAME'] ?? '';
    foreach (['ExtCmdLockDevice' => 'LockDevice', 'ExtCmdUnlockDevice' => 'UnlockDevice'] as $code => $name) {
        $locking = $code === 'ExtCmdLockDevice';
        $offers = sharmatDeviceOffers($speaker, $locking);
        if ($register) {
            $GLOBALS['F_NAMES'][$code] = $name;
            $GLOBALS['F_TRANSLATIONS'][$code] = $locking ? 'Lock an existing device from the target inventory. Choose an exact offered target / device.' : 'Unlock a removable device using a matching key held by you or the wearer. Choose an exact offered target / device.';
            $GLOBALS['FUNCTIONS'][] = ['name' => $name, 'description' => $GLOBALS['F_TRANSLATIONS'][$code], 'parameters' => aiagent_nsfw_action_params($code)];
            $GLOBALS['FUNCSERV'][$code] = static function () { $GLOBALS['AVOID_LLM_CALL'] = false; };
            if ($offers) { $GLOBALS['ENABLED_FUNCTIONS'][] = $code; }
        }
        if (!$offers) {
            $GLOBALS['ENABLED_FUNCTIONS'] = array_values(array_diff($GLOBALS['ENABLED_FUNCTIONS'] ?? [], [$code]));
            if (isset($GLOBALS['FUNC_LIST'])) { $GLOBALS['FUNC_LIST'] = array_values(array_diff($GLOBALS['FUNC_LIST'], [$name])); }
        }
        foreach ($GLOBALS['FUNCTIONS'] ?? [] as $i => $fn) {
            if (($fn['name'] ?? '') === $name) { $GLOBALS['FUNCTIONS'][$i]['parameters']['properties']['target']['enum'] = $offers; }
        }
    }
    if (isset($GLOBALS['FUNC_LIST'])) {
        if (isset($GLOBALS['responseTemplate']['action'])) { $GLOBALS['responseTemplate']['action'] = implode('|', $GLOBALS['FUNC_LIST']); }
        if (isset($GLOBALS['structuredOutputTemplate']['json_schema']['schema']['properties']['action']['enum'])) {
            $GLOBALS['structuredOutputTemplate']['json_schema']['schema']['properties']['action']['enum'] = $GLOBALS['FUNC_LIST'];
        }
    }
}

function sharmatDeviceContext(string $speaker): string
{
    $lines = [];
    $names = sharmatDevicePresentActors();
    if (getGlobalPrompt('dd_awareness') === '1' && !aiagentNsfwIsProtectedNpc($speaker)) {
        $enabled = (string)getGlobalPrompt('dd_enabled_devices');
        $types = $enabled === '' ? array_keys(sharmatDeviceTypes()) : sharmatDeviceCsv($enabled);
        foreach ($names as $name) {
            if (aiagentNsfwIsProtectedNpc($name)) { continue; }
            $worn = array_intersect(sharmatDeviceState($name)['worn'] ?? [], $types);
            if (!$worn) { continue; }
            $phrases = array_map(static fn($type) => sharmatDeviceTypes()[$type], $worn);
            $lines[] = $name . ' is wearing ' . implode(', ', $phrases) . '.';
            if ($name === $speaker) {
                $lines[] = getGlobalPrompt('device_aware');
                $lines[] = getGlobalPrompt('device_refuse');
                if (in_array('gag', $worn, true) && getGlobalPrompt('dd_gag_muffle') === '1') { $lines[] = getGlobalPrompt('device_gag'); }
                if (getGlobalPrompt('dd_beg_keys') === '1') { $lines[] = getGlobalPrompt('device_beg'); }
            } elseif ($name === ($GLOBALS['PLAYER_NAME'] ?? '')) { $lines[] = getGlobalPrompt('device_player_aware'); }
        }
        if ($lines) { $lines[] = 'These are equipment facts only; they establish no ownership, consent, relationship, or change of role. Use the actual relationship and existing speaking style.'; }
    }
    $actionContextAdded = false;
    foreach (['device_actions' => true, 'device_release' => false] as $key => $locking) {
        $offers = sharmatDeviceOffers($speaker, $locking);
        if (!$offers) { continue; }
        if (!$actionContextAdded) {
            $lines[] = getGlobalPrompt('device_actions') ?: sharmatDevicePromptDefaults()['device_actions'];
            $actionContextAdded = true;
        }
        if (!$locking) { $lines[] = getGlobalPrompt('device_release') ?: sharmatDevicePromptDefaults()['device_release']; }
        $lines[] = ($locking ? 'LockDevice' : 'UnlockDevice') . ' available targets: ' . implode('; ', $offers) . '.';
    }
    return strtr(implode("\n", array_filter($lines)), ['#NPC_NAME#' => $speaker, '#PLAYER_NAME#' => $GLOBALS['PLAYER_NAME'] ?? 'the player']);
}
