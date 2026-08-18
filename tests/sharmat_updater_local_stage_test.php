<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/sharmat_updater_lib.php';

$sourceRoot = dirname(__DIR__);
$workspace = null;
try {
    $workspace = _sharmatCreateUniqueDirectory(sys_get_temp_dir(), 'sharmat-local-stage-test-', 0700);
    $mutable = _sharmatValidatePackageRoot($sourceRoot);
    $stage = $workspace . '/stage';
    $currentRoot = _sharmatCreateUniqueDirectory($workspace, 'current-', 0775);
    foreach ($mutable as $relative) {
        $source = $sourceRoot . '/' . $relative;
        $destination = $currentRoot . '/' . $relative;
        if (is_file($source)) {
            if (!is_dir(dirname($destination))) { mkdir(dirname($destination), 0775, true); }
            copy($source, $destination);
        }
    }
    $stats = _sharmatPrepareStagedInstall($sourceRoot, $stage, $currentRoot, $mutable);
    echo 'Current working package staged successfully; files=' . $stats['copied']
        . '; mutable=' . $stats['preserved']
        . '; php_linted=' . $stats['php_linted']
        . '; group_failed=' . $stats['group_failed']
        . PHP_EOL;
} finally {
    if ($workspace !== null && !_sharmatRemoveTree($workspace)) {
        fwrite(STDERR, "Warning: could not remove local-stage test directory {$workspace}\n");
    }
}
