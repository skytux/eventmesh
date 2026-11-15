<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Content\EventPostType;
use EventMesh\Description\DescriptionFilters;
use EventMesh\Description\DescriptionStore;
use EventMesh\Translation\LlmSettings;
use EventMesh\Translation\Translator;

/**
 * The "Short description" box on the event edit screen: the description
 * newsletters, emails and channel posts show, one field per language
 * (Finnish first). The AI writes it once; what is typed here is kept.
 * Changing the Finnish text translates the languages nobody edited again;
 * emptying a field hands that language back to the AI.
 */
final class DescriptionMetaBox
{
    private const NONCE_FIELD = 'eventmesh_description_nonce';

    public function __construct(
        private readonly DescriptionFilters $filters
    ) {
    }

    public function boot(): void
    {
        add_action('add_meta_boxes', [$this, 'register']);
        add_action('save_post_' . EventPostType::NAME, [$this, 'save'], 25, 2);
    }

    public function register(): void
    {
        add_meta_box(
            'eventmesh_event_description',
            __('Short description', 'eventmesh'),
            [$this, 'render'],
            EventPostType::NAME,
            'normal',
            'default'
        );
    }

    public function render(\WP_Post $post): void
    {
        wp_nonce_field('eventmesh_save_description_' . $post->ID, self::NONCE_FIELD);

        echo '<p class="description">'
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            . esc_html__('Two or three sentences for newsletters, emails and channel posts. The AI writes them once in Finnish and translates them. What you type here is kept. Empty a field to have the AI write it again.', 'eventmesh')
            . '</p>';

        if (! LlmSettings::isReady()) {
            echo '<p class="description">'
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                . esc_html__('The AI is off or not set up (EventMesh → Settings). You can still write the texts here by hand.', 'eventmesh')
                . '</p>';
        }

        foreach (DescriptionStore::all($post->ID) as $lang => $state) {
            if ('' !== $state['failed']) {
                /* translators: %s: short error kind */
                $note = sprintf(__('The AI could not write it (%s).', 'eventmesh'), $state['failed']);
            } elseif ('' === $state['text']) {
                $note = __('Not written yet.', 'eventmesh');
            } elseif (DescriptionStore::STATUS_EDITED === $state['status']) {
                $note = __('Edited by hand.', 'eventmesh');
            } else {
                $note = __('Written by the AI.', 'eventmesh');
            }

            printf(
                '<p><label><strong>%1$s</strong> <span class="description">%2$s</span><br />'
                    . '<textarea class="widefat" rows="4" maxlength="%3$d" name="eventmesh_desc[%4$s]">'
                    . '%5$s</textarea></label></p>',
                esc_html(Translator::languageName($lang)),
                esc_html($note),
                (int) DescriptionStore::MAX_LENGTH,
                esc_attr($lang),
                esc_textarea($state['text'])
            );
        }

        echo '<p><label><input type="checkbox" name="eventmesh_desc_regenerate" value="1" /> '
            . esc_html__('Write again with the AI (keeps the translations edited by hand)', 'eventmesh')
            . '</label></p>';
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

        if ('' === $nonce || ! wp_verify_nonce($nonce, 'eventmesh_save_description_' . $postId)) {
            return;
        }

        $submitted = isset($_POST['eventmesh_desc']) && is_array($_POST['eventmesh_desc'])
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- DescriptionStore::clean() sanitizes each text.
            ? wp_unslash($_POST['eventmesh_desc'])
            : [];
        $texts = [];

        foreach ($submitted as $lang => $text) {
            $lang = LlmSettings::languageCode((string) $lang);

            if ('' !== $lang && is_string($text)) {
                $texts[$lang] = str_replace(["\r\n", "\r"], "\n", $text);
            }
        }

        $this->filters->save(false, $postId, $texts);

        if (! empty($_POST['eventmesh_desc_regenerate'])) {
            $this->filters->regenerate(false, $postId);
        }
    }
}
