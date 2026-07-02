<?php
/**
 * SHARMAT-only destructive reset.
 *
 * This file deliberately uses an allowlist. It must not touch CHIM core NPC,
 * relationship, memory, event, or unrelated conf_opts data.
 */

if (!function_exists('sharmat_reset_all_data')) {

function sharmat_reset_quote_ident($identifier)
{
    if (!is_string($identifier) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
        throw new Exception('Unsafe SQL identifier rejected: ' . (string)$identifier);
    }
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function sharmat_reset_exec($sql)
{
    $result = $GLOBALS['db']->execQuery($sql);
    if ($result === false) {
        throw new Exception('SHARMAT reset SQL failed');
    }
    return $result;
}

function sharmat_reset_bool($value)
{
    return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
}

function sharmat_reset_table_exists($schema, $table)
{
    $schemaLit = $GLOBALS['db']->escapeLiteral($schema);
    $tableLit = $GLOBALS['db']->escapeLiteral($table);
    $row = $GLOBALS['db']->fetchOne(
        "SELECT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = {$schemaLit}
              AND table_name = {$tableLit}
        ) AS exists"
    );
    return $row && sharmat_reset_bool($row['exists'] ?? false);
}

function sharmat_reset_count_table($schema, $table)
{
    if (!sharmat_reset_table_exists($schema, $table)) {
        return 0;
    }

    $qualified = sharmat_reset_quote_ident($schema) . '.' . sharmat_reset_quote_ident($table);
    $row = $GLOBALS['db']->fetchOne("SELECT COUNT(*) AS cnt FROM {$qualified}");
    return (int)($row['cnt'] ?? 0);
}

function sharmat_reset_managed_schemas()
{
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT schema_name
         FROM information_schema.schemata
         WHERE schema_name = 'public'
            OR LEFT(schema_name, 13) = 'chim_profile_'
         ORDER BY CASE WHEN schema_name = 'public' THEN 0 ELSE 1 END, schema_name"
    );

    $schemas = [];
    foreach ($rows as $row) {
        $schema = $row['schema_name'] ?? '';
        if ($schema === 'public' || strpos($schema, 'chim_profile_') === 0) {
            sharmat_reset_quote_ident($schema);
            $schemas[] = $schema;
        }
    }
    return $schemas;
}

function sharmat_reset_owned_tables()
{
    return [
        'nsfw_npc_data',
        'nsfw_profile_queue',
        'ext_aiagentnsfw_scenes',
        'ext_aiagentnsfw_scenes_deprecated',
        'ext_aiagentnsfw_speak_styles',
    ];
}

