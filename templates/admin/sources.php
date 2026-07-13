<?php
/**
 * Sources admin view.
 *
 * @var array<int, array{id: string, label: string, enabled: bool}> $connector_rows Registered connectors with their enabled state.
 * @var array<int, array{id: string, url: string, enabled: bool}> $holvi_sources Holvi source rows.
 * @var array<int, string> $dummy_preview Sample events the dummy connector would generate, one per line.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$holvi_sources = isset($holvi_sources) && is_array($holvi_sources) ? $holvi_sources : [];
$connector_rows = isset($connector_rows) && is_array($connector_rows) ? $connector_rows : [];
$dummy_preview = isset($dummy_preview) && is_array($dummy_preview) ? $dummy_preview : [];
$next_index = max(1, count($holvi_sources) > 0 ? max(array_keys($holvi_sources)) + 1 : 1);
?>

<div class="wrap">
    <h1><?php esc_html_e('Sources', 'eventmesh'); ?></h1>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eventmesh_save_sources" />
        <?php wp_nonce_field('eventmesh_save_sources'); ?>

        <h2><?php esc_html_e('Connectors', 'eventmesh'); ?></h2>
        <p class="description">
            <?php esc_html_e('Every installed connector is listed here. Tick a connector to sync its events; unticking it archives its events (moves them to Draft) on the next sync.', 'eventmesh'); ?>
        </p>

        <table class="widefat striped" style="max-width: 960px; margin-top: 12px;">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Connector', 'eventmesh'); ?></th>
                    <th scope="col"><?php esc_html_e('ID', 'eventmesh'); ?></th>
                    <th scope="col"><?php esc_html_e('Enabled', 'eventmesh'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ([] === $connector_rows) : ?>
                    <tr>
                        <td colspan="3">
                            <?php esc_html_e('No connectors are installed yet.', 'eventmesh'); ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($connector_rows as $connector_row) : ?>
                        <tr>
                            <td><?php echo esc_html($connector_row['label']); ?></td>
                            <td><code><?php echo esc_html($connector_row['id']); ?></code></td>
                            <td>
                                <label>
                                    <?php // The hidden 0 submits when the checkbox is unchecked, so unticking actually disables the source. ?>
                                    <input type="hidden" name="eventmesh_source_enabled[<?php echo esc_attr($connector_row['id']); ?>]" value="0" />
                                    <input type="checkbox" name="eventmesh_source_enabled[<?php echo esc_attr($connector_row['id']); ?>]" value="1" <?php checked($connector_row['enabled'], true); ?> />
                                    <?php esc_html_e('Enabled', 'eventmesh'); ?>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="card" style="max-width: 960px; margin-top: 20px;">
            <h2><?php esc_html_e('Holvi source URLs', 'eventmesh'); ?></h2>
            <p class="description">
                <?php esc_html_e('Add one or more Holvi event URLs. Each row can be enabled or disabled independently.', 'eventmesh'); ?>
            </p>

            <div id="eventmesh-holvi-sources">
                <?php foreach ($holvi_sources as $index => $source) : ?>
                    <div class="eventmesh-holvi-source-row" style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                        <label>
                            <input type="hidden" name="eventmesh_holvi_sources[<?php echo esc_attr((string) $index); ?>][enabled]" value="0" />
                            <input type="checkbox" name="eventmesh_holvi_sources[<?php echo esc_attr((string) $index); ?>][enabled]" value="1" <?php checked($source['enabled'], true); ?> />
                            <?php esc_html_e('Enabled', 'eventmesh'); ?>
                        </label>
                        <input type="text" name="eventmesh_holvi_sources[<?php echo esc_attr((string) $index); ?>][url]" value="<?php echo esc_attr($source['url']); ?>" class="regular-text" placeholder="https://example.com" />
                        <input type="hidden" name="eventmesh_holvi_sources[<?php echo esc_attr((string) $index); ?>][id]" value="<?php echo esc_attr($source['id']); ?>" />
                        <button type="button" class="button eventmesh-remove-holvi-source">
                            <?php esc_html_e('Remove', 'eventmesh'); ?>
                        </button>
                    </div>
                <?php endforeach; ?>

                <?php if ([] === $holvi_sources) : ?>
                    <div class="eventmesh-holvi-source-row" style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                        <label>
                            <input type="hidden" name="eventmesh_holvi_sources[0][enabled]" value="0" />
                            <input type="checkbox" name="eventmesh_holvi_sources[0][enabled]" value="1" checked="checked" />
                            <?php esc_html_e('Enabled', 'eventmesh'); ?>
                        </label>
                        <input type="text" name="eventmesh_holvi_sources[0][url]" value="" class="regular-text" placeholder="https://example.com" />
                        <input type="hidden" name="eventmesh_holvi_sources[0][id]" value="" />
                        <button type="button" class="button eventmesh-remove-holvi-source">
                            <?php esc_html_e('Remove', 'eventmesh'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <p>
                <button type="button" class="button" id="eventmesh-add-holvi-source">
                    <?php esc_html_e('Add another source', 'eventmesh'); ?>
                </button>
            </p>
        </div>

        <?php if ([] !== $dummy_preview) : ?>
            <div class="card" style="max-width: 960px; margin-top: 20px;">
                <h2><?php esc_html_e('Dummy (testing) — sample events', 'eventmesh'); ?></h2>
                <p class="description">
                    <?php esc_html_e('The dummy connector has no URLs. When enabled above, it generates these sample events on each sync — no network calls:', 'eventmesh'); ?>
                </p>
                <ul style="margin-left: 1.5em; list-style: disc;">
                    <?php foreach ($dummy_preview as $preview_line) : ?>
                        <li><?php echo esc_html($preview_line); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <p>
            <button type="submit" class="button button-primary">
                <?php esc_html_e('Save sources', 'eventmesh'); ?>
            </button>
        </p>
    </form>
</div>

<script>
(function () {
    const container = document.getElementById('eventmesh-holvi-sources');
    const addButton = document.getElementById('eventmesh-add-holvi-source');

    if (!container || !addButton) {
        return;
    }

    let nextIndex = <?php echo (int) $next_index; ?>;

    addButton.addEventListener('click', function () {
        const row = document.createElement('div');
        row.className = 'eventmesh-holvi-source-row';
        row.style.display = 'flex';
        row.style.alignItems = 'center';
        row.style.gap = '8px';
        row.style.marginBottom = '8px';
        row.innerHTML = '<label><input type="hidden" name="eventmesh_holvi_sources[' + nextIndex + '][enabled]" value="0" /><input type="checkbox" name="eventmesh_holvi_sources[' + nextIndex + '][enabled]" value="1" checked="checked" /> <?php echo esc_js(__('Enabled', 'eventmesh')); ?></label><input type="text" name="eventmesh_holvi_sources[' + nextIndex + '][url]" value="" class="regular-text" placeholder="https://example.com" /><input type="hidden" name="eventmesh_holvi_sources[' + nextIndex + '][id]" value="" /><button type="button" class="button eventmesh-remove-holvi-source"><?php echo esc_js(__('Remove', 'eventmesh')); ?></button>';
        container.appendChild(row);
        nextIndex += 1;
    });

    container.addEventListener('click', function (event) {
        const target = event.target;

        if (!(target instanceof HTMLElement) || !target.classList.contains('eventmesh-remove-holvi-source')) {
            return;
        }

        const row = target.closest('.eventmesh-holvi-source-row');

        if (row) {
            row.remove();
        }
    });
})();
</script>
