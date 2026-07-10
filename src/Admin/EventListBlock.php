<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Content\EventQuery;

final class EventListBlock
{
    public function __construct(
        private readonly EventQuery $eventQuery
    ) {
    }

    public function register(): void
    {
        if (! function_exists('register_block_type')) {
            return;
        }

        $assetFile = EVENTMESH_PLUGIN_DIR . 'build/event-list-block.asset.php';

        if (! is_readable($assetFile)) {
            $this->registerServerSideBlock();
            return;
        }

        $asset = require $assetFile;

        wp_register_script(
            'eventmesh-event-list-block',
            EVENTMESH_PLUGIN_URL . 'build/event-list-block.js',
            $asset['dependencies'] ?? [],
            $asset['version'] ?? EVENTMESH_VERSION,
            true
        );

        register_block_type(
            'eventmesh/event-list',
            [
                'editor_script' => 'eventmesh-event-list-block',
                'render_callback' => [$this, 'render'],
                'attributes' => [
                    'count' => [
                        'type' => 'number',
                        'default' => 6,
                    ],
                    'template' => [
                        'type' => 'string',
                        'default' => 'events-list',
                    ],
                ],
            ]
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function render(array $attributes = []): string
    {
        $events = $this->eventQuery->recent(
            [
                'posts_per_page' => (int) ($attributes['count'] ?? 6),
            ]
        );

        $template = isset($attributes['template']) && is_string($attributes['template'])
            ? sanitize_file_name($attributes['template'])
            : 'events-list';

        ob_start();

        $templatePath = EVENTMESH_PLUGIN_DIR . 'templates/frontend/' . $template . '.php';

        if (is_readable($templatePath)) {
            include $templatePath;
        } else {
            include EVENTMESH_PLUGIN_DIR . 'templates/frontend/events-list.php';
        }

        return (string) ob_get_clean();
    }

    private function registerServerSideBlock(): void
    {
        register_block_type(
            'eventmesh/event-list',
            [
                'render_callback' => [$this, 'render'],
                'attributes' => [
                    'count' => [
                        'type' => 'number',
                        'default' => 6,
                    ],
                    'template' => [
                        'type' => 'string',
                        'default' => 'events-list',
                    ],
                ],
            ]
        );
    }
}
