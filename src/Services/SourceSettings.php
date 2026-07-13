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

    /**
     * A source with no stored choice yet falls back to $default - which the
     * caller derives from the connector's own enabledByDefault(), so a fresh
     * real source is on while the dummy stays off until deliberately enabled.
     */
    public function isEnabled(string $sourceId, bool $default = true): bool
    {
        $settings = $this->all();

        return $settings[$sourceId] ?? $default;
    }

    public function setEnabled(string $sourceId, bool $enabled): void
    {
        $settings = $this->all();
        $settings[$sourceId] = $enabled;

        update_option('eventmesh_source_settings', $settings);
    }
}
