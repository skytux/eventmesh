<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Core\Container;
use EventMesh\Services\SyncRunner;

final class Admin
{
    public function __construct(
        private readonly Container $container
    ) {
    }

    public function boot(): void
    {
        add_action(
            'admin_menu',
            [$this, 'registerMenus']
        );

        add_action(
            'admin_post_eventmesh_sync_holvi',
            [$this, 'handleHolviSync']
        );

        add_action(
            'admin_notices',
            [$this, 'renderSyncNotice']
        );

        add_action(
            'admin_post_eventmesh_save_settings',
            [$this->container->get(SettingsPage::class), 'save']
        );

        add_action('init', [$this, 'scheduleBackgroundSync']);
        add_action('eventmesh/background_sync', [$this, 'runBackgroundSync']);
        add_shortcode('eventmesh_status', [$this, 'renderStatusShortcode']);
        add_shortcode('eventmesh_events', [$this, 'renderEventsShortcode']);
    }

    public function registerMenus(): void
    {
        add_menu_page(
            __('EventMesh', 'eventmesh'),
            __('EventMesh', 'eventmesh'),
            'manage_options',
            'eventmesh',
            [$this->container->get(DashboardPage::class), 'render'],
            'dashicons-share',
            56
        );

        add_submenu_page(
            'eventmesh',
            __('Dashboard', 'eventmesh'),
            __('Dashboard', 'eventmesh'),
            'manage_options',
            'eventmesh',
            [$this->container->get(DashboardPage::class), 'render']
        );

        add_submenu_page(
            'eventmesh',
            __('Sources', 'eventmesh'),
            __('Sources', 'eventmesh'),
            'manage_options',
            'eventmesh-sources',
            [$this->container->get(SourcesPage::class), 'render']
        );

        add_submenu_page(
            'eventmesh',
            __('Diagnostics', 'eventmesh'),
            __('Diagnostics', 'eventmesh'),
            'manage_options',
            'eventmesh-diagnostics',
            [$this->container->get(DiagnosticsPage::class), 'render']
        );

        add_submenu_page(
            'eventmesh',
            __('Settings', 'eventmesh'),
            __('Settings', 'eventmesh'),
            'manage_options',
            'eventmesh-settings',
            [$this->container->get(SettingsPage::class), 'render']
        );
    }

    public function handleHolviSync(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to run this action.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_sync_holvi');

        $result = $this->container->get(DashboardPage::class)->syncHolvi();

        set_transient(
            'eventmesh_sync_notice',
            [
                'type' => $result['success'] ? 'success' : 'error',
                'message' => $result['message'],
            ],
            60
        );

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'eventmesh'],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function scheduleBackgroundSync(): void
    {
        if (! is_admin() && ! wp_doing_cron()) {
            return;
        }

        if (wp_next_scheduled('eventmesh/background_sync')) {
            return;
        }

        wp_schedule_event(time() + 300, 'hourly', 'eventmesh/background_sync');
    }

    public function runBackgroundSync(): void
    {
        $enabled = get_option('eventmesh_enable_background_sync', '1');

        if ('1' !== $enabled) {
            return;
        }

        $result = $this->container->get(SyncRunner::class)->run(['holvi']);

        if ($result['processed'] > 0) {
            $this->container->get(DashboardPage::class)->persistSyncSummary(
                [
                    'created' => $result['created'],
                    'updated' => $result['updated'],
                    'failed' => $result['failed'],
                    'skipped' => $result['skipped'],
                ],
                $result['created'] + $result['updated']
            );
        }
    }

    public function renderStatusShortcode(array $attributes = []): string
    {
        return $this->container->get(DashboardPage::class)->renderStatusShortcode($attributes);
    }

    public function renderEventsShortcode(array $attributes = []): string
    {
        $eventQuery = $this->container->get(\EventMesh\Content\EventQuery::class);
        $events = $eventQuery->recent(
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

    public function renderSyncNotice(): void
    {
        $notice = get_transient('eventmesh_sync_notice');

        if (! is_array($notice)) {
            return;
        }

        delete_transient('eventmesh_sync_notice');

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr((string) ($notice['type'] ?? 'info')),
            esc_html((string) ($notice['message'] ?? ''))
        );
    }
}
