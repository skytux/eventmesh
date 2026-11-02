<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Support\FactoryReset;
use EventMesh\Translation\LlmSettings;
use EventMesh\Translation\TranslationQueue;

final class SettingsPage
{
    public function __construct(
        private readonly View $view,
        private readonly Admin $admin,
        private readonly TranslationQueue $translationQueue
    ) {
    }

    public function render(): void
    {
        $targetLanguages = LlmSettings::targetLanguages();
        $offeredLanguages = array_values(array_unique(array_merge(LlmSettings::siteLanguages(), $targetLanguages)));
        $status = get_option(TranslationQueue::OPTION_STATUS, []);

        $this->view->render(
            'settings',
            [
                'background_sync_enabled' => '1' === (string) get_option('eventmesh_enable_background_sync', '1'),
                'sync_interval' => $this->admin->configuredSyncInterval(),
                'sync_intervals' => $this->admin->availableSyncIntervals(),
                'delete_data_on_uninstall' => '1' === (string) get_option('eventmesh_delete_data_on_uninstall', '0'),
                'embed_facade' => '1' === (string) get_option('eventmesh_embed_facade', '1'),
                'defer_embeds' => '1' === (string) get_option('eventmesh_defer_embeds', '0'),
                'translation_enabled' => LlmSettings::isEnabled(),
                'translation_ready' => LlmSettings::isReady(),
                'llm_url' => LlmSettings::url(),
                'llm_model' => LlmSettings::model(),
                'llm_has_key' => LlmSettings::hasKey(),
                'llm_key_unreadable' => LlmSettings::keyUnreadable(),
                'translation_languages' => $offeredLanguages,
                'translation_targets' => $targetLanguages,
                'translation_queue_length' => count($this->translationQueue->queue()),
                'translation_status' => is_array($status) ? $status : [],
                'remove_key_url' => $this->actionUrl('eventmesh_llm_remove_key'),
                'translate_now_url' => $this->actionUrl('eventmesh_translate_now'),
                'test_connection_url' => $this->actionUrl('eventmesh_llm_test'),
            ]
        );
    }

    private function actionUrl(string $action): string
    {
        return wp_nonce_url(
            add_query_arg(['action' => $action], admin_url('admin-post.php')),
            $action
        );
    }

    public function save(): void
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to save this setting.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_settings');

        $backgroundSyncEnabled = isset($_POST['eventmesh_enable_background_sync'])
            ? '1' === (string) $_POST['eventmesh_enable_background_sync']
            : false;

        $syncInterval = isset($_POST['eventmesh_sync_interval'])
            ? sanitize_key((string) $_POST['eventmesh_sync_interval'])
            : 'hourly';

        $deleteDataOnUninstall = isset($_POST['eventmesh_delete_data_on_uninstall'])
            ? '1' === (string) $_POST['eventmesh_delete_data_on_uninstall']
            : false;

        $embedFacade = isset($_POST['eventmesh_embed_facade'])
            ? '1' === (string) $_POST['eventmesh_embed_facade']
            : false;

        $deferEmbeds = isset($_POST['eventmesh_defer_embeds'])
            ? '1' === (string) $_POST['eventmesh_defer_embeds']
            : false;

        if (! array_key_exists($syncInterval, $this->admin->availableSyncIntervals())) {
            $syncInterval = 'hourly';
        }

        update_option('eventmesh_enable_background_sync', $backgroundSyncEnabled ? '1' : '0');
        update_option('eventmesh_sync_interval', $syncInterval);
        update_option('eventmesh_delete_data_on_uninstall', $deleteDataOnUninstall ? '1' : '0');
        update_option('eventmesh_embed_facade', $embedFacade ? '1' : '0');
        update_option('eventmesh_defer_embeds', $deferEmbeds ? '1' : '0');

