<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/sharmat_updater_lib.php';

$assertions = 0;

function updaterAssert($condition, $message)
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function updaterWrite($path, $contents)
{
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
        throw new RuntimeException('Could not create test directory ' . $parent);
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Could not create test file ' . $path);
    }
}

function updaterBuildPackage($root)
{
    updaterWrite($root . '/manifest.json', json_encode([
        'name' => 'aiagent_nsfw',
        'git_repo' => 'Wondernuttz/Sharmat-Alpha',
        'version' => '9.9.9',
    ]));
    updaterWrite($root . '/dwemer-package.json', json_encode([
        'name' => 'aiagent_nsfw',
        'version' => '9.9.9',
        'server' => ['mutable_paths' => ['conf/conf.php', 'cmd/conf/conf.php']],
    ]));
    updaterWrite($root . '/config_manager.php', "<?php require_once __DIR__ . '/sharmat_updater_lib.php';");
    updaterWrite($root . '/sharmat_updater_lib.php', '<?php // updater support');
    updaterWrite($root . '/scene_role_policy.php', '<?php // runtime role policy');
    updaterWrite($root . '/config_section_defeat.php', '<?php // runtime defeat UI');
    updaterWrite($root . '/functions.php', '<?php // test');
    updaterWrite($root . '/nsfw_data.php', '<?php // test');
    updaterWrite($root . '/mod/version.txt', '9.9.9');
    updaterWrite($root . '/mod/AIAgentNSFW.esp', 'test plugin');
    updaterWrite($root . '/mod/Scripts/AIAgentNSFW.pex', 'test script');
    updaterWrite($root . '/mod/Source/ignored.bak', 'backup junk');
    updaterWrite($root . '/mod/meta.ini', 'manager junk');
    updaterWrite($root . '/conf/conf.php', '<?php $source = true;');
    updaterWrite($root . '/cmd/conf/conf.php', '<?php $sourceCmd = true;');
    updaterWrite($root . '/new.php', '<?php // new');
    for ($index = 0; $index < 15; $index++) {
        updaterWrite($root . '/data/file-' . $index . '.txt', 'payload-' . $index);
    }
    updaterWrite($root . '/.git/should-not-install', 'ignored');
}

