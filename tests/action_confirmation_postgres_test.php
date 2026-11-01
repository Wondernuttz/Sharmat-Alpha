<?php
// php tests/action_confirmation_postgres_test.php /path/to/HerikaServer
// Run as a local PostgreSQL role permitted to create disposable databases.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$server = rtrim($argv[1] ?? '', '/');
if (!is_file($server . '/lib/core/action_catalog.php')) {
    throw new RuntimeException('Pass a CHIM server source directory');
}
$admin = pg_connect('host=/var/run/postgresql dbname=postgres', PGSQL_CONNECT_FORCE_NEW);
if (!$admin) { throw new RuntimeException('Local PostgreSQL connection failed'); }
$database = 'sharmat_confirmation_test_' . bin2hex(random_bytes(6));
$identifier = pg_escape_identifier($admin, $database);
if (!pg_query($admin, "CREATE DATABASE {$identifier}")) {
    throw new RuntimeException('Cannot create disposable database');
}
class ConfirmationTestDb {
    public function __construct(public $connection) {}
    public function execQuery($query) {
        $result = pg_query($this->connection, $query);
        if (!$result) { throw new RuntimeException(pg_last_error($this->connection)); }
        return $result;
    }
    public function fetchAll($query) { return pg_fetch_all($this->execQuery($query)) ?: []; }
    public function fetchOne($query) { return pg_fetch_assoc($this->execQuery($query)) ?: []; }
    public function escapeLiteral($value) { return pg_escape_literal($this->connection, $value); }
}
$checks = 0;
function checkConfirmation($condition, $message) {
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException($message); }
}
function reseedConfirmation() {
    unset($GLOBALS['AIAGENT_NSFW_CATALOG_SEEDED']);
    aiagent_nsfw_seed_action_catalog();
}
function confirmationRow($code) {
    return herikaGetActionCatalogRow($code);
}
try {
    $connection = pg_connect('host=/var/run/postgresql dbname=' . $database, PGSQL_CONNECT_FORCE_NEW);
    checkConfirmation(pg_dbname($connection) === $database, 'Only the newly created test database is used');
    $db = $GLOBALS['db'] = new ConfirmationTestDb($connection);
    // Use the installed schema for the two tables and the custom-over-base view.
    $schema = file_get_contents($server . '/data/database_default.sql');
    foreach (['core_action', 'core_action_custom'] as $table) {
        checkConfirmation((bool)preg_match('/CREATE TABLE public\.' . $table . ' \(.*?\n\);/s', $schema, $m), 'Action schema found');
        $db->execQuery($m[0]);
    }
    checkConfirmation((bool)preg_match('/CREATE VIEW public\.combined_core_action AS.*?;/s', $schema, $m), 'Combined view found');
    $db->execQuery($m[0]);
    require $server . '/lib/core/action_catalog.php';
    $GLOBALS['HERIKA_ACTION_CATALOG_DB_READY'] = true;
    require dirname(__DIR__) . '/catalog_seed.php';

    $starts = ['Hug', 'HoldHands', 'Kiss', 'StartSex', 'StartBlowJob', 'StartAnalSex', 'StartMassage',
        'StartThreesome', 'StartHandJobSex', 'StartTitfuck', 'DrinkBloodSex', 'StartSelfMasturbation', 'AcceptSex'];
    foreach ($starts as $suffix) {
        $code = 'ExtCmd' . $suffix;
        $row = confirmationRow($code);
        checkConfirmation(herikaActionCatalogRowSupportsConfirmation($row), $code . ' exposes confirmation');
        checkConfirmation(herikaActionCatalogGetConfirmationCommandChannel($row) === 'confirmcommand', $code . ' asks before dispatch');
        $config = herikaActionCatalogGetResolvedCustomConfig($code, $row);
        checkConfirmation($config['confirmation_required'] === true, $code . ' UI agrees with dispatch');
        checkConfirmation($row['metadata']['followup']['enabled'] === true, $code . ' retains follow-up');
    }
    foreach (['ExtCmdStopScene', 'ExtCmdRefuseSex', 'ExtCmdPutOnClothes', 'ExtCmdSlowPace'] as $code) {
        checkConfirmation(herikaActionCatalogGetConfirmationCommandChannel(confirmationRow($code)) === 'command', $code . ' remains automatic');
    }

    // Reproduce old stored rows, including saved custom Actions settings.
    $db->execQuery("UPDATE core_action SET game_function=FALSE, metadata=metadata-'confirmation'-'editor_fields'");
    foreach ([
        'ExtCmdStartSex' => ['custom_config' => ['confirmation_required' => false, 'followup_enabled' => false, 'unknown_setting' => 'keep']],
        'ExtCmdKiss' => ['custom_config' => ['followup_prompt' => 'Custom response', 'confirmation_policy' => 'default']],
        'ExtCmdHug' => ['custom_config' => ['confirmation_policy' => 'automatic']],
        'ExtCmdHoldHands' => ['custom_config' => ['confirmation_required' => true]],
        'ExtCmdDrinkBloodSex' => ['dispatch' => 'server_action'],
    ] as $code => $extra) {
        $db->execQuery('INSERT INTO core_action_custom (code_name,action_name,description,metadata,game_function)
            SELECT code_name,action_name,\'Customized description\',metadata || ' . herikaActionCatalogSqlJson($extra) . ',FALSE
            FROM core_action WHERE code_name=' . herikaActionCatalogSqlText($code));
    }
    $db->execQuery("UPDATE core_action_custom SET is_activated=FALSE WHERE code_name='ExtCmdStartSex'");
    $db->execQuery("INSERT INTO core_action_custom(code_name,action_name,game_function) VALUES ('ExtCmdOtherPlugin','Other',FALSE)");
    reseedConfirmation();
    $custom = confirmationRow('ExtCmdStartSex');
    checkConfirmation($custom['game_function'] === true && !$custom['is_activated'], 'Custom dispatch upgraded without re-enabling action');
    checkConfirmation($custom['description'] === 'Customized description', 'Custom description preserved');
    checkConfirmation($custom['metadata']['custom_config']['unknown_setting'] === 'keep', 'Unrelated custom configuration preserved');
    checkConfirmation($custom['metadata']['custom_config']['followup_enabled'] === false, 'Follow-up override preserved');
    checkConfirmation(herikaActionCatalogGetConfirmationCommandChannel($custom) === 'approvedcommand', 'Explicit opt-out preserved');
    checkConfirmation(herikaActionCatalogGetConfirmationCommandChannel(confirmationRow('ExtCmdKiss')) === 'confirmcommand', 'Old custom row gains default confirmation');
    checkConfirmation(herikaActionCatalogGetResolvedCustomConfig('ExtCmdKiss', confirmationRow('ExtCmdKiss'))['confirmation_required'] === true, 'Legacy default selector shows the same policy as dispatch');
    checkConfirmation(herikaActionCatalogGetConfirmationCommandChannel(confirmationRow('ExtCmdHug')) === 'approvedcommand', 'Legacy automatic policy preserved');
    checkConfirmation(herikaActionCatalogGetConfirmationCommandChannel(confirmationRow('ExtCmdHoldHands')) === 'confirmcommand', 'Explicit opt-in preserved');
    checkConfirmation(confirmationRow('ExtCmdDrinkBloodSex')['game_function'] === false, 'Custom server action left alone');
    checkConfirmation(confirmationRow('ExtCmdOtherPlugin')['game_function'] === false, 'Other extension left alone');
    $before = $db->fetchAll('SELECT * FROM core_action_custom ORDER BY code_name');
    reseedConfirmation();
    checkConfirmation($db->fetchAll('SELECT * FROM core_action_custom ORDER BY code_name') === $before, 'Custom upgrade is idempotent, including timestamps');

    // Execute the production serializer and SHARMAT post-filter together.
    $functions = file_get_contents($server . '/functions/functions.php');
    checkConfirmation((bool)preg_match('/^function queueFunctionExecutionCommand\(.*?^\}/ms', $functions, $m), 'CHIM serializer found');
    eval($m[0]);
    $plugin = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__) . '/functions.php'));
    $start = strpos($plugin, '$GLOBALS["action_post_process_fnct_ex"][]=function($actions) {');
    $end = strpos($plugin, '// Get configurable sex cooldown', $start);
    checkConfirmation($start !== false && $end !== false, 'SHARMAT post-filter found');
    function FindClosestNPCName($name) { return trim($name); }
    function aiagentNsfwIsProtectedNpc($name) { return $name === 'Excluded Actor'; }
    function aiagentNsfwNpcToNpcSexEligible($actor, $target) { return $target !== 'Ineligible Actor'; }
    function _getNsfwSetting($key, $default) { return $GLOBALS['test_legacy_affection'] ?? $default; }
    $GLOBALS['HERIKA_NAME'] = 'Test Speaker';
    $GLOBALS['PLAYER_NAME'] = 'Test Player';
    eval(substr($plugin, $start, $end - $start));
    $filter = end($GLOBALS['action_post_process_fnct_ex']);
    foreach ([['ExtCmdKiss', 'confirmcommand'], ['ExtCmdStartSex', 'approvedcommand'], ['ExtCmdStopScene', 'command']] as [$code, $channel]) {
        $buffer = $sent = [];
        $context = ['action_name' => $code, 'function_found' => true, 'function_code_name' => $code,
            'parameter_string' => 'Test Player', 'missing_required' => []];
        checkConfirmation(queueFunctionExecutionCommand($buffer, $sent, $context, 'test'), 'Command serialized');
        $filtered = $filter($buffer);
        checkConfirmation(trim($filtered[0]) === "Test Speaker|{$channel}|{$code}@Test Player", 'Channel and target survive post-filter');
        checkConfirmation(!queueFunctionExecutionCommand($buffer, $sent, $context, 'test'), 'Duplicate command suppressed');
    }
    checkConfirmation($filter(['Test Speaker|confirmcommand|ExtCmdStartSex@Ineligible Actor']) === [], 'Relationship rejection still drops confirmed-channel requests');
    checkConfirmation($filter(['Test Speaker|confirmcommand|ExtCmdKiss@Excluded Actor']) === [], 'Exclusions still enforced before confirmation');
    $GLOBALS['test_legacy_affection'] = true;
    checkConfirmation($filter(["Test Speaker|confirmcommand|ExtCmdKiss@Test Player\r\n"])[0] === 'Test Speaker|confirmcommand|ExtCmdKiss@Test Player@legacy', 'Legacy animation flag retains confirmation channel');
    echo "PASS: {$checks} action confirmation checks, using CHIM catalog/serializer and SHARMAT filter in a disposable database.\n";
} finally {
    if (isset($connection) && $connection) { pg_close($connection); }
    if (!pg_query($admin, "DROP DATABASE {$identifier}")) { throw new RuntimeException('Test database cleanup failed'); }
    pg_close($admin);
}
