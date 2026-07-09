<?php
/**
 * Dashboard admin view.
 *
 * @var int    $connector_count Number of registered connectors.
 * @var string $kernel_status   Human-readable kernel status.
 * @var string $version         Plugin version.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php esc_html_e('Dashboard', 'eventmesh'); ?></h1>

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
        </tbody>
    </table>
</div>
