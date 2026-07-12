<?php

declare(strict_types=1);

return [
    'dependencies' => [
        'wp-blocks',
        'wp-element',
        'wp-block-editor',
        'wp-i18n',
    ],
    'version' => (string) filemtime(__DIR__ . '/index.js'),
];