$originalUmask = umask(0022);
$testRoot = _sharmatCreateUniqueDirectory(sys_get_temp_dir(), 'sharmat-updater-test-', 0700);
try {
    $firstUnique = _sharmatCreateUniqueDirectory($testRoot, 'unique-', 0700);
    $secondUnique = _sharmatCreateUniqueDirectory($testRoot, 'unique-', 0700);
    updaterAssert($firstUnique !== $secondUnique, 'temporary directories are unique');

    $sha = str_repeat('a', 40);
    $archiveSource = $testRoot . '/Wondernuttz-Sharmat-Alpha-' . substr($sha, 0, 7);
    updaterBuildPackage($archiveSource);
    $archivePath = $testRoot . '/package.zip';
    $archive = new ZipArchive();
    updaterAssert($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'test archive opens');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($archiveSource, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $rootName = basename($archiveSource);
    $archive->addEmptyDir($rootName);
    foreach ($iterator as $entry) {
        $relative = $rootName . '/' . str_replace('\\', '/', substr($entry->getPathname(), strlen($archiveSource) + 1));
        if ($entry->isDir()) {
            $archive->addEmptyDir($relative);
        } else {
            $archive->addFile($entry->getPathname(), $relative);
        }
    }
    updaterAssert($archive->close(), 'test archive closes');

    $extractedRoot = _sharmatExtractRepositoryArchive($archivePath, $testRoot . '/extract', $sha);
    updaterAssert(basename($extractedRoot) === $rootName, 'exact extracted repository root is selected');
    $mutable = _sharmatValidatePackageRoot($extractedRoot);
    updaterAssert(in_array('conf/conf.php', $mutable, true), 'main configuration is mutable');
    updaterAssert(in_array('cmd/conf/conf.php', $mutable, true), 'command configuration is mutable');
    _sharmatValidateStagedRuntime($extractedRoot);
    updaterAssert(true, 'required runtime files are present');
    rename($extractedRoot . '/scene_role_policy.php', $extractedRoot . '/scene_role_policy.missing');
    $missingRuntimeRejected = false;
    try {
        _sharmatValidateStagedRuntime($extractedRoot);
    } catch (RuntimeException $error) {
        $missingRuntimeRejected = strpos($error->getMessage(), 'scene_role_policy.php') !== false;
    }
    updaterAssert($missingRuntimeRejected, 'missing role-policy runtime file is rejected');
    rename($extractedRoot . '/scene_role_policy.missing', $extractedRoot . '/scene_role_policy.php');
    rename($extractedRoot . '/config_section_defeat.php', $extractedRoot . '/config_section_defeat.missing');
    $missingDefeatRuntimeRejected = false;
    try {
        _sharmatValidateStagedRuntime($extractedRoot);
    } catch (RuntimeException $error) {
        $missingDefeatRuntimeRejected = strpos($error->getMessage(), 'config_section_defeat.php') !== false;
    }
    updaterAssert($missingDefeatRuntimeRejected, 'missing defeat-UI runtime file is rejected');
    rename($extractedRoot . '/config_section_defeat.missing', $extractedRoot . '/config_section_defeat.php');
    rename($extractedRoot . '/sharmat_updater_lib.php', $extractedRoot . '/sharmat_updater_lib.missing');
    $missingDependencyRejected = false;
    try {
        _sharmatValidatePackageRoot($extractedRoot);
    } catch (RuntimeException $error) {
        $missingDependencyRejected = strpos($error->getMessage(), 'updater support code') !== false;
    }
    updaterAssert($missingDependencyRejected, 'package missing updater dependency is rejected');
    rename($extractedRoot . '/sharmat_updater_lib.missing', $extractedRoot . '/sharmat_updater_lib.php');

    $modArchivePath = $testRoot . '/game-mod.zip';
    $modFileCount = _sharmatBuildModArchive($extractedRoot . '/mod', $modArchivePath);
    updaterAssert($modFileCount === 3, 'game-mod archive contains only distributable files');
    $modArchive = new ZipArchive();
    updaterAssert($modArchive->open($modArchivePath) === true, 'game-mod archive opens');
    updaterAssert($modArchive->locateName('AIAgentNSFW.esp') !== false, 'game-mod archive contains the plugin');
    updaterAssert($modArchive->locateName('Scripts/AIAgentNSFW.pex') !== false, 'game-mod archive contains the compiled script');
    updaterAssert($modArchive->locateName('Source/ignored.bak') === false, 'game-mod archive excludes backup junk');
    updaterAssert($modArchive->locateName('meta.ini') === false, 'game-mod archive excludes mod-manager metadata');
    $modArchive->close();

    $current = $testRoot . '/current';
    updaterWrite($current . '/conf/conf.php', '<?php $custom = "preserved";');
    updaterWrite($current . '/cmd/conf/conf.php', '<?php $customCmd = "preserved";');
    updaterWrite($current . '/obsolete.php', '<?php // obsolete');
    $stage = $testRoot . '/stage';
    $stats = _sharmatPrepareStagedInstall($extractedRoot, $stage, $current, $mutable);
    updaterAssert($stats['copied'] >= 20, 'complete package is staged');
    updaterAssert($stats['preserved'] === 2, 'both mutable configuration files are preserved');
    updaterAssert($stats['php_linted'] >= 8, 'every staged PHP file is syntax checked');
    updaterAssert((fileperms($stage) & 0777) === 0775, 'stage root is group writable under umask 0022');
    updaterAssert((fileperms($stage . '/new.php') & 0777) === 0664, 'staged files are group writable under umask 0022');
    updaterAssert((fileperms($stage . '/data') & 0777) === 0775, 'staged directories are group writable under umask 0022');
    updaterAssert(filegroup($stage) === filegroup($current), 'stage inherits the live installation group');
    updaterAssert($stats['group_failed'] === 0, 'group normalization succeeds for a writable live group');
    updaterAssert(strpos(file_get_contents($stage . '/conf/conf.php'), 'preserved') !== false, 'custom configuration replaces repository default');
    updaterAssert(!file_exists($stage . '/obsolete.php'), 'deleted upstream files do not leak into staged install');
    updaterAssert(!file_exists($stage . '/.git/should-not-install'), 'repository metadata is excluded');

    $backup = $testRoot . '/backup';
    _sharmatSwapStagedInstall($stage, $current, $backup);
    updaterAssert(file_exists($current . '/new.php'), 'staged package becomes current install');
    updaterAssert(strpos(file_get_contents($current . '/conf/conf.php'), 'preserved') !== false, 'mutable configuration survives activation');
    updaterAssert(!file_exists($current . '/obsolete.php'), 'activation is a clean replacement, not an overlay');
    updaterAssert(file_exists($backup . '/obsolete.php'), 'previous install remains intact in backup');

    $badSource = $testRoot . '/bad-source';
    $badCopyCount = 0;
    $badCopyFailures = [];
    _sharmatCopyTreeChecked($current, $badSource, '', [], $badCopyCount, $badCopyFailures);
    updaterAssert(empty($badCopyFailures), 'invalid-PHP fixture copies cleanly before corruption');
    updaterWrite($badSource . '/broken.php', '<?php if (');
    $badPhpRejected = false;
    try {
        _sharmatPrepareStagedInstall($badSource, $testRoot . '/bad-stage', $current, $mutable);
    } catch (RuntimeException $error) {
        $badPhpRejected = strpos($error->getMessage(), 'PHP syntax validation failed') !== false;
    }
    updaterAssert($badPhpRejected, 'invalid staged PHP aborts before activation');

    _sharmatValidateUpdateRequest('POST', '1');
    updaterAssert(true, 'POST with custom same-origin header is accepted');
    $getRejected = false;
    try {
        _sharmatValidateUpdateRequest('GET', '1');
    } catch (RuntimeException $error) {
        $getRejected = true;
    }
    updaterAssert($getRejected, 'GET update request is rejected');
    $headerRejected = false;
    try {
        _sharmatValidateUpdateRequest('POST', '');
    } catch (RuntimeException $error) {
        $headerRejected = true;
    }
    updaterAssert($headerRejected, 'update without custom same-origin header is rejected');
    updaterAssert(_sharmatNormalizeCommitSha(strtoupper($sha)) === $sha, 'full installed commit SHA is normalized');
    updaterAssert(_sharmatNormalizeCommitSha('not-a-commit') === '', 'invalid installed commit SHA is ignored');

    $lockBase = _sharmatCreateUniqueDirectory($testRoot, 'lock-base-', 0770);
    $sharedOne = _sharmatAcquireOperationLock($lockBase, false);
    $sharedTwo = _sharmatAcquireOperationLock($lockBase, false);
    $exclusiveBlocked = false;
    try {
        _sharmatAcquireOperationLock($lockBase, true);
    } catch (RuntimeException $error) {
        $exclusiveBlocked = true;
    }
    updaterAssert($exclusiveBlocked, 'update lock cannot overlap game-mod downloads');
    _sharmatReleaseOperationLock($sharedTwo);
    _sharmatReleaseOperationLock($sharedOne);
    $exclusive = _sharmatAcquireOperationLock($lockBase, true);
    $downloadBlocked = false;
    try {
        _sharmatAcquireOperationLock($lockBase, false);
    } catch (RuntimeException $error) {
        $downloadBlocked = true;
    }
    updaterAssert($downloadBlocked, 'game-mod download cannot overlap an update');
    _sharmatReleaseOperationLock($exclusive);

    $webParent = $testRoot . '/web-parent';
    $documentRoot = $webParent . '/html';
    $fakeExtension = $documentRoot . '/HerikaServer/ext/aiagent_nsfw';
    $persistentTempRoot = $testRoot . '/persistent-temp';
    updaterWrite($fakeExtension . '/placeholder', 'installed');
    mkdir($persistentTempRoot, 0777, true);
    updaterAssert(symlink('/path/that/does/not/exist', $webParent . '/.sharmat_backups'), 'unsafe outside backup candidate fixture is created');
    $protectedBase = _sharmatResolveBackupBase($fakeExtension, $documentRoot, $persistentTempRoot);
    updaterAssert($protectedBase === $persistentTempRoot . '/sharmat_backups', 'fallback backup directory stays outside the web document root');
    updaterAssert(!file_exists($documentRoot . '/.sharmat_backups'), 'no backup fallback is created inside the web document root');
    updaterAssert((fileperms($protectedBase) & 0777) === 0770, 'fallback backup base is not world accessible');

    $manualBackup = $protectedBase . '/my-manual-backup';
    updaterWrite($manualBackup . '/keep.txt', 'manual');
    $unmarkedAutoName = 'aiagent_nsfw_auto_20260101_000000_' . str_repeat('f', 24);
    updaterWrite($protectedBase . '/' . $unmarkedAutoName . '/keep.txt', 'unmarked');
    for ($index = 0; $index < 7; $index++) {
        $name = 'aiagent_nsfw_auto_2026020' . ($index + 1) . '_010101_' . str_pad(dechex($index + 1), 24, '0', STR_PAD_LEFT);
        $automaticPath = $protectedBase . '/' . $name;
        updaterWrite($automaticPath . '/payload.txt', 'automatic');
        _sharmatMarkAutomaticBackup($automaticPath);
        touch($automaticPath, time() - (($index + 1) * 60));
    }
    $retention = _sharmatPruneAutomaticBackups($protectedBase, 3);
    updaterAssert($retention['removed'] === 4 && $retention['failed'] === 0, 'automatic backup retention is bounded');
    updaterAssert(file_exists($manualBackup . '/keep.txt'), 'manual backup is never pruned');
    updaterAssert(file_exists($protectedBase . '/' . $unmarkedAutoName . '/keep.txt'), 'unmarked backup is never pruned');

    $badArchivePath = $testRoot . '/traversal.zip';
    $badArchive = new ZipArchive();
    updaterAssert($badArchive->open($badArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'traversal test archive opens');
    $badArchive->addFromString('../escape.txt', 'blocked');
    $badArchive->close();
    $badArchive = new ZipArchive();
    updaterAssert($badArchive->open($badArchivePath) === true, 'traversal test archive reopens');
    $traversalRejected = false;
    try {
        _sharmatValidateZipEntries($badArchive);
    } catch (RuntimeException $error) {
        $traversalRejected = strpos($error->getMessage(), 'traversal') !== false;
    } finally {
        $badArchive->close();
    }
    updaterAssert($traversalRejected, 'archive path traversal is rejected');

    $rateMessage = _sharmatGitHubFailure('Update check failed', 403, '{"message":"rate limit"}', [
        'headers' => ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string)(time() + 60)],
    ]);
    updaterAssert(strpos($rateMessage, 'rate limit') !== false, 'rate-limit failure is explained to the user');

    $infoUi = file_get_contents(dirname(__DIR__) . '/config_section_info.php');
    updaterAssert(strpos($infoUi, 'Repair / Reinstall') !== false, 'repair and reinstall remains visible in the UI');
    updaterAssert(strpos($infoUi, "'X-SHARMAT-Update': '1'") !== false, 'update UI sends the custom confirmation header');
    updaterAssert(strpos($infoUi, 'runButton.disabled = true') !== false, 'update button is disabled while a request is running');
    updaterAssert(strpos($infoUi, "d.hint ? ' ' + d.hint") !== false, 'update failure hint is rendered in the UI');
    $configManagerSource = file_get_contents(dirname(__DIR__) . '/config_manager.php');
    updaterAssert(strpos($configManagerSource, "_sharmatNormalizeCommitSha(\$installedRow['value'] ?? '')") !== false, 'game-mod fallback reads the installed commit before using main');
} finally {
    umask($originalUmask);
    if (!_sharmatRemoveTree($testRoot)) {
        fwrite(STDERR, "Warning: could not remove updater test directory {$testRoot}\n");
    }
}

echo "SHARMAT updater regression tests passed ({$assertions} assertions).\n";
