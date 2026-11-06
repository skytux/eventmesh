<?php

/**
 * Translation helpers shared by tools/make-pot.php, tools/make-mo.php and
 * tools/make-json.php. Plain functions, no WordPress and no WP-CLI, because
 * the dev container has neither.
 *
 * @package EventMesh
 */

declare(strict_types=1);

/**
 * Undoes the C-style escapes a PO string literal may hold.
 */
function eventmesh_po_unescape(string $literal): string
{
    return (string) preg_replace_callback(
        '/\\\\(.)/s',
        static fn (array $m): string => match ($m[1]) {
            'n' => "\n",
            't' => "\t",
            'r' => "\r",
            default => $m[1],
        },
        $literal
    );
}

/**
 * Parses a .po file into msgid key => msgstr value, both in .mo form: a
 * context is joined to the msgid with \x04, and plural forms with \x00. The
 * header is the entry with the key ''. Untranslated and fuzzy entries are
 * left out, the same as msgfmt does, so WordPress falls back to the English.
 *
 * Reads the subset of the PO format tools/make-pot.php writes and a
 * translator edits: comments, msgctxt, msgid, msgid_plural, msgstr and
 * msgstr[n], each with continuation lines.
 *
 * @return array<string, string>
 */
function eventmesh_read_po(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if (false === $lines) {
        throw new RuntimeException('Could not read ' . $path);
    }

    $entries = [];
    $entry = [];
    $fuzzy = false;
    $field = null;

    $flush = static function () use (&$entries, &$entry, &$fuzzy, &$field): void {
        if (isset($entry['msgid'])) {
            $strings = [];

            foreach ($entry as $key => $value) {
                if (str_starts_with($key, 'msgstr')) {
                    $strings[$key] = $value;
                }
            }

            ksort($strings, SORT_NATURAL);
            $translated = '' !== implode('', $strings);
            $isHeader = '' === $entry['msgid'] && ! isset($entry['msgctxt']);

            if ($translated && (! $fuzzy || $isHeader)) {
                $key = $entry['msgid'];

                if (isset($entry['msgid_plural'])) {
                    $key .= "\0" . $entry['msgid_plural'];
                }

                if (isset($entry['msgctxt'])) {
                    $key = $entry['msgctxt'] . "\x04" . $key;
                }

                $entries[$key] = implode("\0", $strings);
            }
        }

        $entry = [];
        $fuzzy = false;
        $field = null;
    };

    foreach ($lines as $number => $line) {
        $line = trim($line);

        if ('' === $line) {
            $flush();
            continue;
        }

        if (str_starts_with($line, '#,')) {
            $fuzzy = $fuzzy || str_contains($line, 'fuzzy');
            continue;
        }

        if (str_starts_with($line, '#')) {
            continue;
        }

        if (1 === preg_match('/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+"(.*)"$/s', $line, $m)) {
            // A new msgid or msgctxt after a msgstr starts the next entry
            // even without a blank line between them.
            if (in_array($m[1], ['msgid', 'msgctxt'], true) && null !== $field && str_starts_with($field, 'msgstr')) {
                $flush();
            }

            $field = $m[1];
            $entry[$field] = eventmesh_po_unescape($m[2]);
            continue;
        }

        if (1 === preg_match('/^"(.*)"$/s', $line, $m) && null !== $field) {
            $entry[$field] .= eventmesh_po_unescape($m[1]);
            continue;
        }

        throw new RuntimeException(sprintf('%s:%d: cannot read "%s"', $path, $number + 1, $line));
    }

    $flush();

    return $entries;
}

/**
 * The translatable strings in a block editor script: every __(), _x(), _n()
 * and _nx() call whose arguments are plain string literals, with the
 * 'eventmesh' domain last. The block scripts are hand-written ES5 without a
 * build step, so a regular expression over the source is enough.
 *
 * Each result has the msgid, the plural (or null), the context (or null), the
 * 1-based line and the text of a preceding translators comment ('' if none).
 * Calls with another domain (or a non-literal argument) are returned under
 * 'skipped' as line numbers so make-pot can complain about them.
 *
 * @return array{
 *     strings: list<array{msgid: string, plural: ?string, context: ?string, line: int, comment: string}>,
 *     skipped: list<int>
 * }
 */
