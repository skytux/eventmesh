<?php

declare(strict_types=1);

namespace EventMesh\Support;

/**
 * The single canonical list of external providers EventMesh recognizes -
 * shared between HolviHtmlParser (which domain a link belongs to), the event
 * edit screen's manual override fields (which providers to show), and the
 * embed facade button (which platform to name on it).
 */
final class KnownProviders
{
    /**
     * Which provider a URL belongs to, or null if it isn't one we know.
     *
     * Matches the host's own domain and any subdomain of it, since providers
     * serve embeds from their own subdomains - open.spotify.com,
     * w.soundcloud.com, player.mixcloud.com - not their bare domain.
     */
    public static function keyForUrl(string $url): ?string
    {
        // wp_parse_url returns false outright on a malformed URL, so the
        // result is checked before being indexed rather than after.
        $parts = wp_parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        if ('' === $host) {
            return null;
        }

        foreach (self::domains() as $domain => $provider) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * The human-readable platform name for a URL ("Spotify"), or null when the
     * URL belongs to no provider we recognize.
     */
    public static function labelForUrl(string $url): ?string
    {
        $key = self::keyForUrl($url);

        return null === $key ? null : (self::labels()[$key] ?? null);
    }

    /**
     * @return array<string, string> Provider key => human-readable label.
     */
    public static function labels(): array
    {
        return [
            'spotify' => 'Spotify',
            'mixcloud' => 'Mixcloud',
            'soundcloud' => 'SoundCloud',
            'youtube' => 'YouTube',
            'bandcamp' => 'Bandcamp',
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
        ];
    }

    /**
     * @return array<string, string> Hostname (without "www.") => provider key.
     */
    public static function domains(): array
    {
        return [
            'spotify.com' => 'spotify',
            'mixcloud.com' => 'mixcloud',
            'soundcloud.com' => 'soundcloud',
            'youtube.com' => 'youtube',
            'youtu.be' => 'youtube',
            'bandcamp.com' => 'bandcamp',
            'instagram.com' => 'instagram',
            'facebook.com' => 'facebook',
        ];
    }
}
