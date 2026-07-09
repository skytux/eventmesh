<?php
/**
 * Settings admin view.
 *
 * @var string $holvi_source_urls Holvi source URLs configured for syncing.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php esc_html_e('Settings', 'eventmesh'); ?></h1>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eventmesh_save_settings">
        <?php wp_nonce_field('eventmesh_settings'); ?>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="eventmesh_holvi_source_urls">
                            <?php esc_html_e('Holvi source URLs', 'eventmesh'); ?>
                        </label>
                    </th>
                    <td>
                        <textarea
                            id="eventmesh_holvi_source_urls"
                            name="eventmesh_holvi_source_urls"
                            rows="6"
                            cols="80"
                            class="large-text code"
                        ><?php echo esc_textarea($holvi_source_urls); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('Enter one Holvi event URL per line.', 'eventmesh'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php submit_button(__('Save Settings', 'eventmesh')); ?>
    </form>
</div>
