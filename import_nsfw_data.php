<?php
/**
 * NSFW Data Importer
 * Run this in your browser to import all NSFW data (scenes, speak styles, tier prompts, settings)
 *
 * Browser: http://localhost/HerikaServer/ext/aiagent_nsfw/import_nsfw_data.php
 */

$enginePath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once $enginePath . "conf/conf.php";
require_once $enginePath . "lib/postgresql.class.php";

// Load the export data
$importDataFile = __DIR__ . '/nsfw_import_data.php';
if (!file_exists($importDataFile)) {
    die("ERROR: nsfw_import_data.php not found! Make sure it's in the same folder as this file.");
}

require_once $importDataFile;

if (!isset($NSFW_IMPORT_DATA) || empty($NSFW_IMPORT_DATA)) {
    die("ERROR: No data found in nsfw_import_data.php!");
}

$db = new sql();

header('Content-Type: text/plain');
echo "=== NSFW Data Importer ===\n\n";

$imported = 0;
$errors = 0;

foreach ($NSFW_IMPORT_DATA as $id => $value) {
    try {
        $escapedId = $db->escape($id);
        $escapedValue = $db->escape($value);

        // Check if exists
        $existing = $db->fetchOne("SELECT id FROM conf_opts WHERE id = '$escapedId'");

        if ($existing) {
            // Update
            $db->execQuery("UPDATE conf_opts SET value = '$escapedValue' WHERE id = '$escapedId'");
            echo "Updated: $id\n";
        } else {
            // Insert
            $db->execQuery("INSERT INTO conf_opts (id, value) VALUES ('$escapedId', '$escapedValue')");
            echo "Inserted: $id\n";
        }
        $imported++;
    } catch (Exception $e) {
        echo "ERROR on $id: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n=== DONE ===\n";
echo "Imported: $imported\n";
echo "Errors: $errors\n";

if ($errors == 0) {
    echo "\nSUCCESS! All NSFW data has been imported.\n";
    echo "You can now use the NSFW plugin.\n";
}
