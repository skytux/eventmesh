<?php

declare(strict_types=1);

namespace EventMesh\Support;

/**
 * The single canonical list of external providers EventMesh recognizes -
 * shared between HolviHtmlParser (which domain a link belongs to) and the
 * event edit screen's manual override fields (which providers to show).
 */
final class KnownProviders
{
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
