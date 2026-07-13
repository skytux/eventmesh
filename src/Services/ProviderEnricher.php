<?php

declare(strict_types=1);

namespace EventMesh\Services;

use EventMesh\Models\Event;
use EventMesh\Support\Logger;

final class ProviderEnricher
{
    public function __construct(
        private readonly Logger $logger
    ) {
    }

    public function enrich(int $postId, Event $event): void
    {
        if ($postId <= 0) {
            return;
        }

        $providers = $event->providers();

        if ([] === $providers) {
            return;
        }

        $applied = 0;

        foreach ($providers as $provider => $url) {
            $url = trim((string) $url);

            if ('' === $url) {
                continue;
            }

            $metaKey = sprintf('_eventmesh_provider_%s', sanitize_key($provider));
            update_post_meta($postId, $metaKey, esc_url_raw($url));
            ++$applied;
        }

        if ($applied > 0) {
            $this->logger->info(
                sprintf(
                    'Applied %d provider link(s) parsed from the source to post %d.',
                    $applied,
                    $postId
                )
            );
        }
    }
}