        $this->saveTranslationSettings();

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'eventmesh-settings'],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * The automatic translation part of the settings form. An empty key
     * field keeps the saved key; the key is never sent back to the browser.
     *
     * The nonce is verified in save() before this runs.
     */
    // phpcs:disable WordPress.Security.NonceVerification.Missing
    private function saveTranslationSettings(): void
    {
        LlmSettings::setEnabled(
            isset($_POST['eventmesh_translation_enabled']) && '1' === (string) $_POST['eventmesh_translation_enabled']
        );

        $url = isset($_POST['eventmesh_llm_url']) ? (string) wp_unslash($_POST['eventmesh_llm_url']) : '';
        $model = isset($_POST['eventmesh_llm_model'])
            ? sanitize_text_field(wp_unslash((string) $_POST['eventmesh_llm_model']))
            : '';
        $key = isset($_POST['eventmesh_llm_key']) ? trim((string) wp_unslash($_POST['eventmesh_llm_key'])) : '';

        LlmSettings::save($url, $model, $key);

        $submitted = $_POST['eventmesh_translation_languages'] ?? null;
        $languages = is_array($submitted)
            ? array_map(
                static fn ($value): string => sanitize_key((string) $value),
                wp_unslash($submitted)
            )
            : [];

        LlmSettings::saveTargetLanguages($languages);

        // Events queued while translation was off or not set up start now.
        if (LlmSettings::isReady() && [] !== $this->translationQueue->queue()) {
            $this->translationQueue->schedule(0);
        }
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing

    /**
     * The "Remove key" link.
     */
    public function removeLlmKey(): void
    {
        $this->checkAction('eventmesh_llm_remove_key');

        LlmSettings::removeKey();

        $this->redirectWithNotice('success', __('The API key was removed.', 'eventmesh'));
    }

    /**
     * The "Translate now" link: queues every published event and works on
     * the queue in this request, within the same time budget as a cron run.
     */
    public function translateNow(): void
    {
        $this->checkAction('eventmesh_translate_now');

        if (! LlmSettings::isReady()) {
            $this->redirectWithNotice(
                'error',
                __('Automatic translation is off, or the API URL, model or key is missing.', 'eventmesh')
            );
        }

        $result = $this->translationQueue->translateNow();

        if ('' !== $result['error']) {
            $this->redirectWithNotice(
                'error',
                sprintf(
                    /* translators: 1: number of translations made, 2: short error kind */
                    __('Translated %1$d. Stopped by an error from the LLM server: %2$s. It is tried again later.', 'eventmesh'), // phpcs:ignore Generic.Files.LineLength.TooLong
                    $result['translated'],
                    $result['error']
                )
            );
        }

        $this->redirectWithNotice(
            'success',
            sprintf(
                /* translators: 1: translations made, 2: translations that failed the check, 3: events still queued */
                __('Translated %1$d, failed the check %2$d. Events still queued: %3$d (they continue in the background).', 'eventmesh'), // phpcs:ignore Generic.Files.LineLength.TooLong
                $result['translated'],
                $result['failed'],
                $result['left']
            )
        );
    }

    /**
     * The "Test connection" link: one tiny request with the saved settings.
     */
    public function testLlmConnection(): void
    {
        $this->checkAction('eventmesh_llm_test');

        $result = $this->translationQueue->testConnection();

        if ('ok' === $result) {
            $this->redirectWithNotice('success', __('Connection OK.', 'eventmesh'));
        }

        if ('not_configured' === $result) {
            $this->redirectWithNotice('error', __('Enter the API URL, model and key first.', 'eventmesh'));
        }

        $this->redirectWithNotice(
            'error',
            sprintf(
                /* translators: %s: short error kind, e.g. http_401 or network */
                __('Connection failed: %s', 'eventmesh'),
                $result
            )
        );
    }

    /**
     * Shown on every admin page when the saved key cannot be decrypted, which
     * happens when the site's security keys (salts) were changed.
     */
    public function renderKeyNotice(): void
    {
        if (! current_user_can(Admin::CAPABILITY) || ! LlmSettings::keyUnreadable()) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            esc_html__('EventMesh can no longer read the saved API key for automatic translation (the site\'s security keys have changed). Enter the key again.', 'eventmesh'),
            esc_url(add_query_arg(['page' => 'eventmesh-settings'], admin_url('admin.php'))),
            esc_html__('Settings', 'eventmesh')
        );
    }

    private function checkAction(string $action): void
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'eventmesh'));
        }

        check_admin_referer($action);
    }

    private function redirectWithNotice(string $type, string $message): void
    {
        set_transient('eventmesh_sync_notice', ['type' => $type, 'message' => $message], 60);

        wp_safe_redirect(add_query_arg(['page' => 'eventmesh-settings'], admin_url('admin.php')));
        exit;
    }

    /**
     * Wipes all synced events, options, and transients back to a fresh-install
     * state, without deactivating the plugin. Intended for starting over
     * during setup/testing.
     */
    public function factoryReset(): void
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_factory_reset');

        $result = FactoryReset::run();

        set_transient(
            'eventmesh_sync_notice',
            [
                'type' => 'success',
                'message' => sprintf(
                    /* translators: %d: number of deleted events */
                    __('Factory reset complete: removed %d event(s). All settings reset.', 'eventmesh'),
                    $result['deleted_events']
                ),
            ],
            60
        );

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'eventmesh-settings'],
                admin_url('admin.php')
            )
        );
        exit;
    }
}
