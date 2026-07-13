<?php
/**
 * Dashboard admin view.
 *
 * @var array{
 *     status: string,
 *     status_message: string,
 *     last_sync_text: string,
 *     last_error: string,
 *     auto_sync_enabled: bool,
 *     next_sync_text: string,
 *     event_count: int
 * } $panel Display-ready status-panel view model.
 * @var array<int, array{time: string, level: string, message: string}> $logs Recent log entries, most recent first.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$settings_url = add_query_arg(['page' => 'eventmesh-settings'], admin_url('admin.php'));
?>

<div class="wrap">
    <h1><?php esc_html_e('Dashboard', 'eventmesh'); ?></h1>

    <h2 class="screen-reader-text"><?php esc_html_e('Sync status', 'eventmesh'); ?></h2>

    <table class="widefat striped" style="max-width: 720px; margin-top: 12px;">
        <tbody>
            <tr>
                <th scope="row"><?php esc_html_e('Status', 'eventmesh'); ?></th>
                <td>
                    <strong id="eventmesh-status"><?php echo esc_html($panel['status']); ?></strong>
                    <span id="eventmesh-status-message"><?php echo '' === $panel['status_message'] ? '' : esc_html(' — ' . $panel['status_message']); ?></span>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Last sync', 'eventmesh'); ?></th>
                <td id="eventmesh-last-sync"><?php echo esc_html($panel['last_sync_text']); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Last error', 'eventmesh'); ?></th>
                <td id="eventmesh-last-error">
                    <?php
                    echo '' === $panel['last_error']
                        ? esc_html__('None', 'eventmesh')
                        : '<span style="color:#b32d2e;">' . esc_html($panel['last_error']) . '</span>';
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Automatic sync', 'eventmesh'); ?></th>
                <td>
                    <span id="eventmesh-auto-sync"><?php echo $panel['auto_sync_enabled'] ? esc_html__('Enabled', 'eventmesh') : esc_html__('Disabled', 'eventmesh'); ?></span>
                    &nbsp;<a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('(change in Settings)', 'eventmesh'); ?></a>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Next scheduled sync', 'eventmesh'); ?></th>
                <td id="eventmesh-next-sync"><?php echo esc_html($panel['next_sync_text']); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Published events', 'eventmesh'); ?></th>
                <td id="eventmesh-event-count"><?php echo esc_html((string) $panel['event_count']); ?></td>
            </tr>
        </tbody>
    </table>

    <form
        id="eventmesh-sync-form"
        method="post"
        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
        style="margin-top: 16px;"
    >
        <input type="hidden" name="action" value="eventmesh_sync">
        <?php wp_nonce_field('eventmesh_sync'); ?>
        <?php submit_button(__('Sync now', 'eventmesh'), 'primary', 'submit', false, ['id' => 'eventmesh-sync-now']); ?>
        <span id="eventmesh-sync-spinner" class="spinner" style="float: none; margin-top: 0;"></span>
    </form>

    <h2 style="margin-top: 24px;"><?php esc_html_e('Recent activity', 'eventmesh'); ?></h2>
    <ul id="eventmesh-log" style="margin: 8px 0 0; padding: 0; list-style: none;">
        <?php if ([] === $logs) : ?>
            <li id="eventmesh-log-empty"><?php esc_html_e('No recent log entries yet.', 'eventmesh'); ?></li>
        <?php else : ?>
            <?php foreach ($logs as $log_entry) : ?>
                <li>
                    <code><?php echo esc_html($log_entry['time']); ?></code>
                    <strong>[<?php echo esc_html($log_entry['level']); ?>]</strong>
                    <?php echo esc_html($log_entry['message']); ?>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>
</div>

<script>
(function () {
    const form = document.getElementById('eventmesh-sync-form');
    const button = document.getElementById('eventmesh-sync-now');
    const spinner = document.getElementById('eventmesh-sync-spinner');
    const logList = document.getElementById('eventmesh-log');

    if (!form || !button || typeof window.ajaxurl === 'undefined') {
        return; // No JS enhancement: the form still posts and reloads normally.
    }

    const nonceField = form.querySelector('input[name="_wpnonce"]');
    const nonce = nonceField ? nonceField.value : '';

    function setText(id, text) {
        const el = document.getElementById(id);
        if (el) {
            el.textContent = text;
        }
    }

    function renderLogs(logs) {
        if (!logList) {
            return;
        }

        logList.innerHTML = '';

        if (!logs || logs.length === 0) {
            const empty = document.createElement('li');
            empty.textContent = <?php echo wp_json_encode(__('No recent log entries yet.', 'eventmesh')); ?>;
            logList.appendChild(empty);
            return;
        }

        logs.forEach(function (entry) {
            const li = document.createElement('li');
            const time = document.createElement('code');
            time.textContent = entry.time;
            const level = document.createElement('strong');
            level.textContent = ' [' + entry.level + '] ';
            li.appendChild(time);
            li.appendChild(level);
            li.appendChild(document.createTextNode(entry.message));
            logList.appendChild(li);
        });
    }

    function applyPanel(panel) {
        if (!panel) {
            return;
        }

        setText('eventmesh-status', panel.status);
        setText('eventmesh-status-message', panel.status_message ? ' — ' + panel.status_message : '');
        setText('eventmesh-last-sync', panel.last_sync_text);
        setText('eventmesh-next-sync', panel.next_sync_text);
        setText('eventmesh-event-count', String(panel.event_count));
        setText('eventmesh-auto-sync', panel.auto_sync_enabled
            ? <?php echo wp_json_encode(__('Enabled', 'eventmesh')); ?>
            : <?php echo wp_json_encode(__('Disabled', 'eventmesh')); ?>);

        const errorCell = document.getElementById('eventmesh-last-error');
        if (errorCell) {
            if (panel.last_error) {
                errorCell.innerHTML = '';
                const span = document.createElement('span');
                span.style.color = '#b32d2e';
                span.textContent = panel.last_error;
                errorCell.appendChild(span);
            } else {
                errorCell.textContent = <?php echo wp_json_encode(__('None', 'eventmesh')); ?>;
            }
        }
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        button.disabled = true;
        if (spinner) {
            spinner.classList.add('is-active');
        }

        const body = new URLSearchParams();
        body.append('action', 'eventmesh_sync');
        body.append('_ajax_nonce', nonce);

        fetch(window.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                const data = result && result.data ? result.data : {};
                applyPanel(data.panel);
                renderLogs(data.logs);
            })
            .catch(function () {
                setText('eventmesh-status', <?php echo wp_json_encode(__('Error', 'eventmesh')); ?>);
                setText('eventmesh-status-message', ' — ' + <?php echo wp_json_encode(__('The sync request failed. Reload and try again.', 'eventmesh')); ?>);
            })
            .finally(function () {
                button.disabled = false;
                if (spinner) {
                    spinner.classList.remove('is-active');
                }
            });
    });
})();
</script>
