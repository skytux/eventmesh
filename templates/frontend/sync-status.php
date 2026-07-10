<?php
/**
 * Front-end sync status view.
 *
 * @var array{status: string, message: string, timestamp: int}|null $syncState
 * @var array{created: int, updated: int, failed: int, skipped: int, synced: int, timestamp: int}|null $lastSync
 * @var int|false $nextRun
 * @var bool $enabled
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="eventmesh-sync-status">
    <h3><?php esc_html_e('EventMesh sync status', 'eventmesh'); ?></h3>
    <p>
        <strong><?php esc_html_e('Status:', 'eventmesh'); ?></strong>
        <?php echo esc_html(ucfirst((string) ($syncState['status'] ?? 'idle'))); ?>
    </p>
    <p>
        <strong><?php esc_html_e('Message:', 'eventmesh'); ?></strong>
        <?php echo esc_html((string) ($syncState['message'] ?? __('No sync has run yet.', 'eventmesh'))); ?>
    </p>
    <?php if (null !== $lastSync) : ?>
        <p>
            <strong><?php esc_html_e('Last run:', 'eventmesh'); ?></strong>
            <?php echo esc_html(sprintf(
                __('Created %1$d, updated %2$d, failed %3$d, skipped %4$d.', 'eventmesh'),
                $lastSync['created'],
                $lastSync['updated'],
                $lastSync['failed'],
                $lastSync['skipped']
            )); ?>
        </p>
    <?php endif; ?>
    <p>
        <strong><?php esc_html_e('Background sync:', 'eventmesh'); ?></strong>
        <?php echo esc_html($enabled ? __('Enabled', 'eventmesh') : __('Disabled', 'eventmesh')); ?>
    </p>
    <?php if (false !== $nextRun) : ?>
        <p>
            <strong><?php esc_html_e('Next run:', 'eventmesh'); ?></strong>
            <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $nextRun)); ?>
        </p>
    <?php endif; ?>
</div>
