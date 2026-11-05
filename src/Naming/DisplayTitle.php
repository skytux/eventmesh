<?php

declare(strict_types=1);

namespace EventMesh\Naming;

use EventMesh\Connectors\Holvi\HolviHtmlParser;
use EventMesh\Content\EventPostType;
use EventMesh\Translation\LlmSettings;
use EventMesh\Translation\TranslationDisplay;
use EventMesh\Translation\TranslationStore;

/**
 * An event's title in one language, without the date in it. Behind the public
 * function eventmesh_display_title() (src/functions.php).
 *
 * The title is the stored translation for that language when there is a
 * current one (the same rule as TranslationDisplay: made from the event's
 * current original, or edited by a person), else the original title. The
 * date is removed the same way the event list and the event page remove it
 * (HolviHtmlParser::stripDateForDisplay()). Plain text, not escaped.
 */
final class DisplayTitle
{
    /**
     * @param int|\WP_Post $post The event or its id.
     * @param string       $lang Two-letter language code ('fi', 'fi_FI' also
     *                           works); '' = the language of the current page.
     *
     * @return string '' when the post is not an event.
     */
    public static function of(int|\WP_Post $post, string $lang = ''): string
    {
        $post = $post instanceof \WP_Post ? $post : get_post($post);

        if (! $post instanceof \WP_Post || EventPostType::NAME !== $post->post_type) {
            return '';
        }

        $title = (string) $post->post_title;
        $originalLang = TranslationStore::originalLanguage((int) $post->ID);

        if ('' !== $originalLang) {
            $lang = LlmSettings::languageCode('' !== $lang ? $lang : TranslationDisplay::currentLanguage());

            if ('' !== $lang && $lang !== $originalLang) {
                $translation = TranslationStore::read((int) $post->ID, $lang);

                $currentHash = TranslationStore::originalHash($post);

                if (
                    '' !== trim($translation['title'])
                    && TranslationDisplay::choose($lang, $originalLang, $translation, $currentHash)
                ) {
                    $title = $translation['title'];
                }
            }
        }

        return (new HolviHtmlParser())->stripDateForDisplay(trim($title));
    }
}
