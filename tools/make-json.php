<?php

/**
 * Writes the JSON translations the block editor scripts load, one file per
 * script and locale: languages/eventmesh-<locale>-<script handle>.json,
 * e.g. languages/eventmesh-fi-eventmesh-ticket-button-editor-script.json.
 *
 * A pure-PHP stand-in for WP-CLI's `i18n make-json`, because the dev
 * container has no WP-CLI. It reads each languages/*.po and each block's
 * index.js and keeps the strings that script uses. WordPress finds the files
 * by script handle (load_script_textdomain() tries
 * "<domain>-<locale>-<handle>.json" first) once EventListBlock::register()
 * has pointed the handles at languages/ with wp_set_script_translations().
 *
 * tools/build-zip.php runs this itself, so the files are not kept in git.
 *
 *     php tools/make-json.php
 *
 * @package EventMesh
 */

declare(strict_types=1);

require __DIR__ . '/i18n.php';

foreach (eventmesh_make_json(dirname(__DIR__)) as $file) {
    printf("%s\n", $file);
}
