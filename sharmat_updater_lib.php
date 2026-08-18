<?php

/**
 * Support code for SHARMAT's in-page updater.
 *
 * Keep this file free of CHIM bootstrap dependencies so its filesystem behavior
 * can be regression tested without loading the server or database.
 */

function _sharmatUpdateHttpGet($url)
{
    $responseHeaders = [];
    $ch = curl_init($url);
    if ($ch === false) {
        return [0, '', ['curl_error' => 'Could not initialize cURL', 'headers' => []]];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => [
            'User-Agent: SHARMAT-updater',
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
        CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$responseHeaders) {
            $length = strlen($line);
            $separator = strpos($line, ':');
            if ($separator !== false) {
                $name = strtolower(trim(substr($line, 0, $separator)));
                $responseHeaders[$name] = trim(substr($line, $separator + 1));
            }
            return $length;
        },
    ]);

    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = $body === false ? curl_error($ch) : '';
    curl_close($ch);

    return [
        $code,
        is_string($body) ? $body : '',
        ['curl_error' => $curlError, 'headers' => $responseHeaders],
    ];
}

function _sharmatGitHubFailure($context, $code, $body, $meta)
{
    $headers = is_array($meta['headers'] ?? null) ? $meta['headers'] : [];
    $curlError = trim((string)($meta['curl_error'] ?? ''));
    if ($curlError !== '') {
        return "{$context}: could not reach GitHub ({$curlError})";
    }

    $remaining = (string)($headers['x-ratelimit-remaining'] ?? '');
    if ($code === 429 || ($code === 403 && $remaining === '0')) {
        $retry = '';
        $reset = (int)($headers['x-ratelimit-reset'] ?? 0);
        if ($reset > 0) {
            $retry = ' Try again after ' . date('Y-m-d H:i:s T', $reset) . '.';
        } elseif (!empty($headers['retry-after'])) {
            $retry = ' Try again in ' . (int)$headers['retry-after'] . ' seconds.';
        }
        return "{$context}: GitHub's API rate limit was reached.{$retry} The manual GitHub download remains available.";
    }

    $detail = '';
    $decoded = json_decode((string)$body, true);
    if (is_array($decoded) && !empty($decoded['message'])) {
        $detail = ': ' . trim((string)$decoded['message']);
    }
    return "{$context}: GitHub returned HTTP {$code}{$detail}";
}

function _sharmatResolveLatestCommit()
{
    [$code, $body, $meta] = _sharmatUpdateHttpGet(
        'https://api.github.com/repos/Wondernuttz/Sharmat-Alpha/commits/main'
    );
    if ($code !== 200) {
        throw new RuntimeException(_sharmatGitHubFailure('Update check failed', $code, $body, $meta));
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Update check failed: GitHub returned invalid JSON');
    }
    $sha = strtolower(trim((string)($data['sha'] ?? '')));
    if (!preg_match('/^[a-f0-9]{40}$/', $sha)) {
        throw new RuntimeException('Update check failed: GitHub did not return a valid commit SHA');
    }
    return [$sha, $data];
}

function _sharmatNormalizeCommitSha($sha)
{
    $sha = strtolower(trim((string)$sha));
    return preg_match('/^[a-f0-9]{40}$/', $sha) ? $sha : '';
}

function _sharmatCreateUniqueDirectory($base, $prefix, $mode = 0775)
{
    if (!is_dir($base)) {
        if (!@mkdir($base, $mode, true) && !is_dir($base)) {
            throw new RuntimeException("Could not create updater directory: {$base}");
        }
        if (!@chmod($base, $mode)) {
            throw new RuntimeException("Could not set updater directory permissions: {$base}");
        }
    }
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $path = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(12));
        if (@mkdir($path, $mode)) {
            if (!@chmod($path, $mode)) {
                @rmdir($path);
                throw new RuntimeException("Could not set updater directory permissions: {$path}");
            }
            return $path;
        }
    }
    throw new RuntimeException("Could not allocate a unique updater directory in {$base}");
}

