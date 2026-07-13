<?php

declare(strict_types=1);

namespace EventMesh\Support;

/**
 * Resolves an event's effective field values, preferring what a person typed
 * on the edit screen over what the last sync scraped from the source.
 *
 * Each overridable field is stored twice: the sync writes the scraped value
 * to `_eventmesh_{key}` (never touching the override), and the edit screen
 * writes a manual override to `_eventmesh_manual_{key}`. Every consumer reads
 * through here so a hand-entered value always wins and, crucially, survives
 * the next sync - while a blank override transparently falls back to whatever
 * the source most recently provided.
 */
final class EventMeta
{
    private const MANUAL_PREFIX = '_eventmesh_manual_';
    private const SCRAPED_PREFIX = '_eventmesh_';

    /**
     * @param string $baseKey the field name without any prefix, e.g. "price",
     *                        "venue_name", "starts_at", "provider_spotify"
     */
    public static function resolve(int $postId, string $baseKey): string
    {
        $manual = trim((string) get_post_meta($postId, self::MANUAL_PREFIX . $baseKey, true));

        if ('' !== $manual) {
            return $manual;
        }

        return trim((string) get_post_meta($postId, self::SCRAPED_PREFIX . $baseKey, true));
    }

    /**
     * True when the resolved sold-out state is "sold out". The manual override
     * uses a tri-state string ('' = auto/follow the source, '1' = sold out,
     * '0' = force available) so a person can both force and un-force it.
     */
    public static function isSoldOut(int $postId): bool
    {
        $manual = (string) get_post_meta($postId, self::MANUAL_PREFIX . 'sold_out', true);

        if ('1' === $manual) {
            return true;
        }

        if ('0' === $manual) {
            return false;
        }

        return '1' === (string) get_post_meta($postId, self::SCRAPED_PREFIX . 'sold_out', true);
    }

    /**
     * Every provider link for the event, keyed by provider (spotify, ...),
     * with any manual override taking precedence over the scraped value and
     * empty values dropped. Reads all `_eventmesh_provider_*` and
     * `_eventmesh_manual_provider_*` meta in one pass.
     *
     * @return array<string, string>
     */
    public static function resolvedProviders(int $postId): array
    {
        $scraped = [];
        $manual = [];

        foreach ((array) get_post_meta($postId) as $key => $values) {
            $value = trim((string) ($values[0] ?? ''));

            if ('' === $value) {
                continue;
            }

            if (str_starts_with($key, self::MANUAL_PREFIX . 'provider_')) {
                $manual[substr($key, strlen(self::MANUAL_PREFIX . 'provider_'))] = $value;
            } elseif (str_starts_with($key, self::SCRAPED_PREFIX . 'provider_')) {
                $scraped[substr($key, strlen(self::SCRAPED_PREFIX . 'provider_'))] = $value;
            }
        }

        return array_merge($scraped, $manual);
    }
}
