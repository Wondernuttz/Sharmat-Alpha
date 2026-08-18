<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/sharmat_updater_lib.php';

$workspace = null;
try {
    [$sha] = _sharmatResolveLatestCommit();
    $workspace = _sharmatCreateUniqueDirectory(sys_get_temp_dir(), 'sharmat-network-test-', 0700);
    $repository = _sharmatDownloadExactRepository($sha, $workspace);
    $root = $repository['root'];
    $mutable = $repository['mutable_paths'];
    $gameModZip = $workspace . '/SHARMAT-GameMod.zip';
    $gameModFiles = _sharmatBuildModArchive($root . '/mod', $gameModZip);
    echo 'GitHub archive validated at ' . substr($sha, 0, 9)
        . '; root=' . basename($root)
        . '; mutable=' . implode(',', $mutable)
        . '; game_mod_files=' . $gameModFiles
        . PHP_EOL;
} finally {
    if ($workspace !== null && !_sharmatRemoveTree($workspace)) {
        fwrite(STDERR, "Warning: could not remove network-test directory {$workspace}\n");
    }
}
