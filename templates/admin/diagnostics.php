<?php
/**
 * Diagnostics admin view.
 *
 * @var string $php_version       PHP version.
 * @var string $plugin_version    Plugin version.
 * @var string $wordpress_version WordPress version.
 * @var array<int, array{level: string, message: string, timestamp: int}> $recent_logs Recent EventMesh log entries.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
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
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $entry['timestamp'])); ?></td>
                        <td><?php echo esc_html((string) $entry['level']); ?></td>
                        <td><?php echo esc_html((string) $entry['message']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
