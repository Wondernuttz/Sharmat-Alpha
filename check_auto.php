<?php
$enginePath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once $enginePath . "conf/conf.php";
require_once $enginePath . "lib/postgresql.class.php";
require_once __DIR__ . "/nsfw_data.php";

$db = new sql();
$GLOBALS["db"] = $db;

$scenes = NsfwData::getAllScenes();
$autoCount = 0;

foreach ($scenes as $stage => $data) {
    if ($stage === "auto" || strpos($stage, "auto") !== false) {
        echo "Found 'auto' in stage: $stage\n";
        $autoCount++;
    }
    if (isset($data["description"]) && $data["description"] === "auto") {
        echo "Found 'auto' in description for stage: $stage\n";
        $autoCount++;
    }
}

echo "\nTotal 'auto' entries found: $autoCount\n";
echo "Total scenes: " . count($scenes) . "\n";

// Show first few scenes
echo "\nFirst 5 scenes:\n";
$i = 0;
foreach ($scenes as $stage => $data) {
    if ($i++ >= 5) break;
    echo "  $stage => " . json_encode($data) . "\n";
}
