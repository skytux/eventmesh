<?php

declare(strict_types=1);

namespace EventMesh\Support;

/**
 * Strips anything that isn't the compact <iframe> embed markup
 * ProviderEmbedEnricher fetches from Spotify/SoundCloud/Mixcloud's oEmbed
 * endpoints. Applied both when the embed is first fetched and again right
 * before it's ever echoed, since _eventmesh_embed_html is stored as raw HTML
 * in post meta and must never be trusted as pre-sanitized by the time it's
 * rendered.
 *
 * This is also the single point every embed passes through on both output
 * paths (the provider-embed block and the event-list template) and on
 * storage, so it's where the iframe is marked loading="lazy" - one place,
 * and already-stored embeds get it too without a re-sync.
 */
final class EmbedHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'iframe' => [
            'src' => true,
            'width' => true,
            'height' => true,
            'frameborder' => true,
            'allow' => true,
            'allowfullscreen' => true,
            'loading' => true,
            'title' => true,
            'style' => true,
        ],
    ];

    public static function sanitize(string $html): string
    {
        return wp_kses(
            self::lazyLoadIframes(self::titledIframes(self::soundCloudWithoutTeaser($html))),
            self::ALLOWED_TAGS
        );
    }

    /**
     * Sets show_teaser=false on every SoundCloud player src. With the
     * teaser on (SoundCloud's default), the player on iOS and Android
     * covers itself with a "Play on SoundCloud" / "Listen in browser"
     * screen. An src that already has show_teaser gets its value replaced.
     * Done here, so stored embeds get it on output without a re-sync, and
     * the facade's data-src carries it too.
     */
    private static function soundCloudWithoutTeaser(string $html): string
    {
        return (string) preg_replace_callback(
            '/(\ssrc=)(["\'])(https:\/\/w\.soundcloud\.com\/player\/[^"\']*)\2/i',
            static function (array $matches): string {
                $url = (string) preg_replace(
                    '/(\?|&amp;|&)show_teaser=[^&#]*/i',
                    '$1show_teaser=false',
                    $matches[3],
                    -1,
                    $count
                );

                if (0 === $count) {
                    $url .= (str_contains($url, '?') ? '&' : '?') . 'show_teaser=false';
                }

                return $matches[1] . $matches[2] . $url . $matches[2];
            },
            $html
        );
    }

    /**
     * Gives each embed iframe a screen-reader title if it lacks one - provider
     * oEmbed markup often omits it, which fails accessibility audits ("frame
     * without a title"). Named for the provider where the src makes it obvious.
     */
    private static function titledIframes(string $html): string
    {
        return (string) preg_replace_callback(
            '/<iframe\b[^>]*>/i',
            static function (array $matches): string {
                if (false !== stripos($matches[0], ' title=')) {
                    return $matches[0];
                }

                return (string) preg_replace(
                    '/<iframe\b/i',
                    '<iframe title="' . self::iframeTitle($matches[0]) . '"',
                    $matches[0],
                    1
                );
            },
            $html
        );
    }

    private static function iframeTitle(string $iframeTag): string
    {
        $labels = [
            'spotify' => __('Spotify player', 'eventmesh'),
            'soundcloud' => __('SoundCloud player', 'eventmesh'),
            'mixcloud' => __('Mixcloud player', 'eventmesh'),
        ];

        foreach ($labels as $needle => $label) {
            if (false !== stripos($iframeTag, $needle)) {
                return $label;
            }
        }

        return __('Embedded media player', 'eventmesh');
    }

    /**
     * Defers each third-party player until it scrolls near the viewport by
     * stamping loading="lazy" on the embed iframe, so a page listing many
     * events doesn't fire every provider's player request on load. Native and
     * JS-free; an iframe that already declares a loading attribute is left
     * untouched.
     */
    private static function lazyLoadIframes(string $html): string
    {
        return (string) preg_replace_callback(
            '/<iframe\b(?![^>]*\bloading=)/i',
            static fn (array $m): string => '<iframe loading="lazy"',
            $html
        );
    }
}
