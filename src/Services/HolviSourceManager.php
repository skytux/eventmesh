<?php

declare(strict_types=1);

namespace EventMesh\Services;

final class HolviSourceManager
{
    /**
     * @return array<int, array{id: string, url: string, enabled: bool}>
     */
    public function all(): array
    {
        $stored = get_option('eventmesh_holvi_sources', []);

        if (! is_array($stored)) {
            return [];
        }

        $rows = [];

        foreach ($stored as $index => $source) {
            if (! is_array($source)) {
                continue;
            }

            $url = (string) ($source['url'] ?? '');
            $enabled = isset($source['enabled']) ? (bool) $source['enabled'] : true;

            if ('' === trim($url)) {
                continue;
            }

            $rows[] = [
                'id' => (string) ($source['id'] ?? $index),
                'url' => $url,
                'enabled' => $enabled,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public function enabledUrls(): array
    {
        return array_values(
            array_filter(
                array_map(
                    static fn (array $source): string => $source['enabled'] ? $source['url'] : '',
                    $this->all()
                )
            )
        );
    }

    public function save(array $sources): void
    {
        $normalized = [];

        foreach ($sources as $index => $source) {
            if (! is_array($source)) {
                continue;
            }

            $url = trim((string) ($source['url'] ?? ''));

            if ('' === $url) {
                continue;
            }

            $normalized[] = [
                'id' => (string) ($source['id'] ?? $index),
                'url' => esc_url_raw($url),
                // Default to DISABLED when absent: an unchecked checkbox
                // submits nothing, so defaulting to true made it impossible
                // to ever switch a row off (the form pairs each checkbox
                // with a hidden 0, so a present-and-checked box still
                // arrives as 1).
                'enabled' => (bool) ($source['enabled'] ?? false),
            ];
        }

        update_option('eventmesh_holvi_sources', $normalized);
    }
}
