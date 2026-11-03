<?php

declare(strict_types=1);

namespace EventMesh\Djs;

use EventMesh\Support\Logger;

/**
 * Fetches the profile text a DJ wrote on SoundCloud or Mixcloud.
 *
 * - SoundCloud: the public oEmbed endpoint answers a profile address with a
 *   `description` field, the profile's own text. No key is needed.
 * - Mixcloud: the public API (api.mixcloud.com/<username>/) answers with a
 *   `biog` field. No key is needed.
 *
 * Only the profile is asked for. A track, set or mix address gives its first
 * path part, which on both services is the user name; addresses whose first
 * part is one of the services' own pages (discover, search, ...) are
 * skipped. A failed request is logged and gives no text.
 */
final class ProfileDescriptions
{
    public const FETCH_TIMEOUT = 6;

    private const SOUNDCLOUD_OEMBED = 'https://soundcloud.com/oembed';
    private const MIXCLOUD_API = 'https://api.mixcloud.com/';

    private const SOUNDCLOUD_HOSTS = ['soundcloud.com', 'www.soundcloud.com', 'm.soundcloud.com'];
    private const MIXCLOUD_HOSTS = ['mixcloud.com', 'www.mixcloud.com', 'm.mixcloud.com'];

    /**
     * First path parts that are the services' own pages, not user names.
     */
    private const RESERVED = [
        'oembed', 'discover', 'search', 'stream', 'you', 'charts', 'upload', 'pages', 'terms-of-use',
        'pro', 'mobile', 'settings', 'messages', 'notifications', 'tags', 'tag', 'categories', 'live',
        'select', 'dashboard', 'player', 'widget', 'feed', 'people', 'sets', 'tracks', 'popular',
    ];

    public function __construct(
        private readonly Logger $logger
    ) {
    }

    /**
     * The service to ask for this DJ and the profile address or user name,
     * or null when the DJ has no usable link. SoundCloud is asked first,
     * then Mixcloud; only one is asked per refresh.
     *
     * @return array{0: string, 1: string}|null [Dj::SOURCE_SOUNDCLOUD|Dj::SOURCE_MIXCLOUD, profile]
     */
    public static function target(Dj $dj): ?array
    {
        $soundcloud = self::soundcloudProfileUrl($dj->link('soundcloud'));

        if ('' !== $soundcloud) {
            return [Dj::SOURCE_SOUNDCLOUD, $soundcloud];
        }

        $mixcloud = self::mixcloudUsername($dj->link('mixcloud'));

        if ('' !== $mixcloud) {
            return [Dj::SOURCE_MIXCLOUD, $mixcloud];
        }

        return null;
    }

    /**
     * The profile text, '' when there is none or the request failed.
     *
     * @param array{0: string, 1: string} $target From target().
     */
    public function fetch(array $target): string
    {
        [$source, $profile] = $target;

        if (Dj::SOURCE_SOUNDCLOUD === $source) {
            $endpoint = self::SOUNDCLOUD_OEMBED . '?format=json&url=' . rawurlencode($profile);
            $body = $this->get($endpoint, $profile);

            return null === $body ? '' : self::parseSoundcloudOembed($body);
        }

        $body = $this->get(self::MIXCLOUD_API . rawurlencode($profile) . '/', $profile);

        return null === $body ? '' : self::parseMixcloudUser($body);
    }

    /**
     * "https://soundcloud.com/<user>" for a SoundCloud profile, track or
     * set address, '' for anything else.
     */
    public static function soundcloudProfileUrl(string $url): string
    {
        $user = self::firstPathPart($url, self::SOUNDCLOUD_HOSTS);

        return '' === $user ? '' : 'https://soundcloud.com/' . $user;
    }

    /**
     * The user name of a Mixcloud profile or mix address, '' for anything
     * else.
     */
    public static function mixcloudUsername(string $url): string
    {
        return self::firstPathPart($url, self::MIXCLOUD_HOSTS);
    }

    public static function parseSoundcloudOembed(string $body): string
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) && is_string($decoded['description'] ?? null)
            ? self::cleanText($decoded['description'])
            : '';
    }

    public static function parseMixcloudUser(string $body): string
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) && is_string($decoded['biog'] ?? null)
            ? self::cleanText($decoded['biog'])
            : '';
    }

    /**
     * Line endings made "\n", spaces at line ends removed, three or more
     * line breaks made into one blank line.
     */
    private static function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[ \t\x{00A0}]+\n/u', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * @param array<int, string> $hosts
     */
    private static function firstPathPart(string $url, array $hosts): string
    {
        $url = trim($url);

        if ('' === $url) {
            return '';
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['host'] ?? '')), $hosts, true)) {
            return '';
        }

        $segments = array_values(array_filter(explode('/', (string) ($parts['path'] ?? '')), 'strlen'));
        $first = $segments[0] ?? '';

        if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $first) || in_array(strtolower($first), self::RESERVED, true)) {
            return '';
        }

        return $first;
    }

    private function get(string $endpoint, string $profile): ?string
    {
        $response = wp_remote_get($endpoint, ['timeout' => self::FETCH_TIMEOUT]);

        if (is_wp_error($response)) {
            $this->logger->warning(
                sprintf('DJ description fetch failed for "%s": %s', $profile, $response->get_error_message())
            );

            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if (200 > $status || 299 < $status) {
            $this->logger->warning(sprintf('DJ description fetch returned HTTP %d for "%s".', $status, $profile));

            return null;
        }

        return (string) wp_remote_retrieve_body($response);
    }
}
