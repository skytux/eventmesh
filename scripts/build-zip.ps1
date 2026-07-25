<#
.SYNOPSIS
    Builds a distributable eventmesh.zip from the current working tree.

.DESCRIPTION
    Packages only the runtime plugin files (eventmesh.php, uninstall.php, src/,
    templates/, config/, assets/) - no vendor/, tests/, tooling configs, or
    VCS files.

    Output lands in dist/, which .gitignore already excludes, so a build can
    never be committed by accident and the repo root stays free of artifacts.
    Pass -OutputPath to write somewhere else.

    Zip entries are written with forward-slash path separators explicitly,
    rather than relying on Compress-Archive or ZipFile.CreateFromDirectory.
    Windows PowerShell 5.1's System.IO.Compression (.NET Framework) does not
    normalize Path.DirectorySeparatorChar ('\') to '/' in zip entry names the
    way modern .NET does, which produces archives with literal backslashes in
    filenames - those extract as broken/garbled paths on Linux (Linux unzip
    treats '\' as a literal filename character, not a directory separator).
#>

param(
    [string]$OutputPath = (Join-Path $PSScriptRoot '..\dist\eventmesh.zip')
)

$ErrorActionPreference = 'Stop'

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$outputPath = [System.IO.Path]::GetFullPath($OutputPath)

# The output directory is not necessarily present - dist/ is gitignored, so a
# fresh clone has no such folder for the zip stream to be created in.
$outputDir = [System.IO.Path]::GetDirectoryName($outputPath)
if (-not (Test-Path $outputDir)) {
    New-Item -ItemType Directory -Force -Path $outputDir | Out-Null
}

$stagingRoot = Join-Path $env:TEMP 'eventmesh-build'
$stagingDir = Join-Path $stagingRoot 'eventmesh'

if (Test-Path $stagingRoot) {
    [System.IO.Directory]::Delete($stagingRoot, $true)
}
New-Item -ItemType Directory -Force -Path $stagingDir | Out-Null

$runtimeItems = @('eventmesh.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'src', 'templates', 'config', 'assets')

foreach ($item in $runtimeItems) {
    $source = Join-Path $repoRoot $item
    if (Test-Path $source) {
        Copy-Item $source -Destination $stagingDir -Recurse -Force
    }
}

if (Test-Path $outputPath) {
    [System.IO.File]::Delete($outputPath)
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$separator = [System.IO.Path]::DirectorySeparatorChar
$baseLength = $stagingDir.ToString().Length + 1

$zipStream = [System.IO.File]::Open($outputPath, [System.IO.FileMode]::CreateNew)
try {
    $archive = New-Object System.IO.Compression.ZipArchive($zipStream, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        Get-ChildItem -Path $stagingDir -Recurse -File | ForEach-Object {
            $relativePath = $_.FullName.Substring($baseLength).Replace($separator, '/')
            $entryName = "eventmesh/$relativePath"
            $entry = $archive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
            $entryStream = $entry.Open()
            try {
                $fileStream = [System.IO.File]::OpenRead($_.FullName)
                try {
                    $fileStream.CopyTo($entryStream)
                }
                finally {
                    $fileStream.Dispose()
                }
            }
            finally {
                $entryStream.Dispose()
            }
        }
    }
    finally {
        $archive.Dispose()
    }
}
finally {
    $zipStream.Dispose()
}

[System.IO.Directory]::Delete($stagingRoot, $true)

Write-Host "Built $outputPath"