function sharmat_reset_seed_data()
{
    $importFile = __DIR__ . '/nsfw_import_data.php';
    if (!file_exists($importFile)) {
        throw new Exception('nsfw_import_data.php not found; cannot reseed SHARMAT defaults');
    }

    $NSFW_IMPORT_DATA = [];
    require $importFile;
    if (!isset($NSFW_IMPORT_DATA) || !is_array($NSFW_IMPORT_DATA) || empty($NSFW_IMPORT_DATA)) {
        throw new Exception('nsfw_import_data.php did not provide SHARMAT seed data');
    }

    $seed = $NSFW_IMPORT_DATA;

    if (function_exists('nsfw_default_ai_prompt_template')) {
        $seed['aiagent_nsfw_ai_prompt_template'] = nsfw_default_ai_prompt_template();
    }

    if (function_exists('nsfw_default_prompt_overrides')) {
        $promptDefaults = json_decode($seed['aiagent_nsfw_prompts'] ?? '{}', true);
        if (is_array($promptDefaults)) {
            $promptDefaults = array_replace($promptDefaults, nsfw_default_prompt_overrides());
            if (function_exists('nsfw_legacy_pricing_modifier_keys')) {
                foreach (nsfw_legacy_pricing_modifier_keys() as $legacyKey) {
                    unset($promptDefaults[$legacyKey]);
                }
            }
            $seed['aiagent_nsfw_prompts'] = json_encode(
                $promptDefaults,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }
    }

    if (function_exists('nsfw_default_settings_config')) {
        $seed['aiagent_nsfw_settings'] = json_encode(
            nsfw_default_settings_config(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    if (function_exists('nsfw_default_reltypes_config')) {
        $seed['aiagent_nsfw_reltypes'] = json_encode(
            nsfw_default_reltypes_config(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    $seed['aiagent_nsfw_auto_initialized'] = 'true';
    $seed['aiagent_nsfw_seed_version'] = '20260627003';
    $seed['aiagent_nsfw_reset_version'] = '20260625001';

    return $seed;
}

function sharmat_reset_backup_owned_data($schemas, $ownedTables)
{
    $backup = [
        'created_at' => date('c'),
        'scope' => 'SHARMAT-owned data only',
        'schemas' => [],
    ];

    foreach ($schemas as $schema) {
        $schemaBackup = [
            'conf_opts' => [],
            'tables' => [],
        ];
        $qSchema = sharmat_reset_quote_ident($schema);

        if (sharmat_reset_table_exists($schema, 'conf_opts')) {
            $schemaBackup['conf_opts'] = $GLOBALS['db']->fetchAll(
                "SELECT id, value
                 FROM {$qSchema}.conf_opts
                 WHERE LEFT(id, 13) = 'aiagent_nsfw_'
                 ORDER BY id"
            );
        }

        foreach ($ownedTables as $table) {
            if (!sharmat_reset_table_exists($schema, $table)) {
                continue;
            }
            $qualified = $qSchema . '.' . sharmat_reset_quote_ident($table);
            $schemaBackup['tables'][$table] = $GLOBALS['db']->fetchAll("SELECT * FROM {$qualified}");
        }

        $backup['schemas'][$schema] = $schemaBackup;
    }

    $backupDir = __DIR__ . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true)) {
        throw new Exception('Could not create SHARMAT reset backup directory');
    }
    if (!is_writable($backupDir)) {
        throw new Exception('SHARMAT reset backup directory is not writable');
    }

    $backupFile = $backupDir . '/sharmat-reset-' . date('Ymd-His') . '.json';
    $json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($backupFile, $json) === false) {
        throw new Exception('Could not write SHARMAT reset backup file');
    }

    return $backupFile;
}

function sharmat_reset_stop_profile_worker()
{
    $enginePath = $GLOBALS['ENGINE_PATH'] ?? (realpath(__DIR__ . '/../../') . '/');
    $pidFile = $enginePath . 'log/nsfw_profile_worker.pid';
    $stopped = false;

    if (is_file($pidFile)) {
        $pid = (int)trim((string)@file_get_contents($pidFile));
        if ($pid > 0 && function_exists('posix_kill')) {
            $stopped = @posix_kill($pid, 15) || $stopped;
        }
        @unlink($pidFile);
    }

    return $stopped;
}

function sharmat_reset_clear_temp_files()
{
    $deleted = 0;
    foreach ([sys_get_temp_dir() . '/nsfw_*', sys_get_temp_dir() . '/aiagent_nsfw_*'] as $pattern) {
        foreach (glob($pattern) ?: [] as $path) {
            if ((is_file($path) || is_link($path)) && @unlink($path)) {
                $deleted++;
            }
        }
    }
    return $deleted;
}

function sharmat_reset_create_runtime_tables($schema)
{
    $qSchema = sharmat_reset_quote_ident($schema);
    $npcTable = $qSchema . '.' . sharmat_reset_quote_ident('nsfw_npc_data');
    $queueTable = $qSchema . '.' . sharmat_reset_quote_ident('nsfw_profile_queue');

    sharmat_reset_exec("
        CREATE TABLE {$npcTable} (
            npc_name TEXT PRIMARY KEY,
            extended_data JSONB DEFAULT '{}'::jsonb,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    sharmat_reset_exec("
        CREATE INDEX idx_nsfw_npc_data_normalized_name
        ON {$npcTable} (LOWER(REPLACE(npc_name, '_', ' ')))
    ");

    sharmat_reset_exec("
        CREATE TABLE {$queueTable} (
            id SERIAL PRIMARY KEY,
            npc_name VARCHAR(255) NOT NULL,
            profile_id INTEGER NOT NULL DEFAULT 0,
            queue_data JSONB NOT NULL,
            created_at TIMESTAMP DEFAULT NOW(),
            retry_count INTEGER DEFAULT 0,
            last_error TEXT,
            UNIQUE(npc_name, profile_id)
        )
    ");
}

function sharmat_reset_seed_schema($schema, $seed)
{
    if (!sharmat_reset_table_exists($schema, 'conf_opts')) {
        return 0;
    }

    $qConf = sharmat_reset_quote_ident($schema) . '.conf_opts';
    $count = 0;

    foreach ($seed as $id => $value) {
        $idLit = $GLOBALS['db']->escapeLiteral((string)$id);
        $valueLit = $GLOBALS['db']->escapeLiteral((string)$value);
        sharmat_reset_exec("
            INSERT INTO {$qConf} (id, value)
            VALUES ({$idLit}, {$valueLit})
            ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value
        ");
        $count++;
    }

    return $count;
}

function sharmat_reset_all_data()
{
    if (!isset($GLOBALS['db']) || !$GLOBALS['db']) {
        throw new Exception('Database connection is not available');
    }

    $schemas = sharmat_reset_managed_schemas();
    if (empty($schemas)) {
        throw new Exception('No managed CHIM schemas found for SHARMAT reset');
    }

    $ownedTables = sharmat_reset_owned_tables();
    $seed = sharmat_reset_seed_data();
    $workerStopped = sharmat_reset_stop_profile_worker();
    $backupFile = sharmat_reset_backup_owned_data($schemas, $ownedTables);

    $result = [
        'backup_file' => $backupFile,
        'worker_stopped' => $workerStopped,
        'schemas' => [],
        'temp_files_deleted' => 0,
    ];

    sharmat_reset_exec('BEGIN');
    try {
        foreach ($schemas as $schema) {
            $qSchema = sharmat_reset_quote_ident($schema);
            $summary = [
                'conf_opts_deleted' => 0,
                'tables_dropped' => [],
                'runtime_tables_created' => [],
                'conf_opts_seeded' => 0,
            ];

            if (sharmat_reset_table_exists($schema, 'conf_opts')) {
                $row = $GLOBALS['db']->fetchOne(
                    "SELECT COUNT(*) AS cnt
                     FROM {$qSchema}.conf_opts
                     WHERE LEFT(id, 13) = 'aiagent_nsfw_'"
                );
                $summary['conf_opts_deleted'] = (int)($row['cnt'] ?? 0);
                sharmat_reset_exec("DELETE FROM {$qSchema}.conf_opts WHERE LEFT(id, 13) = 'aiagent_nsfw_'");
            }

            foreach ($ownedTables as $table) {
                if (sharmat_reset_table_exists($schema, $table)) {
                    $summary['tables_dropped'][$table] = sharmat_reset_count_table($schema, $table);
                }
                sharmat_reset_exec("DROP TABLE IF EXISTS {$qSchema}." . sharmat_reset_quote_ident($table));
            }

            sharmat_reset_create_runtime_tables($schema);
            $summary['runtime_tables_created'] = ['nsfw_npc_data', 'nsfw_profile_queue'];
            $summary['conf_opts_seeded'] = sharmat_reset_seed_schema($schema, $seed);
            $result['schemas'][$schema] = $summary;
        }

        sharmat_reset_exec('COMMIT');
    } catch (Exception $e) {
        $GLOBALS['db']->execQuery('ROLLBACK');
        throw $e;
    }

    $result['temp_files_deleted'] = sharmat_reset_clear_temp_files();

    require_once __DIR__ . '/catalog_seed.php';
    if (function_exists('aiagent_nsfw_seed_action_catalog')) {
        aiagent_nsfw_seed_action_catalog();
    }

    return $result;
}

function sharmat_handle_reset_request()
{
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new Exception('SHARMAT reset requires POST');
        }

        $confirmation = trim((string)($_POST['confirmation'] ?? ''));
        if ($confirmation !== 'CLEAR_SHARMAT_DATA') {
            throw new Exception('Confirmation phrase did not match');
        }

        $result = sharmat_reset_all_data();
        echo json_encode([
            'success' => true,
            'message' => 'All SHARMAT data was cleared and default SHARMAT data was rebuilt.',
            'data' => $result,
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
    exit;
}

}