function _sharmatUniqueDestination($base, $prefix)
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $path = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(12));
        if (!file_exists($path) && !is_link($path)) {
            return $path;
        }
    }
    throw new RuntimeException("Could not allocate a unique backup name in {$base}");
}

function _sharmatRemoveTree($path)
{
    if ($path === null || $path === '') {
        return true;
    }
    if (is_link($path) || is_file($path)) {
        return @unlink($path);
    }
    if (!is_dir($path)) {
        return true;
    }

    $ok = true;
    $entries = @scandir($path);
    if ($entries === false) {
        return false;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!_sharmatRemoveTree($path . DIRECTORY_SEPARATOR . $entry)) {
            $ok = false;
        }
    }
    return @rmdir($path) && $ok;
}

function _sharmatValidateUpdateRequest($method, $customHeader)
{
    if (strtoupper(trim((string)$method)) !== 'POST') {
        throw new RuntimeException('Update requests must use POST');
    }
    if (!hash_equals('1', trim((string)$customHeader))) {
        throw new RuntimeException('Update request is missing the same-origin confirmation header');
    }
}

function _sharmatPrepareBackupDirectory($path, $groupId, $webProtected)
{
    if (is_link($path)) {
        return false;
    }
    if (!is_dir($path)) {
        if (!@mkdir($path, 0770, true) && !is_dir($path)) {
            return false;
        }
    }
    if (!@chmod($path, 0770) || !is_writable($path)) {
        return false;
    }
    if ($groupId !== false && @filegroup($path) !== $groupId) {
        @chgrp($path, $groupId);
    }

    if ($webProtected) {
        $rules = "Options -Indexes\n"
            . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
        $denyFile = $path . DIRECTORY_SEPARATOR . '.htaccess';
        $existing = is_file($denyFile) ? @file_get_contents($denyFile) : false;
        if ($existing !== $rules) {
            $temporary = $path . DIRECTORY_SEPARATOR . '.htaccess-' . bin2hex(random_bytes(8));
            $written = @file_put_contents($temporary, $rules, LOCK_EX);
            if ($written !== strlen($rules) || !@chmod($temporary, 0660) || !@rename($temporary, $denyFile)) {
                @unlink($temporary);
                return false;
            }
        }
        if (!@chmod($denyFile, 0660)) {
            return false;
        }
        if ($groupId !== false && @filegroup($denyFile) !== $groupId) {
            @chgrp($denyFile, $groupId);
        }
        if (@file_get_contents($denyFile) !== $rules) {
            return false;
        }
    }
    return true;
}

function _sharmatResolveBackupBase($extensionRoot, $documentRoot = null, $persistentTempRoot = null)
{
    if (!is_dir($extensionRoot) || is_link($extensionRoot)) {
        throw new RuntimeException('Cannot locate a safe backup directory for an invalid SHARMAT installation');
    }
    $groupId = @filegroup($extensionRoot);
    $documentRoot = trim((string)$documentRoot);
    if ($documentRoot === '') {
        $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    }
    if ($documentRoot === '' || !is_dir($documentRoot)) {
        $documentRoot = dirname(dirname(dirname($extensionRoot)));
    }
    $resolvedDocumentRoot = realpath($documentRoot);
    if ($resolvedDocumentRoot === false || !is_dir($resolvedDocumentRoot)) {
        throw new RuntimeException('Could not resolve the CHIM document root for protected backups');
    }

    $outsideBase = dirname($resolvedDocumentRoot) . DIRECTORY_SEPARATOR . '.sharmat_backups';
    if (_sharmatPrepareBackupDirectory($outsideBase, $groupId, false)) {
        return $outsideBase;
    }

    $persistentTempRoot = trim((string)$persistentTempRoot);
    if ($persistentTempRoot === '') {
        $persistentTempRoot = DIRECTORY_SEPARATOR === '/' ? '/var/tmp' : sys_get_temp_dir();
    }
    $resolvedPersistentRoot = realpath($persistentTempRoot);
    if ($resolvedPersistentRoot !== false && is_dir($resolvedPersistentRoot)) {
        $persistentBase = $resolvedPersistentRoot . DIRECTORY_SEPARATOR . 'sharmat_backups';
        if (_sharmatPrepareBackupDirectory($persistentBase, $groupId, false)) {
            return $persistentBase;
        }
    }

    throw new RuntimeException(
        'Could not create a SHARMAT backup directory outside the web document root. '
        . 'Create /var/www/.sharmat_backups or /var/tmp/sharmat_backups writable by the web-server group, then try again.'
    );
}

