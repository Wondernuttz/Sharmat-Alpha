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

$releaseManifest = ['version' => '3.1.9.5', 'mod_download_url' => 'https://github.com/Wondernuttz/Sharmat-Alpha/releases/download/v3.1.9.5/SHARMAT-3.1.9.5.zip'];
updaterAssert(_sharmatReleaseModDownloadUrl($releaseManifest) === $releaseManifest['mod_download_url'], 'matching release download is accepted');
$releaseManifest['version'] = '3.1.9.6';
updaterAssert(_sharmatReleaseModDownloadUrl($releaseManifest) === '', 'stale release URL is rejected');
$releaseManifest['version'] = '3.1.9.5';
$releaseManifest['mod_download_url'] = 'https://example.com/unrelated.zip';
updaterAssert(_sharmatReleaseModDownloadUrl($releaseManifest) === '', 'unrelated redirect is rejected');
updaterAssert(_sharmatReleaseModDownloadUrl([]) === '', 'legacy manifest keeps existing fallback');

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
    updaterWrite($root . '/config_manager.php', "<?php require_once __DIR__ . '/sharmat_updater_lib.php'; require_once __DIR__ . '/device_integration.php';");
    updaterWrite($root . '/device_integration.php', '<?php // DD integration');
    updaterWrite($root . '/sharmat_updater_lib.php', '<?php // updater support');
    updaterWrite($root . '/scene_role_policy.php', '<?php // runtime role policy');
    updaterWrite($root . '/drunk_animation_bridge.php', '<?php // drunk animation bridge');
    updaterWrite($root . '/npc_plugin_data.php', '<?php // NPC data mirror');
    updaterWrite($root . '/config_section_defeat.php', '<?php // runtime defeat UI');
    updaterWrite($root . '/functions.php', '<?php // test');
    updaterWrite($root . '/nsfw_data.php', '<?php // test');
    updaterWrite($root . '/mod/version.txt', '9.9.9');
    updaterWrite($root . '/mod/AIAgentNSFW.esp', 'test plugin');
    updaterWrite($root . '/mod/Scripts/AIAgentNSFW.pex', 'test script');
    updaterWrite($root . '/mod/Scripts/AIAgentNSFWPlayerAlias.pex', 'test alias script');
    updaterWrite($root . '/mod/Scripts/AIAgentNSFWSceneEngine.pex', 'test scene engine');
    updaterWrite($root . '/mod/Scripts/AIAgentNSFWOStim.pex', 'test OStim adapter');
    updaterWrite($root . '/mod/Scripts/AIAgentNSFWSexLab.pex', 'test SexLab adapter');
    updaterWrite($root . '/mod/Scripts/AIAgentNSFWDevices.pex', 'test DD adapter');
    updaterWrite($root . '/mod/Scripts/AIAgentVRItems.pex', 'test VR script');
    updaterWrite($root . '/mod/Seq/AIAgentNSFW.seq', 'test sequence');
    updaterWrite($root . '/mod/SKSE/Plugins/StorageUtilData/SHARMAT_scene_framework.json', '{"ostim":1,"sexlab":1}');
    updaterWrite($root . '/mod/README.txt', 'Installation instructions');
    updaterWrite($root . '/mod/meshes/actors/character/animations/OpenAnimationReplacer/Drunk animations/Drunk animations/config.json', '{}');
    updaterWrite($root . '/mod/Source/Scripts/AIAgentNSFWSceneEngine.psc', 'development source');
    updaterWrite($root . '/mod/Scripts/unused.pex', 'unused script');
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
    rename($extractedRoot . '/device_integration.php', $extractedRoot . '/device_integration.missing');
    $missingDevicesRejected = false;
    try { _sharmatValidateStagedRuntime($extractedRoot); }
    catch (RuntimeException $error) { $missingDevicesRejected = strpos($error->getMessage(), 'device_integration.php') !== false; }
    finally { rename($extractedRoot . '/device_integration.missing', $extractedRoot . '/device_integration.php'); }
    updaterAssert($missingDevicesRejected, 'Missing DD server module prevents partial installation');
    rename($extractedRoot . '/drunk_animation_bridge.php', $extractedRoot . '/drunk_animation_bridge.missing');
    $missingDrunkBridgeRejected = false;
    try {
        _sharmatValidateStagedRuntime($extractedRoot);
    } catch (RuntimeException $error) {
        $missingDrunkBridgeRejected = strpos($error->getMessage(), 'drunk_animation_bridge.php') !== false;
    }
    updaterAssert($missingDrunkBridgeRejected, 'missing drunk bridge runtime is rejected');
    rename($extractedRoot . '/drunk_animation_bridge.missing', $extractedRoot . '/drunk_animation_bridge.php');
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
    updaterAssert($modFileCount === 13, 'game-mod archive contains the complete runtime payload');
    $modArchive = new ZipArchive();
    updaterAssert($modArchive->open($modArchivePath) === true, 'game-mod archive opens');
    updaterAssert($modArchive->locateName('meshes/actors/character/animations/OpenAnimationReplacer/Drunk animations/Drunk animations/config.json') !== false, 'game-mod archive contains the drunk compatibility condition');
    updaterAssert($modArchive->locateName('AIAgentNSFW.esp') !== false, 'game-mod archive contains the plugin');
    updaterAssert($modArchive->locateName('Scripts/AIAgentNSFW.pex') !== false, 'game-mod archive contains the compiled script');
    updaterAssert($modArchive->locateName('Scripts/AIAgentNSFWSceneEngine.pex') !== false, 'game-mod archive contains the scene engine');
    updaterAssert($modArchive->getFromName('Scripts/AIAgentNSFWOStim.pex') === 'test OStim adapter', 'game-mod archive preserves the OStim adapter');
    updaterAssert($modArchive->getFromName('Scripts/AIAgentNSFWSexLab.pex') === 'test SexLab adapter', 'game-mod archive preserves the SexLab adapter');
    updaterAssert($modArchive->locateName('Source/Scripts/AIAgentNSFWSceneEngine.psc') === false, 'game-mod archive excludes development source');
    updaterAssert($modArchive->locateName('Scripts/unused.pex') === false, 'game-mod archive excludes unused scripts');
    updaterAssert($modArchive->locateName('Source/ignored.bak') === false, 'game-mod archive excludes backup junk');
    updaterAssert($modArchive->locateName('meta.ini') === false, 'game-mod archive excludes mod-manager metadata');
    $modArchive->close();

    $drunkConfigPath = $extractedRoot . '/mod/meshes/actors/character/animations/OpenAnimationReplacer/Drunk animations/Drunk animations/config.json';
    rename($drunkConfigPath, $drunkConfigPath . '.missing');
    $missingDrunkConfigRejected = false;
    try {
        _sharmatBuildModArchive($extractedRoot . '/mod', $testRoot . '/missing-drunk-config.zip');
    } catch (RuntimeException $error) {
        $missingDrunkConfigRejected = strpos($error->getMessage(), 'Drunk animations/config.json') !== false;
    }
    updaterAssert($missingDrunkConfigRejected, 'game-mod download rejects a missing drunk compatibility config');
    rename($drunkConfigPath . '.missing', $drunkConfigPath);

    $sceneEnginePath = $extractedRoot . '/mod/Scripts/AIAgentNSFWSceneEngine.pex';
    rename($sceneEnginePath, $sceneEnginePath . '.missing');
    $missingSceneEngineRejected = false;
    try {
        _sharmatBuildModArchive($extractedRoot . '/mod', $testRoot . '/incomplete-mod.zip');
    } catch (RuntimeException $error) {
        $missingSceneEngineRejected = strpos($error->getMessage(), 'AIAgentNSFWSceneEngine.pex') !== false;
    }
    updaterAssert($missingSceneEngineRejected, 'game-mod download rejects a missing scene engine');
    updaterAssert(!file_exists($testRoot . '/incomplete-mod.zip'), 'incomplete mod is not offered as an archive');
    rename($sceneEnginePath . '.missing', $sceneEnginePath);

    updaterWrite($extractedRoot . '/mod/version.txt', '3.1.0');
    updaterAssert(_sharmatBuildModArchive($extractedRoot . '/mod', $testRoot . '/renumbered-mod.zip') === 12, 'renumbered 3.1.0 includes both adapters');
    foreach (['AIAgentNSFWOStim.pex', 'AIAgentNSFWSexLab.pex'] as $adapter) {
        $adapterPath = $extractedRoot . '/mod/Scripts/' . $adapter;
        rename($adapterPath, $adapterPath . '.missing');
        $missingAdapterRejected = false;
        try {
            _sharmatBuildModArchive($extractedRoot . '/mod', $testRoot . '/missing-adapter.zip');
        } catch (RuntimeException $error) {
            $missingAdapterRejected = strpos($error->getMessage(), $adapter) !== false;
        } finally {
            rename($adapterPath . '.missing', $adapterPath);
        }
        updaterAssert($missingAdapterRejected, 'game-mod download rejects missing ' . $adapter);
        updaterAssert(!file_exists($testRoot . '/missing-adapter.zip'), 'missing adapter does not produce an incomplete archive');
    }

    updaterWrite($extractedRoot . '/mod/version.txt', '3.1.1');
    updaterAssert(_sharmatBuildModArchive($extractedRoot . '/mod', $testRoot . '/devices-mod.zip') === 13, '3.1.1 includes DD adapter');
    $ddPath = $extractedRoot . '/mod/Scripts/AIAgentNSFWDevices.pex';
    rename($ddPath, $ddPath . '.missing');
    $missingDd = false;
    try { _sharmatBuildModArchive($extractedRoot . '/mod', $testRoot . '/missing-dd.zip'); }
    catch (RuntimeException $e) { $missingDd = strpos($e->getMessage(), 'AIAgentNSFWDevices.pex') !== false; }
    finally { rename($ddPath . '.missing', $ddPath); }
    updaterAssert($missingDd && !file_exists($testRoot . '/missing-dd.zip'), 'Missing DD adapter cannot produce a partial update');

    // Older game mods predate the adapters and must remain downloadable.
    updaterWrite($extractedRoot . '/mod/version.txt', '3.1.9.7');
    foreach (['AIAgentNSFWOStim.pex', 'AIAgentNSFWSexLab.pex'] as $adapter) {
        rename($extractedRoot . '/mod/Scripts/' . $adapter, $extractedRoot . '/mod/Scripts/' . $adapter . '.missing');
    }
    updaterAssert(_sharmatBuildModArchive($extractedRoot . '/mod', $testRoot . '/legacy-mod.zip') === 10, 'legacy game mod does not require newer adapters');
    foreach (['AIAgentNSFWOStim.pex', 'AIAgentNSFWSexLab.pex'] as $adapter) {
        rename($extractedRoot . '/mod/Scripts/' . $adapter . '.missing', $extractedRoot . '/mod/Scripts/' . $adapter);
    }
    updaterWrite($extractedRoot . '/mod/version.txt', '3.1.9.8');
    updaterAssert(_sharmatBuildModArchive($extractedRoot . '/mod', $testRoot . '/current-mod.zip') === 12, '3.1.9.8 includes both adapters');
    updaterWrite($extractedRoot . '/mod/version.txt', '9.9.9');

    updaterWrite($extractedRoot . '/mod/CHIM/server-plugins/aiagent_nsfw/9.9.9.dwpkg', 'matching server package');
    updaterWrite($extractedRoot . '/mod/CHIM/server-plugins/aiagent_nsfw/1.0.0.dwpkg', 'stale server package');
    $embeddedArchivePath = $testRoot . '/game-mod-embedded.zip';
    updaterAssert(_sharmatBuildModArchive($extractedRoot . '/mod', $embeddedArchivePath) === 14, 'matching embedded package is included');
    updaterAssert($modArchive->open($embeddedArchivePath) === true, 'embedded mod archive opens');
    updaterAssert($modArchive->locateName('CHIM/server-plugins/aiagent_nsfw/9.9.9.dwpkg') !== false, 'current embedded package is present');
    updaterAssert($modArchive->locateName('CHIM/server-plugins/aiagent_nsfw/1.0.0.dwpkg') === false, 'stale embedded package is excluded');
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
