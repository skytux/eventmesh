<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Services\ArtistMap;
use EventMesh\Services\SourceSettings;

final class SettingsPage
{
    public function __construct(
        private readonly View $view,
        private readonly ArtistMap $artistMap,
        private readonly SourceSettings $sourceSettings
    ) {
    }

    public function render(): void
    {
        $this->view->render(
            'settings',
            [
                'holvi_source_urls' => get_option('eventmesh_holvi_source_urls', ''),
                'artist_map_json' => wp_json_encode($this->artistMap->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
                'source_settings' => $this->sourceSettings->all(),
            ]
        );
    }

    public function save(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to save this setting.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_settings');

        $urls = isset($_POST['eventmesh_holvi_source_urls'])
            ? wp_unslash((string) $_POST['eventmesh_holvi_source_urls'])
            : '';

        $artistMapJson = isset($_POST['eventmesh_artist_map'])
            ? wp_unslash((string) $_POST['eventmesh_artist_map'])
            : '{}';

        $sourceSettings = isset($_POST['eventmesh_source_enabled'])
            ? array_map('absint', (array) $_POST['eventmesh_source_enabled'])
            : [];

        $artistMapData = json_decode($artistMapJson, true);

        if (! is_array($artistMapData)) {
            wp_die(esc_html__('The artist map must be valid JSON.', 'eventmesh'));
        }

        update_option('eventmesh_holvi_source_urls', $urls);
        update_option('eventmesh_artist_map', $artistMapJson);

        foreach ($sourceSettings as $sourceId => $enabled) {
            $this->sourceSettings->setEnabled((string) $sourceId, 1 === $enabled);
        }

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'eventmesh-settings'],
                admin_url('admin.php')
            )
        );
        exit;
    }
}
