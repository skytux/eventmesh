<?php

/**
 * Compiles each languages/*.po into the .mo file WordPress reads.
 *
 * A small pure-PHP compiler rather than msgfmt or WP-CLI's `i18n make-mo`,
 * because the dev container has neither. Copied from EventCrew's
 * tools/make-mo.php. It reads the subset of the PO format tools/make-pot.php
 * writes and a translator edits: comments, msgctxt, msgid, msgid_plural,
 * msgstr and msgstr[n], each with continuation lines. Untranslated and fuzzy
 * entries are left out, the same as msgfmt does, so WordPress falls back to
 * the English.
 *
 *     php tools/make-mo.php
 *
 * @package EventMesh
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$files = glob($root . '/languages/*.po');

if (false === $files || [] === $files) {
    fwrite(STDERR, "No .po files in languages/.\n");
    exit(1);
}

require __DIR__ . '/i18n.php';

/**
 * Writes the entries as a little-endian GNU .mo file, sorted by key as the
 * format requires for its binary search.
 *
 * @param array<string, string> $entries
 */
$write = static function (string $path, array $entries): void {
    ksort($entries, SORT_STRING);

    $count = count($entries);
    $originalsTable = 28;
    $translationsTable = $originalsTable + 8 * $count;
    $offset = $translationsTable + 8 * $count;

    $originals = '';
    $translations = '';
    $originalIndex = '';
    $translationIndex = '';

    foreach (array_keys($entries) as $key) {
        $originalIndex .= pack('VV', strlen((string) $key), $offset + strlen($originals));
        $originals .= $key . "\0";
    }

    $offset += strlen($originals);

    foreach ($entries as $value) {
        $translationIndex .= pack('VV', strlen($value), $offset + strlen($translations));
        $translations .= $value . "\0";
    }

    // Magic, revision, count, the two table offsets, and an empty hash table.
    $header = pack('VVVVVVV', 0x950412de, 0, $count, $originalsTable, $translationsTable, 0, 0);
    $body = $header . $originalIndex . $translationIndex . $originals . $translations;

    if (false === file_put_contents($path, $body)) {
        throw new RuntimeException('Could not write ' . $path);
    }
};

foreach ($files as $po) {
    $entries = eventmesh_read_po($po);
    $mo = substr($po, 0, -3) . '.mo';
    $write($mo, $entries);
    printf("%s\n%d strings\n", $mo, count($entries) - (isset($entries['']) ? 1 : 0));
}