function _sharmatAcquireOperationLock($backupBase, $exclusive)
{
    if (!is_dir($backupBase) || is_link($backupBase)) {
        throw new RuntimeException('SHARMAT operation lock directory is unavailable');
    }
    $lockPath = $backupBase . DIRECTORY_SEPARATOR . '.sharmat-operation.lock';
    $handle = @fopen($lockPath, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Could not open the SHARMAT operation lock');
    }
    @chmod($lockPath, 0660);
    $groupId = @filegroup($backupBase);
    if ($groupId !== false && @filegroup($lockPath) !== $groupId) {
        @chgrp($lockPath, $groupId);
    }
    $operation = ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB;
    if (!@flock($handle, $operation)) {
        @fclose($handle);
        throw new RuntimeException($exclusive
            ? 'Another SHARMAT update or game-mod download is already running'
            : 'SHARMAT is being updated. Try the game-mod download again when it finishes');
    }
    return $handle;
}

function _sharmatReleaseOperationLock($handle)
{
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

function _sharmatMarkAutomaticBackup($backupRoot)
{
    if (!is_dir($backupRoot) || is_link($backupRoot)) {
        throw new RuntimeException('Could not mark an invalid SHARMAT backup');
    }
    $marker = $backupRoot . DIRECTORY_SEPARATOR . '.sharmat-auto-backup';
    $contents = "SHARMAT_AUTOMATIC_BACKUP_V1\n";
    $written = @file_put_contents($marker, $contents, LOCK_EX);
    if ($written !== strlen($contents) || !@chmod($marker, 0660)) {
        @unlink($marker);
        throw new RuntimeException('SHARMAT was installed, but its automatic backup could not be marked safely');
    }
    $groupId = @filegroup($backupRoot);
    if ($groupId !== false && @filegroup($marker) !== $groupId) {
        @chgrp($marker, $groupId);
    }
}

function _sharmatPruneAutomaticBackups($backupBase, $keep = 5)
{
    $keep = max(1, (int)$keep);
    $automatic = [];
    $entries = @scandir($backupBase);
    if ($entries === false) {
        return ['removed' => 0, 'failed' => 0];
    }
    foreach ($entries as $entry) {
        if (!preg_match('/^aiagent_nsfw_auto_\d{8}_\d{6}_[a-f0-9]{24}$/', $entry)) {
            continue;
        }
        $path = $backupBase . DIRECTORY_SEPARATOR . $entry;
        $marker = $path . DIRECTORY_SEPARATOR . '.sharmat-auto-backup';
        if (!is_dir($path) || is_link($path) || @file_get_contents($marker) !== "SHARMAT_AUTOMATIC_BACKUP_V1\n") {
            continue;
        }
        $automatic[] = ['path' => $path, 'mtime' => (int)@filemtime($path), 'name' => $entry];
    }
    usort($automatic, static function ($left, $right) {
        if ($left['mtime'] === $right['mtime']) {
            return strcmp($right['name'], $left['name']);
        }
        return $right['mtime'] <=> $left['mtime'];
    });

    $removed = 0;
    $failed = 0;
    foreach (array_slice($automatic, $keep) as $backup) {
        if (_sharmatRemoveTree($backup['path'])) {
            $removed++;
        } else {
            $failed++;
        }
    }
    return ['removed' => $removed, 'failed' => $failed];
}

function _sharmatNormalizeRelativePath($path)
{
    $normalized = str_replace('\\', '/', trim((string)$path));
    $normalized = trim($normalized, '/');
    if ($normalized === '' || strpos($normalized, "\0") !== false || preg_match('/^[A-Za-z]:/', $normalized)) {
        throw new RuntimeException("Unsafe package path: {$path}");
    }
    foreach (explode('/', $normalized) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new RuntimeException("Unsafe package path: {$path}");
        }
    }
    return $normalized;
}

