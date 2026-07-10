<?php

declare(strict_types=1);

namespace EventMesh\Services;

final class SourceSettings
{
    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        $stored = get_option('eventmesh_source_settings', []);

        if (! is_array($stored)) {
            $stored = [];
        }

        $enabled = [];

        foreach ($stored as $sourceId => $value) {
            $enabled[(string) $sourceId] = (bool) $value;
        }

        return $enabled;
    }

    public function isEnabled(string $sourceId): bool
    {
        $settings = $this->all();

        return $settings[$sourceId] ?? true;
    }

    public function setEnabled(string $sourceId, bool $enabled): void
    {
        $settings = $this->all();
        $settings[$sourceId] = $enabled;

        update_option('eventmesh_source_settings', $settings);
    }
}
