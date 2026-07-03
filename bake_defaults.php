<?php
// DEV TOOL: re-bake the shipped defaults from THIS server's live tuned values.
// Refreshes nsfw_import_data.php (blob seed), data/scene_defaults.json (scene catalog),
// and bumps the seed version in nsfw_data.php so every install re-merges the new
// defaults on next page load (user edits always stay on top - see nsfw_auto_init).
// RUN: php bake_defaults.php   (CLI only; refuses names/IP leaks and aborts on any hit)

if (php_sapi_name() !== 'cli') { die("CLI only\n"); }

$enginePath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once $enginePath . "conf/conf.php";
require_once $enginePath . "lib/postgresql.class.php";
$GLOBALS['db'] = new sql();
require_once __DIR__ . "/nsfw_data.php";

// Export the same keys the current bake ships
require __DIR__ . '/nsfw_import_data.php';
$keys = array_keys($NSFW_IMPORT_DATA ?? []);
if (empty($keys)) { die("ERROR: current nsfw_import_data.php has no keys\n"); }

$export = [];
foreach ($keys as $key) {
    $row = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id = '" . $GLOBALS['db']->escape($key) . "'");
    if (!$row || $row['value'] === null || $row['value'] === '') {
        die("ABORT: live value for '$key' is missing/empty - refusing to bake a hole into the seed\n");
    }
    $export[$key] = $row['value'];
}

// Sanitize gate: never ship personal/game data in defaults
$blob = implode("\n", $export);
if (preg_match('/borja|borjajf|bannon|172\.25\.|lisette|vivienne onis|sorex vinius|corpulus/i', $blob, $m)) {
    die("ABORT: sanitize hit '" . $m[0] . "' in exported defaults - clean the live value first\n");
}

$stamp = date('Y-m-d');
$out = "<?php\n// Auto-generated NSFW data export - baked from live tuned values {$stamp}\n"
     . "// This file is the FRESH-INSTALL SEED and the RESET-TO-DEFAULTS source. Regenerate via bake_defaults.php, do not hand-edit.\n\n"
     . '$NSFW_IMPORT_DATA = ' . var_export($export, true) . ";\n";
file_put_contents(__DIR__ . '/nsfw_import_data.php', $out);
echo "baked nsfw_import_data.php (" . count($export) . " blobs, " . strlen($out) . " bytes)\n";

// Refresh the shipped scene catalog from the live scene store
$sceneCount = NsfwData::snapshotSceneDefaults();
echo "baked data/scene_defaults.json ({$sceneCount} scenes)\n";

// Bump the seed version so every install re-merges on next load
$nd = __DIR__ . '/nsfw_data.php';
$src = file_get_contents($nd);
$newVersion = date('Ymd') . sprintf('%03d', (int)substr((string)time(), -3) % 999);
$src2 = preg_replace("/\\\$seedVersion = '\\d+';/", "\$seedVersion = '{$newVersion}';", $src, 1, $n);
if ($n === 1) {
    file_put_contents($nd, $src2);
    echo "seed version bumped -> {$newVersion}\n";
} else {
    echo "WARNING: could not bump seed version automatically - bump \$seedVersion in nsfw_data.php by hand\n";
}
echo "DONE - sync + commit + push to ship these defaults to all installs\n";