function _sharmatValidateZipEntries(ZipArchive $zip)
{
    if ($zip->numFiles < 1 || $zip->numFiles > 20000) {
        throw new RuntimeException('Downloaded archive has an invalid file count');
    }

    $totalUncompressed = 0;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $stat = $zip->statIndex($index);
        if (!is_array($stat) || !isset($stat['name'])) {
            throw new RuntimeException("Could not inspect archive entry {$index}");
        }
        $name = str_replace('\\', '/', (string)$stat['name']);
        if ($name === '' || strpos($name, "\0") !== false || $name[0] === '/' || preg_match('/^[A-Za-z]:/', $name)) {
            throw new RuntimeException("Archive contains an unsafe path: {$name}");
        }
        foreach (explode('/', rtrim($name, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException("Archive contains path traversal or an invalid component: {$name}");
            }
        }

        if (method_exists($zip, 'getExternalAttributesIndex')) {
            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                $fileType = ($attributes >> 16) & 0170000;
                if ($fileType === 0120000) {
                    throw new RuntimeException("Archive contains a symbolic link: {$name}");
                }
            }
        }

        $size = (int)($stat['size'] ?? 0);
        if ($size < 0 || $size > 268435456) {
            throw new RuntimeException("Archive entry is unexpectedly large: {$name}");
        }
        $totalUncompressed += $size;
        if ($totalUncompressed > 1073741824) {
            throw new RuntimeException('Downloaded archive expands beyond the 1 GB safety limit');
        }
    }
}

function _sharmatExtractRepositoryArchive($zipPath, $extractDir, $expectedSha)
{
    if (!is_dir($extractDir) && !@mkdir($extractDir, 0700, true) && !is_dir($extractDir)) {
        throw new RuntimeException("Could not create extraction directory: {$extractDir}");
    }

    $zip = new ZipArchive();
    $openResult = $zip->open($zipPath);
    if ($openResult !== true) {
        throw new RuntimeException("Could not open downloaded archive (ZipArchive code {$openResult})");
    }
    try {
        _sharmatValidateZipEntries($zip);
        if (!$zip->extractTo($extractDir)) {
            throw new RuntimeException('Could not extract the downloaded archive');
        }
    } finally {
        if (!$zip->close()) {
            error_log('[SHARMAT updater] ZipArchive close failed for ' . $zipPath);
        }
    }

    $entries = [];
    $scan = @scandir($extractDir);
    if ($scan === false) {
        throw new RuntimeException('Could not inspect the extracted archive');
    }
    foreach ($scan as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $entries[] = $entry;
        }
    }
    if (count($entries) !== 1) {
        throw new RuntimeException('Downloaded archive must contain exactly one repository root');
    }

    $root = $extractDir . DIRECTORY_SEPARATOR . $entries[0];
    if (!is_dir($root) || is_link($root)) {
        throw new RuntimeException('Downloaded archive repository root is not a safe directory');
    }
    $shaPrefix = substr(strtolower($expectedSha), 0, 7);
    if ($shaPrefix === '' || stripos($entries[0], $shaPrefix) === false) {
        throw new RuntimeException('Downloaded archive does not match the commit returned by GitHub');
    }
    return $root;
}

function _sharmatReadJsonFile($path, $label)
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Downloaded package is missing {$label}");
    }
    $data = json_decode($contents, true);
    if (!is_array($data)) {
        throw new RuntimeException("Downloaded package has invalid {$label}");
    }
    return $data;
}

