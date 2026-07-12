<?php

/**
 * Diagnostics admin view.
 *
 * @var string $php_version       PHP version.
 * @var string $plugin_version    Plugin version.
 * @var string $wordpress_version WordPress version.
 * @var array{
 *     background_sync_enabled: bool,
 *     wp_cron_disabled: bool,
 *     next_scheduled: int|false,
 *     is_overdue: bool,
 *     last_attempt: int,
 *     last_sync: int,
 *     lock_held: bool,
 *     lock_age: int,
 *     fastcgi_available: bool,
 *     recommendation: string|null
 * } $sync_health Background-sync health snapshot.
 * @var array<int, array{level: string, message: string, timestamp: int}> $recent_logs Recent EventMesh log entries.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eventmesh_datetime = static function (int $timestamp): string {
    if (0 === $timestamp) {
        return __('Never', 'eventmesh');
    }

    return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
};
?>

<div class="wrap">
    <h1><?php esc_html_e('Diagnostics', 'eventmesh'); ?></h1>

    <table class="widefat striped">
        <tbody>
            <tr>
                <th scope="row"><?php esc_html_e('PHP', 'eventmesh'); ?></th>
                <td><?php echo esc_html($php_version); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('WordPress', 'eventmesh'); ?></th>
                <td><?php echo esc_html($wordpress_version); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Plugin', 'eventmesh'); ?></th>
                <td><?php echo esc_html($plugin_version); ?></td>
            </tr>
        </tbody>
    </table>

    <h2><?php esc_html_e('Background sync health', 'eventmesh'); ?></h2>

    <?php if (null !== $sync_health['recommendation']) : ?>
        <div class="notice notice-warning inline">
            <p><?php echo esc_html($sync_health['recommendation']); ?></p>
        </div>
    <?php endif; ?>

    <table class="widefat striped">
        <tbody>
            <tr>
                <th scope="row"><?php esc_html_e('Background sync', 'eventmesh'); ?></th>
                <td>
                    <?php
                    echo $sync_health['background_sync_enabled']
                        ? esc_html__('Enabled', 'eventmesh')
                        : esc_html__('Disabled', 'eventmesh');
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('DISABLE_WP_CRON', 'eventmesh'); ?></th>
                <td>
                    <?php
                    echo $sync_health['wp_cron_disabled']
                        ? esc_html__('true (WP-Cron off; needs an external trigger)', 'eventmesh')
                        : esc_html__('false (WordPress spawns WP-Cron itself, via loopback)', 'eventmesh');
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Next scheduled sync', 'eventmesh'); ?></th>
                <td>
                    <?php
                    if (false === $sync_health['next_scheduled']) {
                        esc_html_e('Not scheduled', 'eventmesh');
                    } else {
                        echo esc_html($eventmesh_datetime((int) $sync_health['next_scheduled']));

                        if ($sync_health['is_overdue']) {
                            echo ' <strong>' . esc_html__('(overdue)', 'eventmesh') . '</strong>';
                        }
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Last sync attempt', 'eventmesh'); ?></th>
                <td><?php echo esc_html($eventmesh_datetime($sync_health['last_attempt'])); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Last completed sync', 'eventmesh'); ?></th>
                <td><?php echo esc_html($eventmesh_datetime($sync_health['last_sync'])); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Sync lock', 'eventmesh'); ?></th>
                <td>
                    <?php
                    if ($sync_health['lock_held']) {
                        printf(
                            /* translators: %d: number of seconds the sync lock has been held. */
                            esc_html__('Held (%ds) — a sync is running or recently died', 'eventmesh'),
                            (int) $sync_health['lock_age']
                        );
                    } else {
                        esc_html_e('Free', 'eventmesh');
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('fastcgi_finish_request', 'eventmesh'); ?></th>
                <td>
                    <?php
                    echo $sync_health['fastcgi_available']
                        ? esc_html__('Available (fallback sync runs without delaying visitors)', 'eventmesh')
                        : esc_html__('Not available (fallback sync runs inline on a visitor request)', 'eventmesh');
                    ?>
                </td>
            </tr>
        </tbody>
    </table>

    <h2><?php esc_html_e('Recent EventMesh activity', 'eventmesh'); ?></h2>

    <?php if ([] === $recent_logs) : ?>
        <p><?php esc_html_e('No recent log entries yet.', 'eventmesh'); ?></p>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Time', 'eventmesh'); ?></th>
                    <th><?php esc_html_e('Level', 'eventmesh'); ?></th>
                    <th><?php esc_html_e('Message', 'eventmesh'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_logs as $entry) : ?>
                    <tr>
                        <td><?php echo esc_html($eventmesh_datetime((int) $entry['timestamp'])); ?></td>
                        <td><?php echo esc_html((string) $entry['level']); ?></td>
                        <td><?php echo esc_html((string) $entry['message']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
