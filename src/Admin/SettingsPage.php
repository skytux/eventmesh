<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Services\ArtistMap;
use EventMesh\Services\SourceSettings;
use EventMesh\Support\FactoryReset;

final class SettingsPage
{
    public function __construct(
        private readonly View $view,
        private readonly ArtistMap $artistMap,
        private readonly SourceSettings $sourceSettings,
        private readonly Admin $admin
    ) {
    }

    public function render(): void
    {
        $this->view->render(
            'settings',
            [
                'artist_map_json' => wp_json_encode($this->artistMap->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
                'source_settings' => $this->sourceSettings->all(),
                'background_sync_enabled' => '1' === (string) get_option('eventmesh_enable_background_sync', '1'),
                'sync_interval' => $this->admin->configuredSyncInterval(),
                'sync_intervals' => $this->admin->availableSyncIntervals(),
                'delete_data_on_uninstall' => '1' === (string) get_option('eventmesh_delete_data_on_uninstall', '0'),
            ]
        );
    }

    public function save(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to save this setting.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_settings');

        $artistMapJson = isset($_POST['eventmesh_artist_map'])
            ? wp_unslash((string) $_POST['eventmesh_artist_map'])
            : '{}';

        $sourceSettings = isset($_POST['eventmesh_source_enabled'])
            ? array_map('absint', (array) $_POST['eventmesh_source_enabled'])
            : [];

        $backgroundSyncEnabled = isset($_POST['eventmesh_enable_background_sync'])
            ? '1' === (string) $_POST['eventmesh_enable_background_sync']
            : false;

        $syncInterval = isset($_POST['eventmesh_sync_interval'])
            ? sanitize_key((string) $_POST['eventmesh_sync_interval'])
            : 'hourly';

        $deleteDataOnUninstall = isset($_POST['eventmesh_delete_data_on_uninstall'])
            ? '1' === (string) $_POST['eventmesh_delete_data_on_uninstall']
            : false;

        if (! array_key_exists($syncInterval, $this->admin->availableSyncIntervals())) {
            $syncInterval = 'hourly';
        }

        $artistMapData = json_decode($artistMapJson, true);

        if (! is_array($artistMapData)) {
            wp_die(esc_html__('The artist map must be valid JSON.', 'eventmesh'));
        }

        update_option('eventmesh_artist_map', $artistMapJson);
        update_option('eventmesh_enable_background_sync', $backgroundSyncEnabled ? '1' : '0');
        update_option('eventmesh_sync_interval', $syncInterval);
        update_option('eventmesh_delete_data_on_uninstall', $deleteDataOnUninstall ? '1' : '0');

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

    /**
     * Wipes all synced events, performer terms, options, and transients back
     * to a fresh-install state, without deactivating the plugin. Intended
     * for starting over during setup/testing.
     */
    public function factoryReset(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_factory_reset');

        $result = FactoryReset::run();

        set_transient(
            'eventmesh_sync_notice',
            [
                'type' => 'success',
                'message' => sprintf(
                    /* translators: 1: number of deleted events, 2: number of deleted performer terms */
                    __('Factory reset complete: removed %1$d event(s) and %2$d performer term(s). All settings reset.', 'eventmesh'),
                    $result['deleted_events'],
                    $result['deleted_terms']
                ),
            ],
            60
        );

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'eventmesh-settings'],
                admin_url('admin.php')
            )
        );
        exit;
    }
}