function _sharmatValidatePackageRoot($root)
{
    foreach (['config_manager.php', 'functions.php', 'nsfw_data.php', 'mod/version.txt'] as $marker) {
        if (!is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $marker))) {
            throw new RuntimeException("Downloaded package is missing required marker {$marker}");
        }
    }

    $configManager = @file_get_contents($root . DIRECTORY_SEPARATOR . 'config_manager.php');
    if ($configManager === false) {
        throw new RuntimeException('Downloaded package config_manager.php could not be read');
    }
    if (strpos($configManager, 'sharmat_updater_lib.php') !== false
        && !is_file($root . DIRECTORY_SEPARATOR . 'sharmat_updater_lib.php')) {
        throw new RuntimeException('Downloaded package is missing required updater support code');
    }

    $manifest = _sharmatReadJsonFile($root . DIRECTORY_SEPARATOR . 'manifest.json', 'manifest.json');
    $package = _sharmatReadJsonFile($root . DIRECTORY_SEPARATOR . 'dwemer-package.json', 'dwemer-package.json');
    if (($manifest['name'] ?? '') !== 'aiagent_nsfw' || ($manifest['git_repo'] ?? '') !== 'Wondernuttz/Sharmat-Alpha') {
        throw new RuntimeException('Downloaded manifest does not identify Wondernuttz/Sharmat-Alpha');
    }
    if (($package['name'] ?? '') !== 'aiagent_nsfw') {
        throw new RuntimeException('Downloaded package metadata does not identify aiagent_nsfw');
    }

    $manifestVersion = trim((string)($manifest['version'] ?? ''));
    $packageVersion = trim((string)($package['version'] ?? ''));
    $modVersion = trim((string)@file_get_contents($root . DIRECTORY_SEPARATOR . 'mod' . DIRECTORY_SEPARATOR . 'version.txt'));
    if ($manifestVersion === '' || $manifestVersion !== $packageVersion || $manifestVersion !== $modVersion) {
        throw new RuntimeException('Downloaded server and game-mod version markers do not match');
    }

    $mutable = ['conf/conf.php', 'cmd/conf/conf.php'];
    $declared = $package['server']['mutable_paths'] ?? [];
    if (is_array($declared)) {
        foreach ($declared as $path) {
            $mutable[] = _sharmatNormalizeRelativePath($path);
        }
    }
    return array_values(array_unique($mutable));
}

function _sharmatDownloadExactRepository($sha, $workspace)
{
    $sha = strtolower(trim((string)$sha));
    if (!preg_match('/^[a-f0-9]{40}$/', $sha)) {
        throw new RuntimeException('Repository download refused an invalid commit SHA');
    }
    if (!is_dir($workspace) || is_link($workspace)) {
        throw new RuntimeException('Repository download workspace is not a safe directory');
    }

    [$code, $body, $meta] = _sharmatUpdateHttpGet(
        'https://api.github.com/repos/Wondernuttz/Sharmat-Alpha/zipball/' . rawurlencode($sha)
    );
    if ($code !== 200) {
        throw new RuntimeException(_sharmatGitHubFailure('Repository download failed', $code, $body, $meta));
    }
    if (strlen($body) < 1000 || substr($body, 0, 2) !== 'PK') {
        throw new RuntimeException('Repository download failed: GitHub did not return a ZIP archive');
    }

    $archivePath = $workspace . DIRECTORY_SEPARATOR . 'repository.zip';
    $written = @file_put_contents($archivePath, $body, LOCK_EX);
    if ($written === false || $written !== strlen($body)) {
        throw new RuntimeException('Repository download failed: could not write the complete archive to temporary storage');
    }

    $sourceRoot = _sharmatExtractRepositoryArchive(
        $archivePath,
        $workspace . DIRECTORY_SEPARATOR . 'extracted',
        $sha
    );
    $mutablePaths = _sharmatValidatePackageRoot($sourceRoot);
    return ['root' => $sourceRoot, 'mutable_paths' => $mutablePaths];
}

