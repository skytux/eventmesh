<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Content\EventPostType;
use EventMesh\Naming\EventNameStore;
use EventMesh\Translation\LlmSettings;
use EventMesh\Translation\TranslationQueue;
use EventMesh\Translation\TranslationStore;

/**
 * The "Translations" box on the event edit screen: per target language, the
 * translated title and content, editable. Saving changed text marks that
 * translation as edited, and the background translation never overwrites it
 * again; "Back to automatic" hands it back.
 */
final class TranslationMetaBox
{
    private const NONCE_FIELD = 'eventmesh_translations_nonce';

    public function __construct(
        private readonly TranslationQueue $queue
    ) {
    }

    public function boot(): void
    {
        add_action('add_meta_boxes', [$this, 'register']);
        add_action('save_post_' . EventPostType::NAME, [$this, 'save'], 20, 2);
        add_action('admin_post_eventmesh_translation_auto', [$this, 'backToAutomatic']);
    }

    public function register(): void
    {
        add_meta_box(
            'eventmesh_event_translations',
            __('Translations', 'eventmesh'),
            [$this, 'render'],
            EventPostType::NAME,
            'normal',
            'default'
        );
    }

    public function render(\WP_Post $post): void
    {
        wp_nonce_field('eventmesh_save_translations_' . $post->ID, self::NONCE_FIELD);

        $hash = TranslationStore::originalHash($post);
        $originalLang = TranslationStore::originalLanguage($post->ID);
        $languageFresh = '' !== $originalLang
            && $hash === (string) get_post_meta($post->ID, TranslationStore::META_ORIGINAL_LANG_HASH, true);

        if (! LlmSettings::isReady()) {
            echo '<p class="description">'
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                . esc_html__('Automatic translation is off or not set up (EventMesh → Settings). You can still write translations here by hand.', 'eventmesh')
                . '</p>';
        }

        echo '<p><strong>' . esc_html__('Original language:', 'eventmesh') . '</strong> '
            . ('' !== $originalLang ? esc_html($originalLang) : esc_html__('not detected yet', 'eventmesh'));

        if ('' !== $originalLang && ! $languageFresh) {
            echo ' <span class="description">'
                . esc_html__('(the text has changed; it will be checked again)', 'eventmesh')
                . '</span>';
        }

        echo '</p>';

        $this->renderFailures(
            TranslationStore::attemptsFor(
                TranslationStore::failure($post->ID, TranslationStore::META_ORIGINAL_LANG_FAIL),
                $hash
            ),
            (string) (TranslationStore::failure($post->ID, TranslationStore::META_ORIGINAL_LANG_FAIL)['kind'] ?? '')
        );

        $this->renderEventName($post);

        $languages = TranslationStore::targetsFor(LlmSettings::targetLanguages(), $originalLang);

        if ([] === $languages) {
            echo '<p>' . esc_html__('No target languages other than the original.', 'eventmesh') . '</p>';

            return;
        }

        foreach ($languages as $lang) {
            $this->renderLanguage($post, $lang, $hash);
        }
    }

    /**
     * The clean name and DJ names the language model wrote (EventNameQueue),
     * read-only.
     */
    private function renderEventName(\WP_Post $post): void
    {
        $stored = EventNameStore::read($post->ID);
        $notYet = __('not written yet', 'eventmesh');
        $currentHash = EventNameStore::sourceHash((string) $post->post_title, EventNameStore::finnishTitle($post));
        $fresh = $stored['hash'] === $currentHash;

        echo '<p><strong>' . esc_html__('Event name:', 'eventmesh') . '</strong> '
            . esc_html('' !== $stored['name'] ? $stored['name'] : $notYet);

        if ('' !== $stored['name'] && ! $fresh) {
            echo ' <span class="description">'
                . esc_html__('(the text has changed; it will be checked again)', 'eventmesh')
                . '</span>';
        }

        echo '<br /><strong>' . esc_html__('Finnish name:', 'eventmesh') . '</strong> '
            . esc_html('' !== $stored['name_fi'] ? $stored['name_fi'] : $notYet)
            . '<br /><strong>' . esc_html__('DJ names:', 'eventmesh') . '</strong> '
            . esc_html([] !== $stored['djs'] ? implode(', ', $stored['djs']) : '–')
            . '</p>';

        $failure = TranslationStore::failure($post->ID, EventNameStore::META_FAIL);
        $this->renderFailures(
            TranslationStore::attemptsFor($failure, $currentHash),
            (string) ($failure['kind'] ?? '')
        );
    }

    private function renderLanguage(\WP_Post $post, string $lang, string $hash): void
    {
        $translation = TranslationStore::read($post->ID, $lang);
        $failure = TranslationStore::failure($post->ID, TranslationStore::failureKey($lang));
        $exists = '' !== $translation['title'] || '' !== $translation['content'];
        $edited = TranslationStore::STATUS_EDITED === $translation['status'];

        echo '<hr /><h4 style="margin-bottom:.25em">' . esc_html(strtoupper($lang)) . '</h4>';

        if (! $exists) {
            $state = __('Not translated yet.', 'eventmesh');
        } elseif ($edited) {
            $state = __('Edited by hand. Automatic translation leaves it alone.', 'eventmesh');
        } else {
            $state = sprintf(
                /* translators: 1: model name, 2: date and time */
                __('Automatic translation by %1$s, %2$s.', 'eventmesh'),
                '' !== $translation['model'] ? $translation['model'] : '?',
                $translation['time'] > 0
                    ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $translation['time'])
                    : '?'
            );
        }

