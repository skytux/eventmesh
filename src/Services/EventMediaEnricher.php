<?php

declare(strict_types=1);

namespace EventMesh\Services;

use EventMesh\Models\Event;
use EventMesh\Support\Logger;

final class EventMediaEnricher
{
    public function __construct(
        private readonly Logger $logger
    ) {
    }

    public function enrich(int $postId, Event $event): bool
    {
        if ($postId <= 0) {
            return false;
        }

        // A featured image already present - whether a previous sync set it or
        // a person replaced it by hand - is left untouched, so manual images
        // survive. reapplyFromSource() is the deliberate way back to the
        // source image.
        if (has_post_thumbnail($postId)) {
            return false;
        }

        return $this->sideload($postId, trim($event->imageUrl()), $event->title());
    }

    /**
     * Drop the current featured image and re-fetch the source's image, used by
     * the edit screen's "Follow source again" control. The old attachment is
     * left in the media library (it may be used elsewhere); only the featured
     * image link is replaced.
     */
    public function reapplyFromSource(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }

        $imageUrl = trim((string) get_post_meta($postId, '_eventmesh_image_url', true));

        if ('' === $imageUrl) {
            return false;
        }

        delete_post_thumbnail($postId);

        return $this->sideload($postId, $imageUrl, (string) get_the_title($postId));
    }

    private function sideload(int $postId, string $imageUrl, string $title): bool
    {
        if ('' === $imageUrl) {
            return false;
        }

        if (! function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attachmentId = media_sideload_image($imageUrl, $postId, $title, 'id');

        if (is_wp_error($attachmentId)) {
            $this->logger->warning(
                sprintf(
                    'Failed to attach media for event "%s": %s',
                    $title,
                    $attachmentId->get_error_message()
                )
            );

            return false;
        }

        $attachmentId = (int) $attachmentId;

        if ($attachmentId <= 0 || ! set_post_thumbnail($postId, $attachmentId)) {
            $this->logger->warning(
                sprintf(
                    'Failed to set featured image for event "%s".',
                    $title
                )
            );

            return false;
        }

        return true;
    }
}
