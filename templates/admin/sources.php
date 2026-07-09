<?php
/**
 * Sources admin view.
 *
 * @var array<int, array{id: string, label: string}> $source_rows Registered connector rows.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php esc_html_e('Sources', 'eventmesh'); ?></h1>

    <?php if ([] === $source_rows) : ?>
        <div class="notice notice-info inline">
            <p>
                <?php esc_html_e('No connectors are installed yet.', 'eventmesh'); ?>
            </p>
        </div>
    <?php endif; ?>

    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Connector', 'eventmesh'); ?></th>
                <th scope="col"><?php esc_html_e('ID', 'eventmesh'); ?></th>
                <th scope="col"><?php esc_html_e('Status', 'eventmesh'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ([] === $source_rows) : ?>
                <tr>
                    <td colspan="3">
                        <?php esc_html_e('Install a connector plugin to add event sources.', 'eventmesh'); ?>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ($source_rows as $source_row) : ?>
                    <tr>
                        <td><?php echo esc_html($source_row['label']); ?></td>
                        <td><code><?php echo esc_html($source_row['id']); ?></code></td>
                        <td><?php esc_html_e('Available', 'eventmesh'); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
