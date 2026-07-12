<?php
/**
 * Settings admin view.
 *
 * @var string $holvi_source_urls Holvi source URLs configured for syncing.
 * @var string $artist_map_json   Artist/provider mapping JSON.
 * @var array<string, bool> $source_settings Source enablement settings.
 * @var bool $background_sync_enabled Whether background sync is enabled.
 * @var string $sync_interval Currently configured background sync interval key.
 * @var array<string, string> $sync_intervals Available interval keys mapped to labels.
 * @var bool $delete_data_on_uninstall Whether uninstalling the plugin should also delete its data.
 * @var bool $enable_test_connector Whether the built-in dummy/test connector is enabled.
 * @var bool $test_connector_forced Whether the wp-config constant forces the test connector on.
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
                        <?php esc_html_e('Enabled sources', 'eventmesh'); ?>
                    </th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text">
                                <?php esc_html_e('Enabled sources', 'eventmesh'); ?>
                            </legend>
                            <label>
                                <input type="checkbox" name="eventmesh_source_enabled[holvi]" value="1" <?php checked($source_settings['holvi'] ?? true, true); ?> />
                                <?php esc_html_e('Holvi', 'eventmesh'); ?>
                            </label>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Background sync', 'eventmesh'); ?>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="eventmesh_enable_background_sync" value="1" <?php checked($background_sync_enabled, true); ?> />
                            <?php esc_html_e('Enable automatic background synchronization', 'eventmesh'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, EventMesh will try to sync sources automatically in the background.', 'eventmesh'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="eventmesh_sync_interval">
                            <?php esc_html_e('Sync interval', 'eventmesh'); ?>
                        </label>
                    </th>
                    <td>
                        <select id="eventmesh_sync_interval" name="eventmesh_sync_interval">
                            <?php foreach ($sync_intervals as $interval_key => $interval_label) : ?>
                                <option value="<?php echo esc_attr($interval_key); ?>" <?php selected($sync_interval, $interval_key); ?>>
                                    <?php echo esc_html($interval_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('How often background sync runs. Actual timing still depends on site traffic unless real server cron is configured.', 'eventmesh'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="eventmesh_artist_map">
                            <?php esc_html_e('Artist provider map', 'eventmesh'); ?>
                        </label>
                    </th>
                    <td>
                        <textarea
                            id="eventmesh_artist_map"
                            name="eventmesh_artist_map"
                            rows="12"
                            cols="80"
                            class="large-text code"
                        ><?php echo esc_textarea($artist_map_json); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('Define a JSON map of artists to provider URLs such as spotify, youtube, mixcloud, bandcamp, and soundcloud.', 'eventmesh'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Test connector', 'eventmesh'); ?>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="eventmesh_enable_test_connector" value="1" <?php checked($enable_test_connector || $test_connector_forced, true); ?> <?php disabled($test_connector_forced, true); ?> />
                            <?php esc_html_e('Enable the built-in test connector (sample event data, no network calls)', 'eventmesh'); ?>
                        </label>
                        <p class="description">
                            <?php if ($test_connector_forced) : ?>
                                <?php esc_html_e('Forced on by the EVENTMESH_ENABLE_TEST_CONNECTOR constant in wp-config.php.', 'eventmesh'); ?>
                            <?php else : ?>
                                <?php esc_html_e('For development and demos only. Adds a "Dummy (testing)" source with sample upcoming, sold-out, canceled, and past events. Leave off in production.', 'eventmesh'); ?>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Uninstalling', 'eventmesh'); ?>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="eventmesh_delete_data_on_uninstall" value="1" <?php checked($delete_data_on_uninstall, true); ?> />
                            <?php esc_html_e('Delete all EventMesh data when the plugin is deleted', 'eventmesh'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Off by default: deleting the plugin keeps every synced event, term, and setting so reinstalling picks up where you left off.', 'eventmesh'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php submit_button(__('Save Settings', 'eventmesh')); ?>
    </form>

    <hr />

    <h2><?php esc_html_e('Factory reset', 'eventmesh'); ?></h2>
    <p class="description">
        <?php esc_html_e('Removes every synced event and performer term, and resets all EventMesh settings to their defaults. The plugin itself stays installed and active. This cannot be undone.', 'eventmesh'); ?>
    </p>
    <form
        method="post"
        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
        onsubmit="return window.confirm('<?php echo esc_js(__('This will permanently delete all synced events and reset every EventMesh setting. Continue?', 'eventmesh')); ?>');"
    >
        <input type="hidden" name="action" value="eventmesh_factory_reset">
        <?php wp_nonce_field('eventmesh_factory_reset'); ?>
        <?php submit_button(__('Factory Reset', 'eventmesh'), 'delete'); ?>
    </form>
</div>
