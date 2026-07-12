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
        return wp_kses($html, self::ALLOWED_TAGS);
    }
}
