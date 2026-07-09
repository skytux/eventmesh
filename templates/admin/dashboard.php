<?php
/**
 * Dashboard admin view.
 *
 * @var int    $connector_count Number of registered connectors.
 * @var int    $event_count     Number of published synchronized events.
 * @var string $kernel_status   Human-readable kernel status.
 * @var string $version         Plugin version.
 * @var array{created: int, updated: int, failed: int, synced: int, timestamp: int}|null $last_sync Summary of the most recent sync run.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php esc_html_e('Dashboard', 'eventmesh'); ?></h1>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eventmesh_sync_holvi">
        <?php wp_nonce_field('eventmesh_sync_holvi'); ?>
        <?php submit_button(__('Sync Holvi events', 'eventmesh')); ?>
    </form>

    <table class="widefat striped">
        <tbody>
            <tr>
                <th scope="row"><?php esc_html_e('Version', 'eventmesh'); ?></th>
                <td><?php echo esc_html($version); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Kernel', 'eventmesh'); ?></th>
                <td><?php echo esc_html($kernel_status); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Connectors', 'eventmesh'); ?></th>
                <td><?php echo esc_html((string) $connector_count); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Published events', 'eventmesh'); ?></th>
                <td><?php echo esc_html((string) $event_count); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Last sync', 'eventmesh'); ?></th>
                <td>
                    <?php if (null === $last_sync) : ?>
                        <?php esc_html_e('No sync yet.', 'eventmesh'); ?>
                    <?php else : ?>
                        <?php echo esc_html(sprintf(
                            __('Created %1$d, updated %2$d, failed %3$d at %4$s.', 'eventmesh'),
                            $last_sync['created'],
                            $last_sync['updated'],
                            $last_sync['failed'],
                            date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_sync['timestamp'])
                        )); ?>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>
