<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Services\ArtistMap;
use EventMesh\Services\HolviSourceManager;
use EventMesh\Services\SourceSettings;

final class SettingsPage
{
    public function __construct(
        private readonly View $view,
        private readonly ArtistMap $artistMap,
        private readonly SourceSettings $sourceSettings,
        private readonly HolviSourceManager $holviSourceManager
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
                'background_sync_enabled' => '1' === (string) get_option('eventmesh_enable_background_sync', '1'),
                'holvi_sources' => $this->holviSourceManager->all(),
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

        $backgroundSyncEnabled = isset($_POST['eventmesh_enable_background_sync'])
            ? '1' === (string) $_POST['eventmesh_enable_background_sync']
            : false;

        $holviSources = isset($_POST['eventmesh_holvi_sources'])
            ? (array) $_POST['eventmesh_holvi_sources']
            : [];

        $artistMapData = json_decode($artistMapJson, true);

        if (! is_array($artistMapData)) {
            wp_die(esc_html__('The artist map must be valid JSON.', 'eventmesh'));
        }

        update_option('eventmesh_holvi_source_urls', $urls);
        update_option('eventmesh_artist_map', $artistMapJson);
        update_option('eventmesh_enable_background_sync', $backgroundSyncEnabled ? '1' : '0');

        $this->holviSourceManager->save($holviSources);

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
