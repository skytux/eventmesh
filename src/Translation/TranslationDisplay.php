<?php

declare(strict_types=1);

namespace EventMesh\Translation;

use EventMesh\Content\EventPostType;

/**
 * Shows an event's translation on the front end when the page is in another
 * language than the event's original.
 *
 * The page language is Polylang's current language when Polylang is active,
 * else the first two letters of the request's locale. A translation is used
 * only when it exists for that language and was made from the event's
 * current original text (or was saved by a person against it); otherwise the
 * original shows. Translations that came from the model carry a small
 * "Automatically translated" line under the content; edited ones and the
 * original never do.
 *
 * Never active in wp-admin, in cron, or in a REST request in the edit
 * context, so editors always see and edit the original.
 *
 * @phpstan-import-type Translation from TranslationStore
 */
final class TranslationDisplay
{
    /**
     * @var array<int, Translation|null>
     */
    private array $cache = [];

    public function boot(): void
    {
        // Early, so the usual formatting (texturize, wpautop, blocks) runs on
        // the translated text the same way it runs on the original.
        add_filter('the_title', [$this, 'filterTitle'], 1, 2);
        add_filter('the_content', [$this, 'filterContent'], 1);
        // After WordPress has built an excerpt from the content.
        add_filter('get_the_excerpt', [$this, 'filterExcerpt'], 20, 2);
    }

    /**
     * @param mixed $title
     * @param mixed $postId
     *
     * @return mixed
     */
    public function filterTitle($title, $postId = 0)
    {
        if (! is_string($title) || ! is_numeric($postId) || (int) $postId <= 0) {
            return $title;
        }

        $translation = $this->forEvent(get_post((int) $postId));

        return null !== $translation && '' !== $translation['title'] ? $translation['title'] : $title;
    }

    /**
     * @param mixed $content
     *
     * @return mixed
     */
    public function filterContent($content)
    {
        if (! is_string($content)) {
            return $content;
        }

        $post = get_post();

        if (! $post instanceof \WP_Post) {
            return $content;
        }

        // Only the event's own content: the_content also runs on other text
        // while an event is the current post.
        $original = (string) $post->post_content;

        if ($content !== $original && $content !== str_replace(']]>', ']]&gt;', $original)) {
            return $content;
        }

        $translation = $this->forEvent($post);

        if (null === $translation || '' === $translation['content']) {
            return $content;
        }

        $translated = str_replace(']]>', ']]&gt;', $translation['content']);

        if (TranslationStore::STATUS_AUTO === $translation['status']) {
            $translated .= "\n\n" . self::label();
        }

        return $translated;
    }

    /**
     * @param mixed $excerpt
     * @param mixed $post
     *
     * @return mixed
     */
    public function filterExcerpt($excerpt, $post = null)
    {
        if (! is_string($excerpt)) {
            return $excerpt;
        }

        $translation = $this->forEvent(get_post($post));

        return null !== $translation && '' !== $translation['excerpt'] ? $translation['excerpt'] : $excerpt;
    }

    /**
     * The translation to show for this event on the current page, or null to
     * show the original.
     *
     * @param mixed $post
     *
     * @return Translation|null
     */
    public function forEvent($post): ?array
    {
        if (! $post instanceof \WP_Post || EventPostType::NAME !== $post->post_type) {
            return null;
        }

        if (array_key_exists($post->ID, $this->cache)) {
            return $this->cache[$post->ID];
        }

        $translation = null;

        if ($this->isFrontEnd()) {
            $pageLang = self::currentLanguage();
            $originalLang = TranslationStore::originalLanguage($post->ID);

            if ('' !== $pageLang && '' !== $originalLang && $pageLang !== $originalLang) {
                $candidate = TranslationStore::read($post->ID, $pageLang);

                if (self::choose($pageLang, $originalLang, $candidate, TranslationStore::originalHash($post))) {
                    $translation = $candidate;
                }
            }
        }

        $this->cache[$post->ID] = $translation;

        return $translation;
    }

    /**
     * Whether a translation is shown instead of the original.
     *
     * @param array{title: string, content: string, hash: string} $translation
     */
    public static function choose(string $pageLang, string $originalLang, array $translation, string $currentHash): bool
    {
        if ('' === $pageLang || '' === $originalLang || $pageLang === $originalLang) {
            return false;
        }

        if ('' === $translation['hash'] || $translation['hash'] !== $currentHash) {
            return false;
        }

        return '' !== $translation['title'] || '' !== $translation['content'];
    }

    /**
     * The language of the page being shown, as a two-letter code.
     */
    public static function currentLanguage(): string
    {
        if (function_exists('pll_current_language')) {
            $slug = pll_current_language('slug');

            if (is_string($slug) && '' !== $slug) {
                return LlmSettings::languageCode($slug);
            }
        }

        return LlmSettings::languageCode(determine_locale());
    }

    public static function label(): string
    {
        return '<p class="eventmesh-auto-translated">' . esc_html__('Automatically translated', 'eventmesh') . '</p>';
    }

    private function isFrontEnd(): bool
    {
        if (is_admin() || wp_doing_cron()) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of the REST context, no action is taken on it.
        $context = isset($_GET['context']) ? sanitize_key(wp_unslash((string) $_GET['context'])) : '';

        return ! (defined('REST_REQUEST') && REST_REQUEST && 'edit' === $context);
    }
}
