<?php
/**
 * Settings admin view.
 *
 * @var string $holvi_source_urls Holvi source URLs configured for syncing.
 * @var string $artist_map_json   Artist/provider mapping JSON.
 * @var array<string, bool> $source_settings Source enablement settings.
 * @var bool $background_sync_enabled Whether background sync is enabled.
 * @var array<int, array{id: string, url: string, enabled: bool}> $holvi_sources Holvi source rows.
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
                        <?php esc_html_e('Holvi sources', 'eventmesh'); ?>
                    </th>
                    <td>
                        <div id="eventmesh-holvi-sources">
                            <?php foreach ($holvi_sources as $index => $source) : ?>
                                <div class="eventmesh-holvi-source-row" style="margin-bottom: 8px;">
                                    <label>
                                        <input type="checkbox" name="eventmesh_holvi_sources[<?php echo esc_attr((string) $index); ?>][enabled]" value="1" <?php checked($source['enabled'], true); ?> />
                                        <?php esc_html_e('Enabled', 'eventmesh'); ?>
                                    </label>
                                    <input
                                        type="text"
                                        name="eventmesh_holvi_sources[<?php echo esc_attr((string) $index); ?>][url]"
                                        value="<?php echo esc_attr($source['url']); ?>"
                                        class="regular-text"
                                        placeholder="https://example.com"
                                        style="margin: 0 8px;"
                                    />
                                    <input type="hidden" name="eventmesh_holvi_sources[<?php echo esc_attr((string) $index); ?>][id]" value="<?php echo esc_attr($source['id']); ?>" />
                                </div>
                            <?php endforeach; ?>
                            <?php if ([] === $holvi_sources) : ?>
                                <div class="eventmesh-holvi-source-row" style="margin-bottom: 8px;">
                                    <label>
                                        <input type="checkbox" name="eventmesh_holvi_sources[0][enabled]" value="1" checked="checked" />
                                        <?php esc_html_e('Enabled', 'eventmesh'); ?>
                                    </label>
                                    <input type="text" name="eventmesh_holvi_sources[0][url]" value="" class="regular-text" placeholder="https://example.com" style="margin: 0 8px;" />
                                </div>
                            <?php endif; ?>
                        </div>
                        <p class="description">
                            <?php esc_html_e('Add one or more Holvi event URLs. You can enable or disable each source individually.', 'eventmesh'); ?>
                        </p>
                    </td>
                </tr>
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
            </tbody>
        </table>

        <?php submit_button(__('Save Settings', 'eventmesh')); ?>
    </form>
</div>
