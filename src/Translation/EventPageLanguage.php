<?php

declare(strict_types=1);

namespace EventMesh\Translation;

use EventMesh\Content\EventPostType;

/**
 * On a site translated with Polylang, the event pages are in the language of
 * the website page the visitor came from.
 *
 * Events are not translated by Polylang, so their addresses (/events/...)
 * have no language in them and Polylang would show every event page in the
 * default language: English menus, English texts, an English copy of a
 * Finnish event. The rule here, the same as EventCrew's crew page:
 *
 * 1. A Polylang language in the address (/fi/...) wins; nothing changes.
 * 2. A link to an event from a page with a language carries that language as
 *    a marker (?em_lang=fi, see markLink()). The event page is in that
 *    language and keeps it in a cookie that lasts until the browser is
 *    closed.
 * 3. Without a marker, the language kept in that cookie.
 * 4. Without either, the site language the browser ranks first
 *    (Accept-Language).
 * 5. Otherwise Polylang's own choice (the default language).
 *
 * The language is given to Polylang as its current language
 * (pll_get_current_language), so the whole page follows: the menus and the
 * EN | FI switch, WordPress's and EventMesh's texts, and the event's own
 * translation (TranslationDisplay). On an event page the switch links go to
 * the same event with the other language's marker (pll_pre_translation_url).
 *
 * Without Polylang nothing here does anything.
 */
final class EventPageLanguage
{
    /** The query argument a link to an event carries: a language slug. */
    public const MARKER = 'em_lang';

    /** The session cookie that keeps the marked language. */
    public const COOKIE = 'eventmesh_lang';

    /** Where the language of this event page came from, '' when not decided here. */
    private string $source = '';

    /**
     * Polylang picks the language on plugins_loaded at priority 1, before
     * EventMesh's kernel boots, so this is booted when the plugin file loads.
     */
    public function boot(): void
    {
        add_filter('pll_get_current_language', [$this, 'filterPolylangLanguage'], 20);
        add_filter('pll_pre_translation_url', [$this, 'filterTranslationUrl'], 10, 3);
        // After every canonical redirect (Polylang's at 4, WordPress's at 10),
        // so they compare addresses without a marker.
        add_action('template_redirect', [$this, 'onTemplateRedirect'], 100);
    }

