<?php
/**
 * Rename old table to deprecated
 */

$enginePath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once $enginePath . "conf/conf.php";
require_once $enginePath . "lib/postgresql.class.php";

$db = new sql();

// Check if table exists
$check = $db->fetchOne("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'ext_aiagentnsfw_scenes')");

if ($check && $check['exists']) {
    $db->execQuery("ALTER TABLE ext_aiagentnsfw_scenes RENAME TO ext_aiagentnsfw_scenes_deprecated");
    echo "Table renamed to ext_aiagentnsfw_scenes_deprecated\n";
} else {
    echo "Table ext_aiagentnsfw_scenes does not exist (may already be renamed)\n";
}
