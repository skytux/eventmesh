<?php

/**
 * Settings admin view.
 *
 * @var bool $background_sync_enabled Whether background sync is enabled.
 * @var string $sync_interval Currently configured background sync interval key.
 * @var array<string, string> $sync_intervals Available interval keys mapped to labels.
 * @var bool $delete_data_on_uninstall Whether uninstalling the plugin should also delete its data.
 * @var bool $embed_facade Whether provider embeds show a click-to-play button instead of loading.
 * @var bool $defer_embeds Whether provider embeds load only when scrolled into view.
 * @var bool $translation_enabled Whether automatic translation is switched on.
 * @var bool $translation_ready Whether it is on and the URL, model and key are all set.
 * @var string $llm_url Saved API base URL.
 * @var string $llm_model Saved model name.
 * @var bool $llm_has_key Whether an API key is saved (it is never shown).
 * @var bool $llm_key_unreadable Whether the saved key can no longer be decrypted.
 * @var array<int, string> $translation_languages Language codes offered as targets.
 * @var array<int, string> $translation_targets Language codes currently chosen.
 * @var int $translation_queue_length Events waiting for translation.
 * @var array<string, mixed> $translation_status Summary of the last translation run.
 * @var string $remove_key_url Nonced link that removes the saved key.
 * @var string $translate_now_url Nonced link that runs the queue now.
 * @var string $test_connection_url Nonced link that tests the connection.
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
                        <?php esc_html_e('Provider embeds', 'eventmesh'); ?>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="eventmesh_embed_facade" value="1" <?php checked($embed_facade, true); ?> />
                            <?php esc_html_e('Show a play button and load the player only when clicked', 'eventmesh'); ?>
                        </label>
                        <p class="description">
                            <?php // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                            <?php esc_html_e('Recommended. A page listing many events downloads nothing from Spotify/SoundCloud/Mixcloud until a visitor actually asks to hear something, which is by far the biggest thing you can do for its page speed score. Turn this off to have the players appear ready to play on arrival.', 'eventmesh'); ?>
                        </p>
                        <label>
                            <input type="checkbox" name="eventmesh_defer_embeds" value="1" <?php checked($defer_embeds, true); ?> />
                            <?php esc_html_e('Load embedded players only when scrolled into view', 'eventmesh'); ?>
                        </label>
                        <p class="description">
                            <?php // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                            <?php esc_html_e('Only applies when the play button above is off. Players still load on their own, just not until the visitor scrolls to them. Players without JavaScript fall back to loading normally.', 'eventmesh'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2 id="eventmesh-translation"><?php esc_html_e('Automatic translation', 'eventmesh'); ?></h2>
        <p class="description">
            <?php // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
            <?php esc_html_e('Translates the title and text of each event with a language model on a server you choose (any service with an OpenAI-compatible chat completions API). Only the event title and text are sent. Translations are shown to visitors who view the site in another language.', 'eventmesh'); ?>
        </p>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Automatic translation', 'eventmesh'); ?>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="eventmesh_translation_enabled" value="1" <?php checked($translation_enabled, true); ?> />
                            <?php esc_html_e('Translate events automatically', 'eventmesh'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Runs only when the API URL, model and key below are all set.', 'eventmesh'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="eventmesh_llm_url"><?php esc_html_e('API URL', 'eventmesh'); ?></label>
                    </th>
                    <td>
                        <input type="url" class="regular-text" id="eventmesh_llm_url" name="eventmesh_llm_url" value="<?php echo esc_attr($llm_url); ?>" />
                        <p class="description">
                            <?php esc_html_e('The base URL, without /chat/completions at the end.', 'eventmesh'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="eventmesh_llm_model"><?php esc_html_e('Model', 'eventmesh'); ?></label>
                    </th>
                    <td>
                        <input type="text" class="regular-text" id="eventmesh_llm_model" name="eventmesh_llm_model" value="<?php echo esc_attr($llm_model); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="eventmesh_llm_key"><?php esc_html_e('API key', 'eventmesh'); ?></label>
                    </th>
                    <td>
                        <input type="password" class="regular-text" id="eventmesh_llm_key" name="eventmesh_llm_key" value="" autocomplete="new-password" />
                        <p class="description">
                            <?php if ($llm_key_unreadable) : ?>
                                <?php esc_html_e('The saved key can no longer be read (the site\'s security keys have changed). Enter it again.', 'eventmesh'); ?>
                            <?php elseif ($llm_has_key) : ?>
                                <?php esc_html_e('A key is saved. Leave empty to keep the saved key.', 'eventmesh'); ?>
                                <a href="<?php echo esc_url($remove_key_url); ?>"><?php esc_html_e('Remove key', 'eventmesh'); ?></a>
                            <?php else : ?>
                                <?php esc_html_e('No key saved.', 'eventmesh'); ?>
                            <?php endif; ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e('The key is stored encrypted and is never shown again.', 'eventmesh'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Translate into', 'eventmesh'); ?>
                    </th>
                    <td>
                        <?php foreach ($translation_languages as $translation_language) : ?>
                            <label style="margin-right:1em">
                                <input type="checkbox" name="eventmesh_translation_languages[]" value="<?php echo esc_attr($translation_language); ?>" <?php checked(in_array($translation_language, $translation_targets, true), true); ?> />
                                <?php echo esc_html(strtoupper($translation_language)); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php esc_html_e('An event is never translated into its own original language.', 'eventmesh'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Status', 'eventmesh'); ?>
                    </th>
                    <td>
                        <p>
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: %d: number of events */
                                    __('Events waiting for translation: %d', 'eventmesh'),
                                    $translation_queue_length
                                )
                            );
                            ?>
                        </p>
                        <?php if (! empty($translation_status['time'])) : ?>
                            <p>
                                <?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: 1: date and time, 2: translations made, 3: translations that failed the check */
                                        __('Last run: %1$s. Translated %2$d, failed the check %3$d.', 'eventmesh'),
                                        wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), (int) $translation_status['time']),
                                        (int) ($translation_status['translated'] ?? 0),
                                        (int) ($translation_status['failed'] ?? 0)
                                    )
                                );
                                ?>
                                <?php if (! empty($translation_status['error'])) : ?>
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %s: short error kind, e.g. http_401 or network */
                                            __('Last error: %s', 'eventmesh'),
                                            (string) $translation_status['error']
                                        )
                                    );
                                    ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <p>
                            <?php if ($translation_ready) : ?>
                                <a class="button" href="<?php echo esc_url($translate_now_url); ?>"><?php esc_html_e('Translate now', 'eventmesh'); ?></a>
                            <?php endif; ?>
                            <a class="button" href="<?php echo esc_url($test_connection_url); ?>"><?php esc_html_e('Test connection', 'eventmesh'); ?></a>
                        </p>
                        <p class="description">
                            <?php esc_html_e('Save the settings first; both buttons use the saved settings.', 'eventmesh'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <table class="form-table" role="presentation">
            <tbody>
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
                            <?php esc_html_e('Off by default: deleting the plugin keeps every synced event and setting so reinstalling picks up where you left off.', 'eventmesh'); ?>
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
        <?php esc_html_e('Removes every synced event and resets all EventMesh settings to their defaults. The plugin itself stays installed and active. This cannot be undone.', 'eventmesh'); ?>
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
