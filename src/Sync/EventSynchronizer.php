<?php

declare(strict_types=1);

namespace EventMesh\Sync;

use EventMesh\Content\EventPostType;
use EventMesh\Models\Event;
use EventMesh\Services\EventMediaEnricher;
use EventMesh\Services\ProviderEmbedEnricher;
use EventMesh\Services\ProviderEnricher;
use EventMesh\Support\Logger;
use WP_Query;

final class EventSynchronizer
{
    public function __construct(
        private readonly Logger $logger,
        private readonly EventMediaEnricher $mediaEnricher,
        private readonly ProviderEnricher $providerEnricher,
        private readonly ProviderEmbedEnricher $providerEmbedEnricher
    ) {
    }

    public function sync(Event $event): int
    {
        $postId = $this->findExistingPostId($event);
        $isNew = 0 === $postId;

        $sourceTitle = $event->title();
        $sourceContent = $event->description();

        // Title and description are native post fields a person edits directly
        // in the block editor. Only overwrite them while they still match what
        // this plugin last wrote - once someone has changed either by hand, we
        // leave it alone so their edit survives every future sync (the edit
        // screen's "Follow source again" is how they hand control back).
        $writeTitle = true;
        $writeContent = true;

        if (! $isNew) {
            $existing = get_post($postId);

            if ($existing instanceof \WP_Post) {
                $writeTitle = $this->followsSource(
                    $existing->post_title,
                    (string) get_post_meta($postId, '_eventmesh_synced_title', true)
                );
                $writeContent = $this->followsSource(
                    md5((string) $existing->post_content),
                    (string) get_post_meta($postId, '_eventmesh_synced_content_hash', true)
                );
            }
        }

        $postData = [
            'ID' => $postId,
            'post_type' => EventPostType::NAME,
            'post_status' => 'publish',
        ];

        if ($writeTitle) {
            $postData['post_title'] = $sourceTitle;
        }

        if ($writeContent) {
            $postData['post_content'] = $sourceContent;
            $postData['post_excerpt'] = wp_trim_words($sourceContent, 55, '');
        }

        if ($isNew) {
            unset($postData['ID']);
            $result = wp_insert_post($postData, true);
        } else {
            $result = wp_update_post($postData, true);
        }

        if (is_wp_error($result)) {
            $this->logger->error(
                sprintf(
                    'Failed to synchronize event "%s": %s',
                    $event->title(),
                    $result->get_error_message()
                )
            );

            return 0;
        }

        $syncedPostId = (int) $result;

        // Always keep the source's latest title/description on hand so the edit
        // screen can restore them, and fingerprint whatever we actually wrote
        // so the next sync can detect a human edit and back off.
        update_post_meta($syncedPostId, '_eventmesh_source_title', $sourceTitle);
        update_post_meta($syncedPostId, '_eventmesh_source_content', $sourceContent);

        if ($writeTitle) {
            update_post_meta($syncedPostId, '_eventmesh_synced_title', $sourceTitle);
        }

        if ($writeContent) {
            update_post_meta($syncedPostId, '_eventmesh_synced_content_hash', md5($sourceContent));
        }

        $this->writeMeta($syncedPostId, $event);
        $this->mediaEnricher->enrich($syncedPostId, $event);
        $this->providerEnricher->enrich($syncedPostId, $event);
        $this->providerEmbedEnricher->enrich($syncedPostId);

        return $syncedPostId;
    }

