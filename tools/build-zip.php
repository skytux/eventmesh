<?php

/**
 * Builds an installable plugin zip in dist/: dist/eventmesh-<version>.zip.
 *
 * The Linux counterpart of scripts/build-zip.ps1, adapted from EventCrew's
 * tools/build-zip.php. It ships the same runtime files as the PowerShell
 * builder (eventmesh.php, uninstall.php, readme.txt, LICENSE, src/,
 * templates/, config/ when present, assets/) plus languages/, under a single
 * top-level eventmesh/ folder, which is what WordPress's Plugins → Add New →
 * Upload Plugin expects. Entry names always use forward slashes.
 *
 *     php tools/build-zip.php
 *
 * Needs the zip extension (the dev container has it).
 *
 * @package EventMesh
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$slug = 'eventmesh';

if (! class_exists(ZipArchive::class)) {
    fwrite(STDERR, "The zip extension is not loaded.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// What ships
// ---------------------------------------------------------------------------

/**
 * Named explicitly rather than derived by excluding things, so a new top-level
 * directory has to be considered before it can end up on someone's server.
 * Keep this list the same as $runtimeItems in scripts/build-zip.ps1, plus
 * languages/.
 */
$include = [
    'eventmesh.php',
    'uninstall.php',
    'readme.txt',
    'LICENSE',
    'src',
    'templates',
    'assets',
    // The translations (.mo is what WordPress reads; .po and .pot are its
    // source; the .json files are what the block editor scripts read,
    // written below). Without it a Finnish page shows the English texts.
    'languages',
];

/** Listed in the PowerShell builder but optional: skipped when absent there too. */
$optional = [
    'config',
];

/**
 * Never ships. Dotfiles match what Get-ChildItem leaves out by default in the
 * PowerShell builder (hidden files).
 */
$excludePatterns = [
    '#(^|/)\.#',
];

// ---------------------------------------------------------------------------
// Version, read from the plugin header so it cannot disagree with the zip name
// ---------------------------------------------------------------------------

$header = (string) file_get_contents($root . '/eventmesh.php');

if (1 !== preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', $header, $m)) {
    fwrite(STDERR, "Could not read Version from the plugin header.\n");
    exit(1);
}

$version = trim($m[1]);

// The constant is used at runtime while the header is what WordPress shows,
// so a mismatch between them is a real bug that only shows up after release.
if (1 === preg_match("/define\('EVENTMESH_VERSION',\s*'([^']+)'\)/", $header, $c) && $c[1] !== $version) {
    fwrite(STDERR, sprintf(
        "Version mismatch: header says %s, EVENTMESH_VERSION says %s.\n",
        $version,
        $c[1]
    ));
    exit(1);
}

// ---------------------------------------------------------------------------
// JSON translations for the block editor scripts, made fresh from the .po
// files so they always match the scripts being shipped (not kept in git)
// ---------------------------------------------------------------------------

require __DIR__ . '/i18n.php';

try {
    $jsonFiles = eventmesh_make_json($root);
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not write the JSON translations: ' . $e->getMessage() . "\n");
    exit(1);
}

if ([] === $jsonFiles) {
    fwrite(STDERR, "No JSON translations were written; the editor would show English only.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Collect
// ---------------------------------------------------------------------------

$shouldExclude = static function (string $relative) use ($excludePatterns): bool {
    foreach ($excludePatterns as $pattern) {
        if (1 === preg_match($pattern, $relative)) {
            return true;
        }
    }

    return false;
};

/** @var array<int, string> $files */
$files = [];

foreach (array_merge($include, $optional) as $entry) {
    $path = $root . '/' . $entry;

    if (is_file($path)) {
        $files[] = $entry;
        continue;
    }

    if (! is_dir($path)) {
        if (in_array($entry, $optional, true)) {
            continue;
        }

        fwrite(STDERR, sprintf("Missing: %s\n", $entry));
        exit(1);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        if ($shouldExclude($relative)) {
            continue;
        }

        $files[] = $relative;
    }
}

sort($files);

// ---------------------------------------------------------------------------
// Write
// ---------------------------------------------------------------------------

if (! is_dir($root . '/dist') && ! mkdir($root . '/dist', 0o755, true)) {
    fwrite(STDERR, "Could not create dist/.\n");
    exit(1);
}

$zipPath = sprintf('%s/dist/%s-%s.zip', $root, $slug, $version);

if (is_file($zipPath) && ! unlink($zipPath)) {
    fwrite(STDERR, sprintf("Could not replace %s.\n", $zipPath));
    exit(1);
}

$zip = new ZipArchive();

if (true !== $zip->open($zipPath, ZipArchive::CREATE)) {
    fwrite(STDERR, sprintf("Could not open %s for writing.\n", $zipPath));
    exit(1);
}

foreach ($files as $relative) {
    $zip->addFile($root . '/' . $relative, $slug . '/' . $relative);
}

$zip->close();

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

printf("%s\n\n", $zipPath);

foreach ($files as $relative) {
    printf("  %s/%s\n", $slug, $relative);
}

printf(
    "\n%d files, %s\n",
    count($files),
    number_format(round(filesize($zipPath) / 1024, 1), 1) . ' KB'
);
