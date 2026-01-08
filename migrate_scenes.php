<?php
/**
 * One-time migration script: Table -> JSONB
 * Run this to migrate existing scenes from ext_aiagentnsfw_scenes table to JSONB storage
 */

$enginePath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once $enginePath . "conf/conf.php";
require_once $enginePath . "lib/postgresql.class.php";
require_once __DIR__ . "/nsfw_data.php";

$db = new sql();
$GLOBALS["db"] = $db;

echo "=== NSFW Scenes Migration: Table -> JSONB ===\n\n";

// Check old table exists
$tableCheck = $db->fetchOne("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'ext_aiagentnsfw_scenes')");

if (!$tableCheck || !$tableCheck['exists']) {
    echo "ERROR: Table 'ext_aiagentnsfw_scenes' does not exist.\n";
    exit(1);
}

// Count rows in table
$countResult = $db->fetchOne("SELECT COUNT(*) as cnt FROM ext_aiagentnsfw_scenes");
$tableCount = $countResult['cnt'] ?? 0;
echo "Rows in table: $tableCount\n";

// Check current JSONB storage
$jsonbCheck = $db->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_scenes'");
$jsonbCount = 0;
if ($jsonbCheck && !empty($jsonbCheck['value'])) {
    $existing = json_decode($jsonbCheck['value'], true);
    $jsonbCount = count($existing);
}
echo "Scenes in JSONB: $jsonbCount\n\n";

if ($tableCount == 0) {
    echo "No scenes to migrate.\n";
    exit(0);
}

// Run migration
echo "Starting migration...\n";
$result = NsfwData::migrateFromTable();

if ($result['success']) {
    echo "SUCCESS: " . $result['message'] . "\n";

    // Verify
    $verifyCheck = $db->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_scenes'");
    if ($verifyCheck && !empty($verifyCheck['value'])) {
        $data = json_decode($verifyCheck['value'], true);
        echo "Verified: " . count($data) . " scenes now in JSONB storage.\n";
    }
} else {
    echo "ERROR: " . $result['error'] . "\n";
    exit(1);
}

echo "\nMigration complete!\n";
