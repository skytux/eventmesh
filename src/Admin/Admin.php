<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Core\Container;
use EventMesh\Services\SyncRunner;

final class Admin
{
    /**
     * Shared across every EventMesh admin page/action - a single place to
     * change if a dedicated capability is ever introduced instead of
     * reusing WordPress's own manage_options.
     */
    public const CAPABILITY = 'manage_options';

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
            'admin_post_eventmesh_sync',
            [$this, 'handleSync']
        );

        add_action(
            'admin_notices',
            [$this, 'renderSyncNotice']
        );

        add_action(
            'admin_post_eventmesh_save_settings',
            [$this->container->get(SettingsPage::class), 'save']
        );

        add_action(
            'admin_post_eventmesh_factory_reset',
            [$this->container->get(SettingsPage::class), 'factoryReset']
        );

        add_action(
            'admin_post_eventmesh_save_sources',
            [$this->container->get(SourcesPage::class), 'save']
        );

        add_action(
            'admin_post_eventmesh_dashboard_toggle',
            [$this->container->get(DashboardPage::class), 'saveBackgroundSyncToggle']
        );

        add_filter('cron_schedules', [$this, 'registerCronSchedules']);
        add_action('init', [$this, 'scheduleBackgroundSync']);
        add_action('init', [$this, 'registerBlock']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontendStyles']);
        add_action('eventmesh/background_sync', [$this, 'runBackgroundSync']);
        add_shortcode('eventmesh_status', [$this, 'renderStatusShortcode']);
        add_shortcode('eventmesh_events', [$this, 'renderEventsShortcode']);
    }

    /**
     * @return array<string, array{interval: int, display: string}>
     */
    public function availableSyncIntervals(): array
    {
        return [
            'eventmesh_15min' => __('Every 15 minutes', 'eventmesh'),
            'eventmesh_30min' => __('Every 30 minutes', 'eventmesh'),
            'hourly' => __('Hourly', 'eventmesh'),
            'twicedaily' => __('Twice daily', 'eventmesh'),
            'daily' => __('Daily', 'eventmesh'),
        ];
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     *
     * @return array<string, array{interval: int, display: string}>
     */
    public function registerCronSchedules(array $schedules): array
    {
        $schedules['eventmesh_15min'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Every 15 minutes', 'eventmesh'),
        ];
        $schedules['eventmesh_30min'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('Every 30 minutes', 'eventmesh'),
        ];

        return $schedules;
    }

    public function configuredSyncInterval(): string
    {
        $configured = (string) get_option('eventmesh_sync_interval', 'hourly');

        return array_key_exists($configured, $this->availableSyncIntervals()) ? $configured : 'hourly';
    }

    public function registerMenus(): void
    {
        add_menu_page(
            __('EventMesh', 'eventmesh'),
            __('EventMesh', 'eventmesh'),
            self::CAPABILITY,
            'eventmesh',
            [$this->container->get(DashboardPage::class), 'render'],
            'dashicons-share',
            56
        );

        add_submenu_page(
            'eventmesh',
            __('Dashboard', 'eventmesh'),
            __('Dashboard', 'eventmesh'),
            self::CAPABILITY,
            'eventmesh',
            [$this->container->get(DashboardPage::class), 'render']
        );

        add_submenu_page(
            'eventmesh',
            __('Sources', 'eventmesh'),
            __('Sources', 'eventmesh'),
            self::CAPABILITY,
            'eventmesh-sources',
            [$this->container->get(SourcesPage::class), 'render']
        );

        add_submenu_page(
            'eventmesh',
            __('Diagnostics', 'eventmesh'),
            __('Diagnostics', 'eventmesh'),
            self::CAPABILITY,
            'eventmesh-diagnostics',
            [$this->container->get(DiagnosticsPage::class), 'render']
        );

        add_submenu_page(
            'eventmesh',
            __('Settings', 'eventmesh'),
            __('Settings', 'eventmesh'),
            self::CAPABILITY,
            'eventmesh-settings',
            [$this->container->get(SettingsPage::class), 'render']
        );
    }

    public function handleSync(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to run this action.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_sync');

        $result = $this->container->get(DashboardPage::class)->runSync();

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

        $configuredInterval = $this->configuredSyncInterval();
        $scheduled = wp_get_scheduled_event('eventmesh/background_sync');

        if (false !== $scheduled && $scheduled->schedule === $configuredInterval) {
            return;
        }

        if (false !== $scheduled) {
            wp_unschedule_event($scheduled->timestamp, 'eventmesh/background_sync');
        }

        wp_schedule_event(time() + 300, $configuredInterval, 'eventmesh/background_sync');
    }

    public function runBackgroundSync(): void
    {
        $enabled = get_option('eventmesh_enable_background_sync', '1');

        if ('1' !== $enabled) {
            return;
        }

        $result = $this->container->get(SyncRunner::class)->run();

        // Persist unconditionally (not only when events were processed), so
        // the dashboard's "Last sync" timestamp reflects every completed cron
        // run - matching the manual "Sync now" and the visitor fallback paths.
        $this->container->get(DashboardPage::class)->persistSyncSummary(
            [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'failed' => $result['failed'],
                'skipped' => $result['skipped'],
                'archived' => $result['archived'],
            ],
            $result['created'] + $result['updated']
        );
    }

    public function registerBlock(): void
    {
        $this->container->get(EventListBlock::class)->register();
    }

    public function enqueueFrontendStyles(): void
    {
        $path = EVENTMESH_PLUGIN_DIR . 'assets/css/frontend.css';

        if (! is_readable($path)) {
            return;
        }

        wp_enqueue_style(
            'eventmesh-frontend',
            EVENTMESH_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            (string) filemtime($path)
        );
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
