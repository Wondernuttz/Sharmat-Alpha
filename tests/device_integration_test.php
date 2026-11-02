<?php
// Deterministic game-report, policy, prompt and dispatch fixtures; no live server data.
require dirname(__DIR__) . '/device_integration.php';
class DeviceFixtureDb {
    public array $session = [];
    public function escape($s) { return str_replace("'", "''", $s); }
    public function execQuery($sql) { preg_match("/VALUES \\('aiagent_nsfw_dd_session','(.*?)'\\)/", $sql, $m); $this->session = json_decode($m[1], true); }
    public function fetchOne($sql) { return ['value' => json_encode($this->session)]; }
}
class NsfwNpcData {
    public static array $data = [];
    public static function get($name) { return self::$data[$name] ?? []; }
    public static function save($name, $data) { self::$data[$name] = $data; }
}
function getGlobalPrompt($key) { return $GLOBALS['settings'][$key] ?? ''; }
function getNormalizedNearbyDialogueListenerNames($unused) { return $GLOBALS['nearby']; }
function aiagentNsfwPlayerPresentForAutonomy() { return $GLOBALS['player_present']; }
function aiagentNsfwIsProtectedNpc($name) { return in_array($name, $GLOBALS['protected'], true); }
function aiagentNsfwPlayerConflictActive() { return $GLOBALS['combat']; }
function getIntimacyForActor($name) { return $GLOBALS['scenes'][$name] ?? []; }
function isNpcSlave($name) { return in_array($name, $GLOBALS['slaves'], true); }
function isProstitute($name) { return in_array($name, $GLOBALS['workers'], true); }
function aiagentNsfwProstitutePaymentWaived($name) { return $GLOBALS['waived']; }
function aiagentNsfwOpenMode() { return $GLOBALS['open']; }
function aiagentNsfwOrientationAllowsPlayer($name) { return $GLOBALS['orientation']; }
function getNpcAffinity($npc, $target = null) { return $GLOBALS['affinity']; }
function aiagentNsfwSceneCallFloorFor($npc) { return $GLOBALS['floor']; }
function aiagentNsfwRelTypeSexEligible($npc) { return $GLOBALS['reltype']; }
function aiagentNsfwNpcToNpcSexEligible($a, $b) { $GLOBALS['pair_calls'][] = [$a, $b]; return !in_array([$a, $b], $GLOBALS['blocked_pairs'], true); }
function herikaActionCatalogGetConfirmationCommandChannel($row = []) { return 'confirmcommand'; }
function herikaActionCatalogIsActionEnabled($code) { return !in_array($code, $GLOBALS['disabled'], true); }
function herikaGetActionCatalogRow($code) { return ['code_name' => $code]; }
function herikaActionCatalogRowIsAvailableInCurrentMode($row) { return $GLOBALS['catalog_available']; }
function aiagent_nsfw_action_params($code) { return ['type' => 'object', 'properties' => ['target' => ['type' => 'string']], 'required' => ['target']]; }
function fixture() {
    $GLOBALS['db'] = new DeviceFixtureDb();
    NsfwNpcData::$data = [];
    foreach (['protected','slaves','workers','disabled','blocked_pairs','pair_calls','scenes','FUNCTIONS','ENABLED_FUNCTIONS'] as $k) { $GLOBALS[$k] = []; }
    $GLOBALS['settings'] = ['dd_awareness' => '1', 'dd_lock_unlock' => '1'];
    $GLOBALS['nearby'] = ['Lydia', 'Jenassa'];
    $GLOBALS['HERIKA_NAME'] = 'Lydia'; $GLOBALS['PLAYER_NAME'] = 'Player';
    foreach (['player_present','orientation','reltype','catalog_available'] as $k) { $GLOBALS[$k] = true; }
    foreach (['combat','open','waived'] as $k) { $GLOBALS[$k] = false; }
    $GLOBALS['affinity'] = 80; $GLOBALS['floor'] = 56;
    sharmatDeviceReceive('ext_nsfw_ddsession', '2^123.456^1');
    foreach (['Lydia','Jenassa','Player'] as $name) {
        NsfwNpcData::$data[$name] = ['speak_style' => 'filthy', 'custom' => 'keep'];
        sharmatDeviceReceive('ext_nsfw_devices', $name . '^collar,gag,^2^123.456^belt,armcuffs,^collar,gag,');
    }
}
$checks = 0;
function checkDevice($value, $why) { global $checks; ++$checks; if (!$value) { throw new RuntimeException($why); } }
fixture();
checkDevice(sharmatDeviceOffers('Lydia', true) === ['Jenassa / belt','Jenassa / armcuffs','Player / belt','Player / armcuffs'], 'Real nearby targets, no self-target');
checkDevice(in_array(['Lydia','Jenassa'], $GLOBALS['pair_calls'], true) && in_array(['Jenassa','Lydia'], $GLOBALS['pair_calls'], true), 'Relationship evaluated in both directions for actual NPC pair');
checkDevice(NsfwNpcData::get('Lydia')['custom'] === 'keep', 'Snapshots preserve profiles');
foreach (['confirmcommand','approvedcommand'] as $channel) {
    $action = "Lydia|{$channel}|ExtCmdLockDevice@Player / belt";
    checkDevice(sharmatDeviceFilterActions([$action]) === [$action], 'Confirmation channel preserved');
}
foreach (['Lydia|command|ExtCmdLockDevice@Player / belt','Jenassa|confirmcommand|ExtCmdLockDevice@Player / belt',
    'Lydia|confirmcommand|ExtCmdLockDevice@Player', 'Lydia|confirmcommand|ExtCmdLockDevice@Missing / belt',
    'Lydia|confirmcommand|ExtCmdLockDevice@Player / collar', 'Lydia|confirmcommand|ExtCmdLockDevice@Player / belt@extra',
    'Lydia|confirmcommand|ExtCmdLockDevice@Player / belt|extra', 'Lydia|confirmcommand|ExtCmdLockDevice@Player / unknown'] as $action) {
    checkDevice(sharmatDeviceFilterActions([$action]) === [], 'Malformed/unoffered command rejected: ' . $action);
}
checkDevice(sharmatDeviceFilterActions(['Lydia|command|ExtCmdHug@Player']) === ['Lydia|command|ExtCmdHug@Player'], 'Other actions untouched');
$GLOBALS['affinity'] = 8;
checkDevice(!in_array('Player / belt', sharmatDeviceOffers('Lydia', true), true), 'Low-affinity player lock blocked');
checkDevice(in_array('Player / collar', sharmatDeviceOffers('Lydia', false), true), 'Helpful unlock does not require romance');
$GLOBALS['blocked_pairs'] = [['Jenassa','Lydia']];
checkDevice(!in_array('Jenassa / belt', sharmatDeviceOffers('Lydia', true), true), 'Recipient NPC relationship also respected');
$GLOBALS['settings']['npc_scene_affinity_gate'] = '0'; NsfwNpcData::$data['Lydia']['npc_scene_affinity_gate'] = false;
checkDevice(!in_array('Jenassa / belt', sharmatDeviceOffers('Lydia', true), true), 'Dialogue style override cannot grant equipment permission');
fixture(); $GLOBALS['settings']['dd_lock_unlock'] = '0';
checkDevice(!sharmatDeviceOffers('Lydia', true) && !sharmatDeviceOffers('Lydia', false), 'Global off hides both');
checkDevice(str_contains(sharmatDeviceContext('Lydia'), 'Lydia is wearing'), 'Awareness independent of action toggle');
fixture(); $GLOBALS['settings']['dd_awareness'] = '0';
checkDevice(!str_contains(sharmatDeviceContext('Lydia'), 'is wearing') && sharmatDeviceOffers('Lydia', true), 'Actions independent of awareness toggle');
foreach (['Lydia','Jenassa','Player'] as $who) {
    fixture(); $GLOBALS['protected'] = [$who]; $GLOBALS['open'] = true;
    $offers = sharmatDeviceOffers('Lydia', true);
    checkDevice($who === 'Lydia' ? !$offers : !in_array($who . ' / belt', $offers, true), 'Protected actors blocked even in open mode');
}
foreach (['combat','catalog_available'] as $flag) {
    fixture(); $GLOBALS[$flag] = $flag === 'combat';
    checkDevice(!sharmatDeviceOffers('Lydia', true) && !sharmatDeviceOffers('Lydia', false), $flag . ' blocks actions');
}
fixture(); $GLOBALS['disabled'] = ['ExtCmdLockDevice'];
checkDevice(!sharmatDeviceOffers('Lydia', true) && sharmatDeviceOffers('Lydia', false), 'CHIM per-action disable honored');
foreach (['Lydia','Player'] as $who) {
    fixture(); $GLOBALS['scenes'][$who] = ['is_npc_scene' => true];
    checkDevice(!in_array('Player / belt', sharmatDeviceOffers('Lydia', true), true), 'Active scene blocks device edits');
    fixture(); $GLOBALS['scenes'][$who] = ['refused_until_scene_end' => true];
    checkDevice(!in_array('Player / belt', sharmatDeviceOffers('Lydia', true), true), 'Refusal respected');
}
fixture(); $GLOBALS['slaves'] = ['Lydia'];
checkDevice(!sharmatDeviceOffers('Lydia', true) && sharmatDeviceOffers('Lydia', false), 'Slave role does not authorize restraining owner; helping remove still allowed');
fixture(); $GLOBALS['workers'] = ['Lydia'];
checkDevice(!in_array('Player / belt', sharmatDeviceOffers('Lydia', true), true), 'Unpaid service remains gated');
$GLOBALS['scenes']['Lydia']['payment_confirmed'] = true;
checkDevice(in_array('Player / belt', sharmatDeviceOffers('Lydia', true), true), 'Confirmed payment read from existing field');
fixture(); $GLOBALS['player_present'] = false;
checkDevice(!in_array('Player / belt', sharmatDeviceOffers('Lydia', true), true), 'Absent player not offered');
fixture(); $GLOBALS['settings']['dd_enabled_devices'] = 'collar'; $GLOBALS['settings']['dd_gag_muffle'] = '1'; $GLOBALS['settings']['device_gag'] = 'GAG_SENTINEL';
$text = sharmatDeviceContext('Lydia');
checkDevice(str_contains($text, 'Player is wearing a collar.') && !str_contains($text, 'a gag') && !str_contains($text, 'GAG_SENTINEL'), 'Same awareness selection for player and NPC, including gag prompt');
checkDevice(!str_contains($text, 'marking') && str_contains($text, 'establish no ownership'), 'Collar does not invent ownership');
$GLOBALS['settings']['dd_enabled_devices'] = '__none__';
checkDevice(!str_contains(sharmatDeviceContext('Lydia'), 'is wearing'), 'All awareness types disabled');
fixture(); $GLOBALS['settings']['device_actions'] = 'CUSTOM #NPC_NAME# #PLAYER_NAME#';
checkDevice(str_contains(sharmatDeviceContext('Lydia'), 'CUSTOM Lydia Player'), 'Editable prompt and placeholders used');
fixture(); sharmatDeviceReceive('ext_nsfw_ddsession', '2^987.654^1');
checkDevice(!sharmatDeviceOffers('Lydia', true) && !str_contains(sharmatDeviceContext('Lydia'), 'is wearing'), 'Load session invalidates previous save snapshots');
sharmatDeviceReceive('ext_nsfw_devices', 'Player^gag^2^123.456^belt^gag');
checkDevice(!sharmatDeviceState('Player'), 'Delayed earlier-session report rejected');
sharmatDeviceReceive('ext_nsfw_devices', 'Player^^2^987.654^^');
checkDevice(sharmatDeviceState('Player')['worn'] === [], 'Empty report clears removed equipment');
fixture(); sharmatDeviceReceive('ext_nsfw_ddsession', '2^111.222^0');
checkDevice(!sharmatDeviceOffers('Lydia', true) && !sharmatDeviceState('Player'), 'Missing dependency disables actions and stale awareness');
fixture(); NsfwNpcData::$data['Player']['aiagent_nsfw_device_state']['ts'] -= 601;
checkDevice(!sharmatDeviceState('Player'), 'Stale snapshot rejected');
fixture(); $GLOBALS['db']->session = []; sharmatDeviceReceive('ext_nsfw_devices', 'Player^collar,unknown,');
checkDevice(sharmatDeviceState('Player')['worn'] === ['collar'] && !in_array('Player / belt', sharmatDeviceOffers('Lydia', true), true), 'Legacy reports give awareness only, never new commands');
fixture(); sharmatDeviceApplyTools(true);
checkDevice(count($GLOBALS['FUNCTIONS']) === 2 && count($GLOBALS['ENABLED_FUNCTIONS']) === 2, 'Both action schemas registered');
$GLOBALS['FUNC_LIST'] = ['Talk','LockDevice','UnlockDevice']; $GLOBALS['responseTemplate']['action'] = '';
$GLOBALS['structuredOutputTemplate']['json_schema']['schema']['properties']['action']['enum'] = [];
$GLOBALS['settings']['dd_lock_unlock'] = '0'; sharmatDeviceApplyTools();
checkDevice($GLOBALS['FUNC_LIST'] === ['Talk'] && $GLOBALS['ENABLED_FUNCTIONS'] === [], 'Late gate strips text connector tools');
checkDevice($GLOBALS['responseTemplate']['action'] === 'Talk' && $GLOBALS['structuredOutputTemplate']['json_schema']['schema']['properties']['action']['enum'] === ['Talk'], 'Late gate strips structured output tools');
echo "Device integration: {$checks} checks passed\n";
