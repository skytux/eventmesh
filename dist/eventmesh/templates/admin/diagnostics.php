<?php
/**
 * Diagnostics admin view.
 *
 * @var string $php_version       PHP version.
 * @var string $plugin_version    Plugin version.
 * @var string $wordpress_version WordPress version.
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
</div>