        echo '<p class="description" style="margin-top:0">' . esc_html($state);

        if ($exists && $translation['hash'] !== $hash) {
            echo ' ' . esc_html(
                $edited
                    // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    ? __('The original has changed since this was saved, so the original is shown. Update this translation and save, or go back to automatic.', 'eventmesh')
                    // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    : __('The original has changed since this was made, so the original is shown until it is translated again.', 'eventmesh')
            );
        }

        echo '</p>';

        $this->renderFailures(TranslationStore::attemptsFor($failure, $hash), (string) ($failure['kind'] ?? ''));

        printf(
            '<p><label><strong>%s</strong><br />'
                . '<input type="text" class="widefat" name="eventmesh_tr[%s][title]" value="%s" /></label></p>',
            esc_html__('Title', 'eventmesh'),
            esc_attr($lang),
            esc_attr($translation['title'])
        );

        printf(
            '<p><label><strong>%s</strong><br />'
                . '<textarea class="widefat" rows="8" name="eventmesh_tr[%s][content]">%s</textarea></label></p>',
            esc_html__('Content', 'eventmesh'),
            esc_attr($lang),
            esc_textarea($translation['content'])
        );

        if ($edited) {
            $url = wp_nonce_url(
                add_query_arg(
                    [
                        'action' => 'eventmesh_translation_auto',
                        'post' => $post->ID,
                        'lang' => $lang,
                    ],
                    admin_url('admin-post.php')
                ),
                'eventmesh_translation_auto_' . $post->ID . '_' . $lang
            );

            echo '<p><a href="' . esc_url($url) . '">' . esc_html__('Back to automatic', 'eventmesh') . '</a></p>';
        }
    }

    private function renderFailures(int $attempts, string $kind): void
    {
        if ($attempts <= 0) {
            return;
        }

        $text = $attempts >= TranslationStore::MAX_ATTEMPTS
            ? sprintf(
                /* translators: 1: number of failed attempts, 2: short error kind */
                __('Failed %1$d times (%2$s). Given up until the original changes.', 'eventmesh'),
                $attempts,
                $kind
            )
            : sprintf(
                /* translators: 1: number of failed attempts, 2: maximum attempts, 3: short error kind */
                __('Failed attempts: %1$d of %2$d (%3$s). Tried again later.', 'eventmesh'),
                $attempts,
                TranslationStore::MAX_ATTEMPTS,
                $kind
            );

        echo '<p class="description" style="color:#b32d2e">' . esc_html($text) . '</p>';
    }

    public function save(int $postId, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (EventPostType::NAME !== $post->post_type || ! current_user_can('edit_post', $postId)) {
            return;
        }

        $nonce = isset($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD]))
            : '';

        if ('' === $nonce || ! wp_verify_nonce($nonce, 'eventmesh_save_translations_' . $postId)) {
            return;
        }

        $submitted = isset($_POST['eventmesh_tr']) && is_array($_POST['eventmesh_tr'])
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.
            ? wp_unslash($_POST['eventmesh_tr'])
            : [];

        $hash = TranslationStore::originalHash($post);

        foreach ($submitted as $lang => $fields) {
            $lang = LlmSettings::languageCode((string) $lang);

            if ('' === $lang || ! is_array($fields)) {
                continue;
            }

            $this->saveLanguage($postId, $lang, $fields, $hash);
        }

        // The original may have changed in this same save.
        $this->queue->enqueue([$postId]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function saveLanguage(int $postId, string $lang, array $fields, string $hash): void
    {
        $stored = TranslationStore::read($postId, $lang);

        $title = sanitize_text_field((string) ($fields['title'] ?? ''));
        $content = self::normalizeNewlines((string) ($fields['content'] ?? ''));

        if (! current_user_can('unfiltered_html')) {
            $content = wp_kses_post($content);
        }

        if ($title === $stored['title'] && $content === self::normalizeNewlines($stored['content'])) {
            return;
        }

        TranslationStore::write(
            $postId,
            $lang,
            [
                'title' => $title,
                'content' => $content,
                'excerpt' => '' !== $content ? wp_trim_words($content, 55, '') : '',
                // Saved against the original as it is now: shown until the
                // original changes again.
                'hash' => $hash,
                'status' => TranslationStore::STATUS_EDITED,
                'model' => $stored['model'],
                'time' => time(),
            ]
        );
    }

    public function backToAutomatic(): void
    {
        $postId = isset($_GET['post']) ? absint(wp_unslash($_GET['post'])) : 0;
        $lang = isset($_GET['lang']) ? LlmSettings::languageCode(sanitize_key(wp_unslash((string) $_GET['lang']))) : '';

        check_admin_referer('eventmesh_translation_auto_' . $postId . '_' . $lang);

        if (0 === $postId || '' === $lang || ! current_user_can('edit_post', $postId)) {
            wp_die(esc_html__('You do not have permission to do this.', 'eventmesh'));
        }

        TranslationStore::resetToAutomatic($postId, $lang);
        $this->queue->enqueue([$postId]);

        wp_safe_redirect((string) get_edit_post_link($postId, 'url'));
        exit;
    }

    private static function normalizeNewlines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