    /**
     * @param array<int, Event> $events
     *
     * @return array{created: int, updated: int, failed: int, skipped: int}
     */
    public function syncMany(array $events): array
    {
        $result = [
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($events as $event) {
            if ('' === trim($event->title())) {
                ++$result['skipped'];
                continue;
            }

            $existingPostId = $this->findExistingPostId($event);
            $postId = $this->sync($event);

            if (0 === $postId) {
                ++$result['failed'];
                continue;
            }

            if (0 === $existingPostId) {
                ++$result['created'];
            } else {
                ++$result['updated'];
            }
        }

        return $result;
    }

    /**
     * Move published posts for a source to Draft when they're no longer
     * present in that source's latest fetch (cancelled/removed upstream).
     *
     * A post that reappears at the source later is naturally republished by
     * sync(), which looks posts up regardless of status and always writes
     * post_status => 'publish'.
     *
     * @param array<int, string> $seenExternalIds External IDs present in the current fetch.
     */
    public function pruneStale(string $sourceId, array $seenExternalIds): int
    {
        $query = new WP_Query(
            [
                'post_type' => EventPostType::NAME,
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'meta_query' => [
                    [
                        'key' => '_eventmesh_source_id',
                        'value' => $sourceId,
                        'compare' => '=',
                    ],
                ],
            ]
        );

        $archived = 0;

        foreach ($query->posts as $postId) {
            $postId = (int) $postId;
            $externalId = (string) get_post_meta($postId, '_eventmesh_external_id', true);

            if (in_array($externalId, $seenExternalIds, true)) {
                continue;
            }

            $result = wp_update_post(
                [
                    'ID' => $postId,
                    'post_status' => 'draft',
                ],
                true
            );

            if (is_wp_error($result)) {
                $this->logger->error(
                    sprintf(
                        'Failed to archive stale event #%d: %s',
                        $postId,
                        $result->get_error_message()
                    )
                );
                continue;
            }

            ++$archived;
        }

        if ($archived > 0) {
            $this->logger->info(
                sprintf(
                    'Archived %d stale event(s) for source "%s".',
                    $archived,
                    $sourceId
                )
            );
        }

        return $archived;
    }

    /**
     * Move published posts to Draft when the source that owns them is no
     * longer registered at all - a connector that was uninstalled, or the
     * test connector after its toggle was switched off. Skipping such posts
     * (as the per-connector sync loop necessarily does, having no connector
     * to iterate for them) would leave content live that nothing can ever
     * update or retire again.
     *
     * Posts with no _eventmesh_source_id at all (manually created events)
     * are deliberately left alone - they were never owned by a connector.
     *
     * @param array<int, string> $knownSourceIds IDs of every currently registered connector.
     */
    public function pruneOrphanedSources(array $knownSourceIds): int
    {
        $query = new WP_Query(
            [
                'post_type' => EventPostType::NAME,
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
            ]
        );

        $archived = 0;

        foreach ($query->posts as $postId) {
            $postId = (int) $postId;
            $sourceId = (string) get_post_meta($postId, '_eventmesh_source_id', true);

            if ('' === $sourceId || in_array($sourceId, $knownSourceIds, true)) {
                continue;
            }

            $result = wp_update_post(
                [
                    'ID' => $postId,
                    'post_status' => 'draft',
                ],
                true
            );

            if (is_wp_error($result)) {
                $this->logger->error(
                    sprintf(
                        'Failed to archive orphaned event #%d (source "%s"): %s',
                        $postId,
                        $sourceId,
                        $result->get_error_message()
                    )
                );
                continue;
            }

            ++$archived;
        }

        if ($archived > 0) {
            $this->logger->info(
                sprintf(
                    'Archived %d event(s) whose source connector is no longer registered.',
                    $archived
                )
            );
        }

        return $archived;
    }

    /**
     * Whether the sync still "owns" a native field and may overwrite it.
     *
     * With no fingerprint yet (a brand-new field, or a post from before this
     * tracking existed) the sync adopts the source exactly as it always did,
     * and starts fingerprinting from now on. Once a fingerprint exists, the
     * sync only overwrites while the field still matches it - i.e. nobody has
     * edited the field by hand since we last wrote it.
     */
    private function followsSource(string $current, string $lastWritten): bool
    {
        if ('' === $lastWritten) {
            return true;
        }

        return $current === $lastWritten;
    }

    private function findExistingPostId(Event $event): int
    {
        $query = new WP_Query(
            [
                'post_type' => EventPostType::NAME,
                'post_status' => 'any',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => '_eventmesh_source_id',
                        'value' => $event->sourceId(),
                        'compare' => '=',
                    ],
                    [
                        'key' => '_eventmesh_external_id',
                        'value' => $event->externalId(),
                        'compare' => '=',
                    ],
                ],
            ]
        );

        $postId = $query->posts[0] ?? 0;

        return (int) $postId;
    }

    private function writeMeta(int $postId, Event $event): void
    {
        update_post_meta($postId, '_eventmesh_source_id', $event->sourceId());
        update_post_meta($postId, '_eventmesh_external_id', $event->externalId());
        update_post_meta($postId, '_eventmesh_starts_at', $event->startsAt()?->format(DATE_ATOM) ?? '');
        update_post_meta($postId, '_eventmesh_starts_at_year_known', $event->startsAtYearKnown() ? '1' : '');
        update_post_meta($postId, '_eventmesh_ends_at', $event->endsAt()?->format(DATE_ATOM) ?? '');
        update_post_meta($postId, '_eventmesh_url', esc_url_raw($event->url()));
        update_post_meta($postId, '_eventmesh_image_url', esc_url_raw($event->imageUrl()));
        update_post_meta($postId, '_eventmesh_sold_out', $event->soldOut() ? '1' : '');

        // Venue is often unreliable to auto-extract; once someone has
        // manually filled it in (or a previous sync found one), a sync that
        // finds nothing this time should not blank it out. Only overwrite
        // when this fetch actually found a venue.
        if ('' !== trim($event->venueName())) {
            update_post_meta($postId, '_eventmesh_venue_name', sanitize_text_field($event->venueName()));
        }

        // Same guard as venue: the listing page usually carries the price but
        // an event's own detail page may not, so a fetch that finds none must
        // not wipe a price a previous fetch (or a person) already set.
        if ('' !== trim($event->price())) {
            update_post_meta($postId, '_eventmesh_price', sanitize_text_field($event->price()));
        }
    }
}