function _sharmatBuildModArchive($modDir, $archivePath)
{
    if (!is_dir($modDir) || is_link($modDir)) {
        throw new RuntimeException('Game mod directory is not available');
    }
    foreach (['version.txt', 'AIAgentNSFW.esp', 'Scripts/AIAgentNSFW.pex'] as $marker) {
        if (!is_file($modDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $marker))) {
            throw new RuntimeException("Game mod directory is missing required file {$marker}");
        }
    }

    $archive = new ZipArchive();
    $openResult = $archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($openResult !== true) {
        throw new RuntimeException("Could not create game-mod archive (ZipArchive code {$openResult})");
    }

    $added = 0;
    $failures = [];
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                $failures[] = $file->getPathname() . ': symbolic links are not supported';
                continue;
            }
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($modDir) + 1));
            $relative = _sharmatNormalizeRelativePath($relative);
            if (preg_match('/(?:\.bak|meta\.ini)$/i', $relative)) {
                continue;
            }
            if (!$archive->addFile($file->getPathname(), $relative)) {
                $failures[] = $relative . ': could not add file to archive';
                continue;
            }
            $added++;
        }
    } catch (Throwable $error) {
        $failures[] = $error->getMessage();
    }

    $closed = $archive->close();
    if (!$closed) {
        $failures[] = 'could not finalize game-mod archive';
    }
    if ($added < 3) {
        $failures[] = "only {$added} game-mod files were archived";
    }
    if (!empty($failures)) {
        @unlink($archivePath);
        throw new RuntimeException('Could not build game-mod download: ' . implode('; ', array_slice($failures, 0, 8)));
    }
    $size = @filesize($archivePath);
    if ($size === false || $size < 100) {
        @unlink($archivePath);
        throw new RuntimeException('Could not build game-mod download: completed archive is empty');
    }
    return $added;
}

function _sharmatCopyFileChecked($source, $destination, $relativePath, &$failures)
{
    $parent = dirname($destination);
    if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
        $failures[] = "{$relativePath}: could not create destination directory";
        return false;
    }
    if (!@chmod($parent, 0775)) {
        $failures[] = "{$relativePath}: could not normalize destination directory permissions";
        return false;
    }

    $temporary = $parent . DIRECTORY_SEPARATOR . '.sharmat-copy-' . bin2hex(random_bytes(8));
    if (!@copy($source, $temporary)) {
        $failures[] = "{$relativePath}: copy failed";
        return false;
    }

    $sourceSize = @filesize($source);
    $copySize = @filesize($temporary);
    $sourceHash = @hash_file('sha256', $source);
    $copyHash = @hash_file('sha256', $temporary);
    if ($sourceSize === false || $copySize !== $sourceSize || $sourceHash === false || !hash_equals($sourceHash, (string)$copyHash)) {
        @unlink($temporary);
        $failures[] = "{$relativePath}: copied file failed verification";
        return false;
    }

    if (file_exists($destination) || is_link($destination)) {
        if (is_dir($destination) || !@unlink($destination)) {
            @unlink($temporary);
            $failures[] = "{$relativePath}: could not replace staged destination";
            return false;
        }
    }
    if (!@rename($temporary, $destination)) {
        @unlink($temporary);
        $failures[] = "{$relativePath}: could not finalize staged copy";
        return false;
    }
    if (!@chmod($destination, 0664)) {
        @unlink($destination);
        $failures[] = "{$relativePath}: could not normalize staged file permissions";
        return false;
    }
    return true;
}

