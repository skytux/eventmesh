<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Content\EventPostType;
use EventMesh\Services\ConnectorManager;
use EventMesh\Services\SyncRunner;
use EventMesh\Sync\EventSynchronizer;

final class DashboardPage
{
    public function __construct(
        private readonly View $view,
        private readonly ConnectorManager $connectors,
        private readonly EventSynchronizer $synchronizer,
        private readonly SyncRunner $syncRunner
    ) {
    }

    public function render(): void
    {
        $this->view->render(
            'dashboard',
            [
                'connector_count' => $this->connectors->count(),
                'event_count' => wp_count_posts(EventPostType::NAME)->publish ?? 0,
                'kernel_status' => __('Running', 'eventmesh'),
                'version' => EVENTMESH_VERSION,
                'background_sync_enabled' => '1' === (string) get_option('eventmesh_enable_background_sync', '1'),
                'last_sync' => $this->lastSyncSummary(),
                'next_sync_timestamp' => wp_next_scheduled('eventmesh/background_sync'),
            ]
        );
    }

    public function saveBackgroundSyncToggle(): void
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to save this setting.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_dashboard_toggle');

        $enabled = isset($_POST['eventmesh_enable_background_sync'])
            ? '1' === (string) $_POST['eventmesh_enable_background_sync']
            : false;

        update_option('eventmesh_enable_background_sync', $enabled ? '1' : '0');

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'eventmesh'],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * @return array{success: bool, synced: int, message: string}
     */
    public function runSync(): array
    {
        if (0 === $this->connectors->count()) {
            $this->markSyncState('error', __('No connectors are registered.', 'eventmesh'));

            return [
                'success' => false,
                'synced' => 0,
                'message' => __('No connectors are registered.', 'eventmesh'),
            ];
        }

        $this->markSyncState('running', __('Sync in progress…', 'eventmesh'));

        $result = $this->syncRunner->run();
        $synced = $result['created'] + $result['updated'];

        $this->persistSyncSummary(
            [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'failed' => $result['failed'],
                'skipped' => $result['skipped'],
                'archived' => $result['archived'],
            ],
            $synced
        );

        if (0 === $result['processed']) {
            $this->markSyncState('completed', __('No events were found.', 'eventmesh'));

            return [
                'success' => true,
                'synced' => 0,
                'message' => __('No events were found.', 'eventmesh'),
            ];
        }

        $message = sprintf(
            /* translators: 1: created count, 2: updated count, 3: failed count, 4: skipped count, 5: archived count */
            __('Created %1$d, updated %2$d, failed %3$d, skipped %4$d, archived %5$d.', 'eventmesh'),
            $result['created'],
            $result['updated'],
            $result['failed'],
            $result['skipped'],
            $result['archived']
        );

        $this->markSyncState('completed', $message);

        return [
            'success' => true,
            'synced' => $synced,
            'message' => $message,
        ];
    }

    /**
     * @param array{created: int, updated: int, failed: int, skipped: int, archived?: int} $result
     */
    public function persistSyncSummary(array $result, int $synced): void
    {
        set_transient(
            'eventmesh_last_sync',
            [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'failed' => $result['failed'],
                'skipped' => $result['skipped'],
                'archived' => $result['archived'] ?? 0,
                'synced' => $synced,
                'timestamp' => time(),
            ],
            24 * HOUR_IN_SECONDS
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function renderStatusShortcode(array $attributes = []): string
    {
        $syncState = $this->syncState();
        $lastSync = $this->lastSyncSummary();
        $nextRun = wp_next_scheduled('eventmesh/background_sync');
        $enabled = '1' === (string) get_option('eventmesh_enable_background_sync', '1');

        ob_start();

        include EVENTMESH_PLUGIN_DIR . 'templates/frontend/sync-status.php';

        return (string) ob_get_clean();
    }

    /**
     * @return array{status: string, message: string, timestamp: int}|null
     */
    public function syncState(): ?array
    {
        $state = get_transient('eventmesh_sync_status');

        if (! is_array($state)) {
            return null;
        }

        return [
            'status' => (string) ($state['status'] ?? 'idle'),
            'message' => (string) ($state['message'] ?? ''),
            'timestamp' => (int) ($state['timestamp'] ?? 0),
        ];
    }

    private function markSyncState(string $status, string $message): void
    {
        set_transient(
            'eventmesh_sync_status',
            [
                'status' => $status,
                'message' => $message,
                'timestamp' => time(),
            ],
            2 * HOUR_IN_SECONDS
        );
    }

    /**
     * @return array{
     *     created: int,
     *     updated: int,
     *     failed: int,
     *     skipped: int,
     *     archived: int,
     *     synced: int,
     *     timestamp: int
     * }|null
     */
    public function lastSyncSummary(): ?array
    {
        $summary = get_transient('eventmesh_last_sync');

        if (! is_array($summary)) {
            return null;
        }

        return [
            'created' => (int) ($summary['created'] ?? 0),
            'updated' => (int) ($summary['updated'] ?? 0),
            'failed' => (int) ($summary['failed'] ?? 0),
            'skipped' => (int) ($summary['skipped'] ?? 0),
            'archived' => (int) ($summary['archived'] ?? 0),
            'synced' => (int) ($summary['synced'] ?? 0),
            'timestamp' => (int) ($summary['timestamp'] ?? 0),
        ];
    }
}