    /**
     * The pll_get_current_language filter: on an event address without a
     * language in it, the language from the rule above.
     *
     * @param mixed $language Polylang's choice (PLL_Language or false).
     *
     * @return mixed
     */
    public function filterPolylangLanguage($language)
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return $language;
        }

        if (! self::isEventPath(self::requestPath(), self::homePath())) {
            return $language;
        }

        if ('' !== self::languageInAddress()) {
            return $language;
        }

        $marker = self::marker();
        $session = isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE])
            ? sanitize_key(wp_unslash($_COOKIE[self::COOKIE]))
            : '';
        $browser = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && is_string($_SERVER['HTTP_ACCEPT_LANGUAGE'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE']))
            : '';

        $choice = self::choose($marker, $session, $browser, self::siteLanguages());
        $this->source = $choice['source'];

        if ('' === $choice['language'] || ! function_exists('PLL')) {
            return $language;
        }

        $polylang = PLL();
        $chosen = is_object($polylang) && isset($polylang->model) && is_object($polylang->model)
            ? $polylang->model->get_language($choice['language'])
            : false;

        return is_object($chosen) ? $chosen : $language;
    }

    /**
     * The template_redirect listener. On every page with a language, event
     * links get their marker from here on. On an event page whose language
     * was chosen here, a marker is kept for the session, and page caches are
     * told not to store the page: its address alone does not say which
     * language it is in.
     */
    public function onTemplateRedirect(): void
    {
        if (function_exists('pll_current_language')) {
            add_filter('post_type_link', [$this, 'markLink'], 10, 2);
        }

        if ('' === $this->source) {
            return;
        }

        if ('marker' === $this->source) {
            $this->remember();
        }

        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        nocache_headers();
    }

    /**
     * The post_type_link filter: an event's address with the current page's
     * language as its marker. Only on the front end, and never twice.
     *
     * @param mixed $url
     * @param mixed $post
     *
     * @return mixed
     */
    public function markLink($url, $post)
    {
        if (! is_string($url) || ! $post instanceof \WP_Post || EventPostType::NAME !== $post->post_type) {
            return $url;
        }

        if (is_admin() || is_feed() || str_contains($url, self::MARKER . '=')) {
            return $url;
        }

        $language = function_exists('pll_current_language') ? pll_current_language('slug') : '';

        if (! is_string($language) || '' === $language) {
            return $url;
        }

        return add_query_arg(self::MARKER, $language, $url);
    }

    /**
     * The pll_pre_translation_url filter: on an event page (or the event
     * archive), the EN | FI switch links to the same page with that
     * language's marker, instead of to the other language's home page.
     *
     * @param mixed $url
     * @param mixed $language PLL_Language.
     * @param mixed $queriedObjectId
     *
     * @return mixed
     */
    public function filterTranslationUrl($url, $language, $queriedObjectId = 0)
    {
        if ((is_string($url) && '' !== $url) || ! is_object($language) || ! isset($language->slug)) {
            return $url;
        }

        if (is_singular(EventPostType::NAME)) {
            $base = get_permalink((int) $queriedObjectId);
        } elseif (is_post_type_archive(EventPostType::NAME)) {
            $base = get_post_type_archive_link(EventPostType::NAME);
        } else {
            return $url;
        }

        if (! is_string($base) || '' === $base) {
            return $url;
        }

        return add_query_arg(self::MARKER, (string) $language->slug, remove_query_arg(self::MARKER, $base));
    }

    /**
     * Which language the event page is in, and why.
     *
     * @param string             $marker    The marker in the address, or ''.
     * @param string             $session   The language kept in the session cookie, or ''.
     * @param string             $browser   The Accept-Language header, or ''.
     * @param array<int, string> $languages The site's language slugs.
     *
     * @return array{language: string, source: string} source is 'marker',
     *         'session', 'browser', or 'default' with language ''.
     */
    public static function choose(string $marker, string $session, string $browser, array $languages): array
    {
        $languages = array_values(array_map('strtolower', $languages));

        foreach (['marker' => $marker, 'session' => $session] as $source => $value) {
            $value = strtolower(trim($value));

            if ('' !== $value && in_array($value, $languages, true)) {
                return ['language' => $value, 'source' => $source];
            }
        }

        $preferred = self::preferredInBrowser($browser, $languages);

        return '' !== $preferred
            ? ['language' => $preferred, 'source' => 'browser']
            : ['language' => '', 'source' => 'default'];
    }

    /**
     * The site language an Accept-Language header ranks highest (highest q,
     * then the one listed first), by its primary subtag: 'fi' for "fi-FI".
     * '' when it lists none of them.
     *
     * @param array<int, string> $languages
     */
    public static function preferredInBrowser(string $header, array $languages): string
    {
        $best = '';
        $bestQuality = 0.0;

        foreach (explode(',', $header) as $part) {
            $pieces = explode(';', $part);
            $primary = strtolower(trim(explode('-', trim($pieces[0]))[0]));

            if ('' === $primary || ! in_array($primary, $languages, true)) {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($pieces, 1) as $parameter) {
                if (1 === preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $parameter, $match)) {
                    $quality = (float) $match[1];
                }
            }

            // Strictly higher: an equal q keeps the one listed first.
            if ($quality > $bestQuality) {
                $best = $primary;
                $bestQuality = $quality;
            }
        }

        return $best;
    }

    /**
     * Whether $path (the request path) is an event page or the event
     * archive under the site's $homePath.
     */
    public static function isEventPath(string $path, string $homePath): bool
    {
        $homePath = rtrim($homePath, '/') . '/';

        if (! str_starts_with($path, $homePath)) {
            return false;
        }

        $relative = substr($path, strlen($homePath));

        return 1 === preg_match('#^' . preg_quote(EventPostType::REWRITE_SLUG, '#') . '(/|$)#', $relative);
    }

    /** Keeps the marker of this request in the session cookie. */
    private function remember(): void
    {
        $marker = self::marker();

        if ('' === $marker || headers_sent()) {
            return;
        }

        // No expiry: the cookie ends with the browser session.
        setcookie(
            self::COOKIE,
            $marker,
            [
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
        $_COOKIE[self::COOKIE] = $marker;
    }

    /** The language marker in this request's address, or ''. */
    private static function marker(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a display choice, not an action.
        return isset($_GET[self::MARKER]) && is_string($_GET[self::MARKER])
            ? sanitize_key(wp_unslash($_GET[self::MARKER]))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /** The path of this request's address. */
    private static function requestPath(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? sanitize_url(wp_unslash($_SERVER['REQUEST_URI']))
            : '';

        return (string) wp_parse_url($uri, PHP_URL_PATH);
    }

    private static function homePath(): string
    {
        return (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
    }

    /** The language Polylang reads from this request's address, or ''. */
    private static function languageInAddress(): string
    {
        if (! function_exists('PLL')) {
            return '';
        }

        $polylang = PLL();

        if (
            ! is_object($polylang) || ! isset($polylang->links_model) || ! is_object($polylang->links_model)
            || ! method_exists($polylang->links_model, 'get_language_from_url')
        ) {
            return '';
        }

        $slug = $polylang->links_model->get_language_from_url();

        return is_string($slug) ? $slug : '';
    }

    /**
     * Polylang's language slugs.
     *
     * @return array<int, string>
     */
    private static function siteLanguages(): array
    {
        if (! function_exists('pll_languages_list')) {
            return [];
        }

        $slugs = pll_languages_list(['fields' => 'slug']);

        return is_array($slugs) ? array_values(array_filter($slugs, 'is_string')) : [];
    }
}