function _sharmatCopyTreeChecked($source, $destination, $relative, $skipTop, &$copied, &$failures)
{
    if (is_link($source)) {
        $failures[] = ($relative !== '' ? $relative : basename($source)) . ': symbolic links are not supported';
        return;
    }
    if (!is_dir($source)) {
        $failures[] = ($relative !== '' ? $relative : basename($source)) . ': source directory is missing';
        return;
    }
    if (!is_dir($destination) && !@mkdir($destination, 0775, true) && !is_dir($destination)) {
        $failures[] = ($relative !== '' ? $relative : '.') . ': could not create staged directory';
        return;
    }
    if (!@chmod($destination, 0775)) {
        $failures[] = ($relative !== '' ? $relative : '.') . ': could not normalize staged directory permissions';
        return;
    }

    $entries = @scandir($source);
    if ($entries === false) {
        $failures[] = ($relative !== '' ? $relative : '.') . ': could not read source directory';
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if ($relative === '' && in_array($entry, $skipTop, true)) {
            continue;
        }
        $nextRelative = $relative === '' ? $entry : $relative . '/' . $entry;
        $sourcePath = $source . DIRECTORY_SEPARATOR . $entry;
        $destinationPath = $destination . DIRECTORY_SEPARATOR . $entry;
        if (is_link($sourcePath)) {
            $failures[] = "{$nextRelative}: symbolic links are not supported";
        } elseif (is_dir($sourcePath)) {
            _sharmatCopyTreeChecked($sourcePath, $destinationPath, $nextRelative, $skipTop, $copied, $failures);
        } elseif (is_file($sourcePath)) {
            if (_sharmatCopyFileChecked($sourcePath, $destinationPath, $nextRelative, $failures)) {
                $copied++;
            }
        } else {
            $failures[] = "{$nextRelative}: unsupported filesystem entry";
        }
    }
}

function _sharmatPreserveMutablePaths($currentRoot, $stageRoot, $mutablePaths, &$preserved, &$failures)
{
    foreach ($mutablePaths as $path) {
        $relative = _sharmatNormalizeRelativePath($path);
        $platformPath = str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $source = $currentRoot . DIRECTORY_SEPARATOR . $platformPath;
        $destination = $stageRoot . DIRECTORY_SEPARATOR . $platformPath;
        if (!file_exists($source) && !is_link($source)) {
            continue;
        }
        if (is_link($source)) {
            $failures[] = "{$relative}: mutable path is a symbolic link";
        } elseif (is_dir($source)) {
            _sharmatCopyTreeChecked($source, $destination, $relative, [], $preserved, $failures);
        } elseif (is_file($source)) {
            if (_sharmatCopyFileChecked($source, $destination, $relative, $failures)) {
                $preserved++;
            }
        } else {
            $failures[] = "{$relative}: mutable path has an unsupported type";
        }
    }
}

function _sharmatValidateStagedRuntime($stageRoot)
{
    $required = [
        'config_manager.php',
        'sharmat_updater_lib.php',
        'scene_role_policy.php',
        'config_section_defeat.php',
    ];
    foreach ($required as $relative) {
        $path = $stageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException("Staged update is missing required runtime file {$relative}");
        }
    }
}

function _sharmatFindPhpCliBinary()
{
    $candidates = [PHP_BINDIR . DIRECTORY_SEPARATOR . 'php', '/usr/bin/php', PHP_BINARY];
    foreach (array_unique($candidates) as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_file($candidate) && is_executable($candidate)
            && stripos(basename($candidate), 'php-fpm') === false) {
            return $candidate;
        }
    }
    throw new RuntimeException('Cannot lint the staged update because the PHP CLI binary was not found');
}

function _sharmatLintPhpTree($stageRoot)
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('Cannot lint the staged update because proc_open is unavailable');
    }
    $phpBinary = _sharmatFindPhpCliBinary();
    $phpFiles = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stageRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isLink()) {
            throw new RuntimeException('Cannot lint staged update containing symbolic links');
        }
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $phpFiles[] = $file->getPathname();
        }
    }
    sort($phpFiles, SORT_STRING);
    if (empty($phpFiles)) {
        throw new RuntimeException('Staged update does not contain any PHP runtime files');
    }

    foreach ($phpFiles as $phpFile) {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open([$phpBinary, '-n', '-l', $phpFile], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start PHP syntax validation for the staged update');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $relative = str_replace('\\', '/', substr($phpFile, strlen($stageRoot) + 1));
            $detail = trim($stderr !== '' ? $stderr : $stdout);
            throw new RuntimeException("PHP syntax validation failed for {$relative}: {$detail}");
        }
    }
    return count($phpFiles);
}