function eventmesh_js_strings(string $source, string $domain = 'eventmesh'): array
{
    $literal = '(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")';
    $strings = [];
    $skipped = [];

    $decode = static function (string $raw): string {
        $inner = substr($raw, 1, -1);

        return (string) preg_replace_callback(
            '/\\\\(u[0-9a-fA-F]{4}|.)/s',
            static function (array $m): string {
                if ('u' === $m[1][0] && 5 === strlen($m[1])) {
                    return mb_chr((int) hexdec(substr($m[1], 1)), 'UTF-8');
                }

                return match ($m[1]) {
                    'n' => "\n",
                    't' => "\t",
                    default => $m[1],
                };
            },
            $inner
        );
    };

    // The function name must not follow a "." or a word character, so
    // i18n.__ (the alias assignment) and other names ending in "__" are not
    // read as calls.
    if (! preg_match_all('/(?<![\w.$])(__|_x|_n|_nx)\s*\(/', $source, $calls, PREG_OFFSET_CAPTURE)) {
        return ['strings' => [], 'skipped' => []];
    }

    foreach ($calls[1] as $index => [$name, $offset]) {
        $start = $calls[0][$index][1] + strlen($calls[0][$index][0]);
        $line = substr_count($source, "\n", 0, $offset) + 1;
        $argCount = ['__' => 2, '_x' => 3, '_n' => 4, '_nx' => 5][$name];

        // Literal arguments only, except _n()'s and _nx()'s number.
        $parts = [];
        $pos = $start;
        $ok = true;

        for ($arg = 0; $arg < $argCount; $arg++) {
            $isNumber = ('_n' === $name && 2 === $arg) || ('_nx' === $name && 2 === $arg);
            $pattern = $isNumber
                ? '/\G\s*([^,()]+(?:\([^()]*\))?[^,()]*)\s*' . ($arg < $argCount - 1 ? ',' : '\)') . '/A'
                : '/\G\s*(' . $literal . ')\s*' . ($arg < $argCount - 1 ? ',' : ',?\s*\)') . '/As';

            if (1 !== preg_match($pattern, $source, $m, 0, $pos)) {
                $ok = false;
                break;
            }

            $parts[] = $isNumber ? null : $decode($m[1]);
            $pos += strlen($m[0]);
        }

        if (! $ok || $domain !== $parts[$argCount - 1]) {
            $skipped[] = $line;
            continue;
        }

        // A translators comment directly above the call, or above the
        // sprintf( that wraps it.
        $before = rtrim(substr($source, 0, $offset));
        $before = (string) preg_replace('/(?:sprintf\s*\(|\()\s*$/', '', $before);
        $comment = '';

        if (1 === preg_match('#/\*\s*translators:\s*(.*?)\s*\*/\s*$#s', rtrim($before), $c)) {
            $comment = trim((string) preg_replace('/\s+/', ' ', $c[1]));
        }

        $strings[] = [
            'msgid' => (string) $parts[0],
            'plural' => in_array($name, ['_n', '_nx'], true) ? $parts[1] : null,
            'context' => match ($name) {
                '_x' => $parts[1],
                '_nx' => $parts[3],
                default => null,
            },
            'line' => $line,
            'comment' => $comment,
        ];
    }

    return ['strings' => $strings, 'skipped' => $skipped];
}

/**
 * The block editor scripts of this plugin: script path relative to the
 * plugin root => the script handle WordPress registers for it from
 * block.json (generate_block_asset_handle(): the block name with "/" made
 * "-", then "-editor-script").
 *
 * @return array<string, string>
 */
function eventmesh_block_editor_scripts(string $root): array
{
    $scripts = [];

    foreach (glob($root . '/src/blocks/*/block.json') ?: [] as $blockJson) {
        $metadata = json_decode((string) file_get_contents($blockJson), true);

        if (! is_array($metadata) || 'file:./index.js' !== ($metadata['editorScript'] ?? null)) {
            continue;
        }

        $relative = substr(dirname($blockJson), strlen($root) + 1) . '/index.js';
        $handle = str_replace('/', '-', (string) $metadata['name']) . '-editor-script';
        $scripts[str_replace('\\', '/', $relative)] = $handle;
    }

    ksort($scripts);

    return $scripts;
}

/**
 * Writes one JSON translation file per block editor script and locale, in
 * the Jed 1.x form WordPress's load_script_textdomain() reads:
 * languages/eventmesh-<locale>-<script handle>.json. Each file holds only
 * the strings that script uses and that have a translation. Files from an
 * earlier run are removed first, so a script that lost all its strings does
 * not keep a stale file.
 *
 * @return list<string> The written files.
 */
function eventmesh_make_json(string $root, string $domain = 'eventmesh'): array
{
    $languages = $root . '/languages';

    foreach (glob($languages . '/' . $domain . '-*.json') ?: [] as $old) {
        unlink($old);
    }

    $scripts = eventmesh_block_editor_scripts($root);
    $written = [];

    foreach (glob($languages . '/' . $domain . '-*.po') ?: [] as $po) {
        $locale = substr(basename($po, '.po'), strlen($domain) + 1);
        $translations = eventmesh_read_po($po);
        $header = $translations[''] ?? '';
        $pluralForms = 1 === preg_match('/^Plural-Forms:\s*(.+)$/mi', $header, $m)
            ? trim($m[1])
            : 'nplurals=2; plural=(n != 1);';

        foreach ($scripts as $relative => $handle) {
            $messages = [];

            $found = eventmesh_js_strings((string) file_get_contents($root . '/' . $relative), $domain);

            foreach ($found['strings'] as $string) {
                $key = $string['msgid'];

                if (null !== $string['plural']) {
                    $key .= "\0" . $string['plural'];
                }

                if (null !== $string['context']) {
                    $key = $string['context'] . "\x04" . $key;
                }

                if (! isset($translations[$key])) {
                    continue;
                }

                // Jed keys a context the same way as .mo: context, \u0004,
                // msgid; the plural is not part of the key.
                $jedKey = null === $string['context']
                    ? $string['msgid']
                    : $string['context'] . "\x04" . $string['msgid'];
                $messages[$jedKey] = explode("\0", $translations[$key]);
            }

            if ([] === $messages) {
                continue;
            }

            ksort($messages, SORT_STRING);

            $json = [
                'translation-revision-date' => '',
                'generator' => 'EventMesh tools/make-json.php',
                'source' => $relative,
                'domain' => 'messages',
                'locale_data' => [
                    'messages' => ['' => [
                        'domain' => 'messages',
                        'lang' => $locale,
                        'plural-forms' => $pluralForms,
                    ]] + $messages,
                ],
            ];

            $target = $languages . '/' . $domain . '-' . $locale . '-' . $handle . '.json';
            $encoded = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (false === file_put_contents($target, $encoded . "\n")) {
                throw new RuntimeException('Could not write ' . $target);
            }

            $written[] = $target;
        }
    }

    return $written;
}
