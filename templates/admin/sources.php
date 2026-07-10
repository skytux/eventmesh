<?php
/**
 * Sources admin view.
 *
 * @var array<int, array{id: string, label: string}> $source_rows Registered connector rows.
 * @var array<int, array{id: string, url: string, enabled: bool}> $holvi_sources Holvi source rows.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$holvi_sources = isset($holvi_sources) && is_array($holvi_sources) ? $holvi_sources : [];
$next_index = max(1, count($holvi_sources) > 0 ? max(array_keys($holvi_sources)) + 1 : 1);
?>

<div class="wrap">
    <h1><?php esc_html_e('Sources', 'eventmesh'); ?></h1>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eventmesh_save_sources" />
        <?php wp_nonce_field('eventmesh_save_sources'); ?>

        <div class="card" style="max-width: 960px; margin-top: 20px;">
            <h2><?php esc_html_e('Holvi source URLs', 'eventmesh'); ?></h2>
            <p class="description">
                <?php esc_html_e('Add one or more Holvi event URLs. Each row can be enabled or disabled independently.', 'eventmesh'); ?>
            </p>

            <div id="eventmesh-holvi-sources">
                <?php foreach ($holvi_sources as $index => $source) : ?>
                    <div class="eventmesh-holvi-source-row" style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                        <label>
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

            <p>
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Save sources', 'eventmesh'); ?>
                </button>
            </p>
        </div>
    </form>

    <?php if ([] === $source_rows) : ?>
        <div class="notice notice-info inline" style="margin-top: 20px;">
            <p>
                <?php esc_html_e('No connectors are installed yet.', 'eventmesh'); ?>
            </p>
        </div>
    <?php endif; ?>

    <table class="widefat striped" style="margin-top: 20px;">
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
        row.innerHTML = '<label><input type="checkbox" name="eventmesh_holvi_sources[' + nextIndex + '][enabled]" value="1" checked="checked" /> <?php echo esc_js(__('Enabled', 'eventmesh')); ?></label><input type="text" name="eventmesh_holvi_sources[' + nextIndex + '][url]" value="" class="regular-text" placeholder="https://example.com" /><input type="hidden" name="eventmesh_holvi_sources[' + nextIndex + '][id]" value="" /><button type="button" class="button eventmesh-remove-holvi-source"><?php echo esc_js(__('Remove', 'eventmesh')); ?></button>';
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