function _sharmatNormalizeStagedPermissions($stageRoot, $currentRoot)
{
    $groupId = @filegroup($currentRoot);
    $paths = [$stageRoot];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stageRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        $paths[] = $entry->getPathname();
    }

    $groupChanged = 0;
    $groupFailed = 0;
    foreach ($paths as $path) {
        if (is_link($path)) {
            throw new RuntimeException('Cannot normalize permissions for a staged symbolic link');
        }
        $mode = is_dir($path) ? 0775 : 0664;
        if (!@chmod($path, $mode)) {
            throw new RuntimeException('Could not normalize staged permissions for ' . $path);
        }
        if ($groupId !== false && @filegroup($path) !== $groupId) {
            if (@chgrp($path, $groupId)) {
                $groupChanged++;
            } else {
                $groupFailed++;
            }
        }
    }
    clearstatcache(true, $stageRoot);
    return ['group_changed' => $groupChanged, 'group_failed' => $groupFailed];
}

function _sharmatPrepareStagedInstall($sourceRoot, $stageRoot, $currentRoot, $mutablePaths)
{
    $copied = 0;
    $preserved = 0;
    $failures = [];
    $skipTop = ['.git', '.github', '.gitignore', 'scripts', 'tests'];
    _sharmatCopyTreeChecked($sourceRoot, $stageRoot, '', $skipTop, $copied, $failures);
    _sharmatPreserveMutablePaths($currentRoot, $stageRoot, $mutablePaths, $preserved, $failures);

    if ($copied < 20) {
        $failures[] = "package: only {$copied} files were staged";
    }
    if (!empty($failures)) {
        $preview = implode('; ', array_slice($failures, 0, 8));
        throw new RuntimeException('Could not build a complete staged update: ' . $preview);
    }
    _sharmatValidatePackageRoot($stageRoot);
    _sharmatValidateStagedRuntime($stageRoot);
    $permissionStats = _sharmatNormalizeStagedPermissions($stageRoot, $currentRoot);
    $phpLinted = _sharmatLintPhpTree($stageRoot);
    return [
        'copied' => $copied,
        'preserved' => $preserved,
        'php_linted' => $phpLinted,
        'group_changed' => $permissionStats['group_changed'],
        'group_failed' => $permissionStats['group_failed'],
    ];
}

function _sharmatSwapStagedInstall($stageRoot, $currentRoot, $backupRoot)
{
    if (!is_dir($stageRoot) || is_link($stageRoot)) {
        throw new RuntimeException('Staged update disappeared before installation');
    }
    if (!is_dir($currentRoot) || is_link($currentRoot)) {
        throw new RuntimeException('Current SHARMAT directory is not a safe directory');
    }
    if (file_exists($backupRoot) || is_link($backupRoot)) {
        throw new RuntimeException('Refusing to overwrite an existing SHARMAT backup');
    }

    if (!@rename($currentRoot, $backupRoot)) {
        throw new RuntimeException('Could not move the current SHARMAT install into its backup directory. Check server permissions.');
    }
    if (@rename($stageRoot, $currentRoot)) {
        return;
    }

    $rollbackSucceeded = @rename($backupRoot, $currentRoot);
    if (!$rollbackSucceeded) {
        throw new RuntimeException("CRITICAL: staged install failed and automatic rollback failed. Restore {$backupRoot} to {$currentRoot}.");
    }
    throw new RuntimeException('Could not activate the staged SHARMAT update. The previous install was restored. Check server permissions.');
}
