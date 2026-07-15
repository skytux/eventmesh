<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Support\FactoryReset;

final class SettingsPage
{
    public function __construct(
        private readonly View $view,
        private readonly Admin $admin
    ) {
    }

    public function render(): void
    {
        $this->view->render(
            'settings',
            [
                'background_sync_enabled' => '1' === (string) get_option('eventmesh_enable_background_sync', '1'),
                'sync_interval' => $this->admin->configuredSyncInterval(),
                'sync_intervals' => $this->admin->availableSyncIntervals(),
                'delete_data_on_uninstall' => '1' === (string) get_option('eventmesh_delete_data_on_uninstall', '0'),
                'defer_embeds' => '1' === (string) get_option('eventmesh_defer_embeds', '0'),
            ]
        );
    }

    public function save(): void
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to save this setting.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_settings');

        $backgroundSyncEnabled = isset($_POST['eventmesh_enable_background_sync'])
            ? '1' === (string) $_POST['eventmesh_enable_background_sync']
            : false;

        $syncInterval = isset($_POST['eventmesh_sync_interval'])
            ? sanitize_key((string) $_POST['eventmesh_sync_interval'])
            : 'hourly';

        $deleteDataOnUninstall = isset($_POST['eventmesh_delete_data_on_uninstall'])
            ? '1' === (string) $_POST['eventmesh_delete_data_on_uninstall']
            : false;

        $deferEmbeds = isset($_POST['eventmesh_defer_embeds'])
            ? '1' === (string) $_POST['eventmesh_defer_embeds']
            : false;

        if (! array_key_exists($syncInterval, $this->admin->availableSyncIntervals())) {
            $syncInterval = 'hourly';
        }

        update_option('eventmesh_enable_background_sync', $backgroundSyncEnabled ? '1' : '0');
        update_option('eventmesh_sync_interval', $syncInterval);
        update_option('eventmesh_delete_data_on_uninstall', $deleteDataOnUninstall ? '1' : '0');
        update_option('eventmesh_defer_embeds', $deferEmbeds ? '1' : '0');

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'eventmesh-settings'],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Wipes all synced events, options, and transients back to a fresh-install
     * state, without deactivating the plugin. Intended for starting over
     * during setup/testing.
     */
    public function factoryReset(): void
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_factory_reset');

        $result = FactoryReset::run();

        set_transient(
            'eventmesh_sync_notice',
            [
                'type' => 'success',
                'message' => sprintf(
                    /* translators: %d: number of deleted events */
                    __('Factory reset complete: removed %d event(s). All settings reset.', 'eventmesh'),
                    $result['deleted_events']
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
