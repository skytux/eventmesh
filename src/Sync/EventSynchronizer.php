<?php

declare(strict_types=1);

namespace EventMesh\Sync;

use EventMesh\Content\EventPostType;
use EventMesh\Models\Event;
use EventMesh\Services\EventMediaEnricher;
use EventMesh\Services\ProviderEnricher;
use EventMesh\Support\Logger;
use WP_Query;

final class EventSynchronizer
{
    public function __construct(
        private readonly Logger $logger,
        private readonly EventMediaEnricher $mediaEnricher,
        private readonly ProviderEnricher $providerEnricher
    ) {
    }

    public function sync(Event $event): int
    {
        $postId = $this->findExistingPostId($event);
        $postData = [
            'ID' => $postId,
            'post_type' => EventPostType::NAME,
            'post_status' => 'publish',
            'post_title' => $event->title(),
            'post_content' => $event->description(),
            'post_excerpt' => wp_trim_words($event->description(), 55, ''),
        ];

        if (0 === $postId) {
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
        $this->writeMeta($syncedPostId, $event);
        $this->mediaEnricher->enrich($syncedPostId, $event);
        $this->providerEnricher->enrich($syncedPostId, $event);

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
        update_post_meta($postId, '_eventmesh_ends_at', $event->endsAt()?->format(DATE_ATOM) ?? '');
        update_post_meta($postId, '_eventmesh_url', esc_url_raw($event->url()));
        update_post_meta($postId, '_eventmesh_image_url', esc_url_raw($event->imageUrl()));
        update_post_meta($postId, '_eventmesh_venue_name', sanitize_text_field($event->venueName()));
    }
}
