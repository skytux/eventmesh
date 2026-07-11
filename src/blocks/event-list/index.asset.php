<?php

/**
 * Hand-authored equivalent of a `wp-scripts build` asset file: declares the
 * script dependencies and cache-busting version for index.js, since this
 * block ships without a Node/npm build step.
 */

declare(strict_types=1);

return [
    'dependencies' => [
        'wp-blocks',
        'wp-element',
        'wp-block-editor',
        'wp-components',
        'wp-i18n',
        'wp-server-side-render',
    ],
    'version' => (string) filemtime(__DIR__ . '/index.js'),
];
