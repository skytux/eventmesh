<?php

declare(strict_types=1);

namespace EventMesh\Support;

/**
 * The list of other plugins that have connected themselves to EventMesh.
 *
 * EventMesh has no knowledge of any particular consumer - it only exposes a
 * filter and renders whoever answered it. A sibling plugin announces itself
 * by adding an entry:
 *
 *     add_filter('eventmesh/integrations', function (array $integrations): array {
 *         $integrations[] = [
 *             'id'     => 'eventcrew',
 *             'label'  => 'EventCrew',
 *             'status' => 'Connected — auto-creating tasks',
 *         ];
 *         return $integrations;
 *     });
 *
 * With no consumer active the filter returns nothing and the diagnostics
 * screen simply reports that nothing is connected. This is the read side of
 * the same one-directional link the `eventmesh/event_synced` action forms:
 * consumers may know EventMesh; EventMesh never names a consumer.
 */
final class Integrations
{
    public const FILTER = 'eventmesh/integrations';

    /**
     * Every connected integration, normalized. An entry with no usable label
     * is dropped rather than trusted, and a duplicate id keeps the first - a
     * consumer's filter callback is arbitrary code, so nothing it returns is
     * assumed well-formed.
     *
     * @return array<int, array{id: string, label: string, status: string}>
     */
    public static function all(): array
    {
        $raw = apply_filters(self::FILTER, []);

        if (! is_array($raw)) {
            return [];
        }

        $integrations = [];
        $seen = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $label = trim((string) ($entry['label'] ?? ''));

            if ('' === $label) {
                continue;
            }

            $id = sanitize_key((string) ($entry['id'] ?? $label));

            if ('' === $id || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            $integrations[] = [
                'id' => $id,
                'label' => $label,
                'status' => trim((string) ($entry['status'] ?? '')),
            ];
        }

        return $integrations;
    }
}
