<?php

declare(strict_types=1);

namespace EventMesh\Services;

final class ArtistMap
{
    /**
     * @return array<string, array<string, string>>
     */
    public function all(): array
    {
        $storedValue = get_option('eventmesh_artist_map', '');
        $decoded = $this->decode($storedValue);

        if (null !== $decoded) {
            return $this->normalize($decoded);
        }

        $path = EVENTMESH_PLUGIN_DIR . 'config/artist-map.json';

        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if (false === $contents) {
            return [];
        }

        $decoded = $this->decode($contents);

        if (null === $decoded) {
            return [];
        }

        return $this->normalize($decoded);
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(string $json): ?array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return array<string, array<string, string>>
     */
    private function normalize(array $decoded): array
    {
        $result = [];

        foreach ($decoded as $artist => $providers) {
            if (! is_string($artist) || ! is_array($providers)) {
                continue;
            }

            $normalized = [];

            foreach ($providers as $provider => $value) {
                if (! is_string($provider) || ! is_string($value)) {
                    continue;
                }

                $normalized[$provider] = $value;
            }

            $result[$artist] = $normalized;
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    public function forArtist(string $artist): array
    {
        $all = $this->all();

        if (! isset($all[$artist])) {
            return [];
        }

        return $all[$artist];
    }
}
