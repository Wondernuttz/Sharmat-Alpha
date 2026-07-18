[CmdletBinding()]
param(
    [string]$GamePackagePath,
    [string]$OutputPath
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
Add-Type -AssemblyName System.IO.Compression

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
if ([string]::IsNullOrWhiteSpace($GamePackagePath)) {
    $GamePackagePath = Join-Path $repoRoot 'mod'
}
$gamePackage = (Resolve-Path -LiteralPath $GamePackagePath).Path
$extensionManifest = Get-Content -LiteralPath (Join-Path $repoRoot 'manifest.json') -Raw | ConvertFrom-Json
$packageManifest = Get-Content -LiteralPath (Join-Path $repoRoot 'dwemer-package.json') -Raw | ConvertFrom-Json
$packageManifest.name = [string]$extensionManifest.name
$packageManifest.version = [string]$extensionManifest.version

if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $OutputPath = Join-Path $gamePackage ("CHIM\server-plugins\{0}\{1}.dwpkg" -f $packageManifest.name, $packageManifest.version)
}
$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
[System.IO.Directory]::CreateDirectory([System.IO.Path]::GetDirectoryName($OutputPath)) | Out-Null

$stageRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('sharmat-dwpkg-' + [guid]::NewGuid().ToString('N'))
$serverStage = Join-Path $stageRoot 'server'

try {
    [System.IO.Directory]::CreateDirectory($serverStage) | Out-Null

    $trackedFiles = git -C $repoRoot ls-files
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not enumerate tracked SHARMAT files.'
    }
    foreach ($relative in $trackedFiles) {
        $normalized = $relative.Replace('\', '/')
        if ($normalized -eq '.gitignore' -or
            $normalized -eq 'dwemer-package.json' -or
            $normalized.StartsWith('mod/') -or
            $normalized.StartsWith('scripts/')) {
            continue
        }
        $source = Join-Path $repoRoot $relative
        if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
            continue
        }
        $destination = Join-Path $serverStage $relative
        [System.IO.Directory]::CreateDirectory([System.IO.Path]::GetDirectoryName($destination)) | Out-Null
        Copy-Item -LiteralPath $source -Destination $destination
    }

    $manifestJson = $packageManifest | ConvertTo-Json -Depth 20
    [System.IO.File]::WriteAllText((Join-Path $stageRoot 'manifest.json'), $manifestJson + "`n", [System.Text.UTF8Encoding]::new($false))

    $checksumLines = foreach ($file in Get-ChildItem -LiteralPath $stageRoot -Recurse -File | Sort-Object FullName) {
        if ($file.Name -eq 'checksums.sha256') { continue }
        $relative = $file.FullName.Substring($stageRoot.Length + 1).Replace('\', '/')
        $hash = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
        "$hash  $relative"
    }
    [System.IO.File]::WriteAllText((Join-Path $stageRoot 'checksums.sha256'), ($checksumLines -join "`n") + "`n", [System.Text.UTF8Encoding]::new($false))

    if (Test-Path -LiteralPath $OutputPath) {
        Remove-Item -LiteralPath $OutputPath -Force
    }
    $archiveStream = [System.IO.File]::Open($OutputPath, [System.IO.FileMode]::CreateNew)
    try {
        $archive = [System.IO.Compression.ZipArchive]::new($archiveStream, [System.IO.Compression.ZipArchiveMode]::Create, $false)
        try {
            foreach ($file in Get-ChildItem -LiteralPath $stageRoot -Recurse -File | Sort-Object FullName) {
                $relative = $file.FullName.Substring($stageRoot.Length + 1).Replace('\', '/')
                $entry = $archive.CreateEntry($relative, [System.IO.Compression.CompressionLevel]::Optimal)
                $entryStream = $entry.Open()
                $inputStream = [System.IO.File]::OpenRead($file.FullName)
                try {
                    $inputStream.CopyTo($entryStream)
                }
                finally {
                    $inputStream.Dispose()
                    $entryStream.Dispose()
                }
            }
        }
        finally {
            $archive.Dispose()
        }
    }
    finally {
        $archiveStream.Dispose()
    }
    Write-Output $OutputPath
}
finally {
    if (Test-Path -LiteralPath $stageRoot) {
        Remove-Item -LiteralPath $stageRoot -Recurse -Force
    }
}
