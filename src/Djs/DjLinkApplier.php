<?php

declare(strict_types=1);

namespace EventMesh\Djs;

use EventMesh\Content\EventPostType;
use EventMesh\Services\ProviderEmbedEnricher;
use EventMesh\Support\EventMeta;
use EventMesh\Support\LocalTime;
use WP_Query;

/**
 * Gives an event the links of the DJs its title or text names.
 *
 * Rules, per service (Spotify, SoundCloud, Mixcloud):
 *
 * - A link typed by hand on the event (_eventmesh_manual_provider_{service})
 *   is never replaced.
 * - A link the source gave (_eventmesh_provider_{service}) is never replaced.
 * - Otherwise the first matching DJ (in the order the names appear in the
 *   text) that has a link on that service gives it. The link is written to
 *   _eventmesh_provider_{service}, the same place a link from the source
 *   goes, so every block and template shows it with no other change.
 *
 * Which links came from the DJ table is recorded in _eventmesh_dj_links, so a
 * later change in the table can update or remove exactly those links and
 * nothing else. When the source later gives its own link on that service,
 * the source's link replaces the DJ table's link and the record is dropped.
 */
final class DjLinkApplier
{
    public const ADDED_META = '_eventmesh_dj_links';

    public function __construct(
        private readonly DjRepository $repository,
        private readonly ?ProviderEmbedEnricher $providerEmbedEnricher = null
    ) {
    }

    /**
     * Updates the event's links from the DJ table. Does not rebuild the
     * player; the caller does that (save and sync already do it right after).
     *
     * @return bool True when a link was added, changed or removed.
     */
    public function apply(int $postId): bool
    {
        $post = get_post($postId);

        if (! $post instanceof \WP_Post || EventPostType::NAME !== $post->post_type) {
            return false;
        }

        $added = $this->readAdded($postId);
        $djs = $this->repository->all();

        if ([] === $djs && [] === $added) {
            return false;
        }

        $matched = DjMatcher::matches($djs, $post->post_title . "\n" . $post->post_content);

        $manual = [];
        $scraped = [];

        foreach (Dj::LINK_KINDS as $kind) {
            $manual[$kind] = trim((string) get_post_meta($postId, '_eventmesh_manual_provider_' . $kind, true));
            $scraped[$kind] = trim((string) get_post_meta($postId, '_eventmesh_provider_' . $kind, true));
        }

        $decision = self::decide($matched, $manual, $scraped, $added);

        foreach ($decision['writes'] as $kind => $url) {
            update_post_meta($postId, '_eventmesh_provider_' . $kind, $url);
        }

        if ($decision['added'] !== $added) {
            if ([] === $decision['added']) {
                delete_post_meta($postId, self::ADDED_META);
            } else {
                update_post_meta($postId, self::ADDED_META, (string) wp_json_encode($decision['added']));
            }
        }

        return [] !== $decision['writes'];
    }

    /**
     * Runs apply() on every event whose start is now or later, and rebuilds
     * the player of each event whose links changed. Used after a DJ is added,
     * changed or deleted in the table.
     *
     * @return int How many events had a link added, changed or removed.
     */
    public function recheckFutureEvents(): int
    {
        $query = new WP_Query(
            [
                'post_type' => EventPostType::NAME,
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
            ]
        );

        $now = wp_date(LocalTime::STORAGE_FORMAT);
        $changed = 0;

        foreach ($query->posts as $postId) {
            $postId = (int) $postId;
            $startsAt = EventMeta::resolve($postId, 'starts_at');

            if ('' === $startsAt || $startsAt < $now) {
                continue;
            }

            if ($this->apply($postId)) {
                ++$changed;
                $this->providerEmbedEnricher?->enrich($postId);
            }
        }

        return $changed;
    }

    /**
     * The rules from the class comment, without any WordPress calls.
     *
     * @param array<int, Dj>        $matched DJs named in the event, first named first.
     * @param array<string, string> $manual  Service => link typed by hand on the event ('' = none).
     * @param array<string, string> $scraped Service => link in _eventmesh_provider_* ('' = none).
     * @param array<string, string> $added   Service => link the DJ table wrote earlier.
     *
     * @return array{writes: array<string, string>, added: array<string, string>}
     *         writes: service => new value of _eventmesh_provider_* ('' removes the link);
     *         added: the new record of links that came from the DJ table.
     */
    public static function decide(array $matched, array $manual, array $scraped, array $added): array
    {
        $writes = [];
        $newAdded = [];

        foreach (Dj::LINK_KINDS as $kind) {
            $current = trim((string) ($scraped[$kind] ?? ''));
            $ours = '' !== $current && ($added[$kind] ?? '') === $current;

            // A link from the source (or from before the DJ table): leave it.
            if ('' !== $current && ! $ours) {
                continue;
            }

            $tableLink = '';

            foreach ($matched as $dj) {
                if ('' !== $dj->link($kind)) {
                    $tableLink = $dj->link($kind);
                    break;
                }
            }

            // A hand-typed link always wins on the page; the DJ table does
            // not write under it, and a link it wrote earlier is removed.
            if ('' !== trim((string) ($manual[$kind] ?? ''))) {
                $tableLink = '';
            }

            if ($tableLink !== $current) {
                $writes[$kind] = $tableLink;
            }

            if ('' !== $tableLink) {
                $newAdded[$kind] = $tableLink;
            }
        }

        return ['writes' => $writes, 'added' => $newAdded];
    }

    /**
     * @return array<string, string>
     */
    private function readAdded(int $postId): array
    {
        $decoded = json_decode((string) get_post_meta($postId, self::ADDED_META, true), true);

        if (! is_array($decoded)) {
            return [];
        }

        $added = [];

        foreach (Dj::LINK_KINDS as $kind) {
            if (isset($decoded[$kind]) && is_string($decoded[$kind]) && '' !== $decoded[$kind]) {
                $added[$kind] = $decoded[$kind];
            }
        }

        return $added;
    }
}
