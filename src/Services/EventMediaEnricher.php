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

        if (has_post_thumbnail($postId)) {
            return false;
        }

        $imageUrl = trim($event->imageUrl());

        if ('' === $imageUrl) {
            return false;
        }

        if (! function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attachmentId = media_sideload_image($imageUrl, $postId, $event->title(), 'id');

        if (is_wp_error($attachmentId)) {
            $this->logger->warning(
                sprintf(
                    'Failed to attach media for event "%s": %s',
                    $event->title(),
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
                    $event->title()
                )
            );

            return false;
        }

        return true;
    }
}
