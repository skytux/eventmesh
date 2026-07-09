<?php

declare(strict_types=1);

namespace EventMesh\Services;

use EventMesh\Content\PerformerTaxonomy;
use EventMesh\Models\Event;
use EventMesh\Support\Logger;

final class ProviderEnricher
{
    public function __construct(
        private readonly ArtistMap $artistMap,
        private readonly Logger $logger
    ) {
    }

    public function enrich(int $postId, Event $event): void
    {
        if ($postId <= 0) {
            return;
        }

        $artistName = $this->artistNameFromEvent($event);

        if ('' === $artistName) {
            return;
        }

        $providers = $this->artistMap->forArtist($artistName);

        if ([] === $providers) {
            return;
        }

        $this->attachPerformerTerm($postId, $artistName);

        foreach ($providers as $provider => $url) {
            $url = trim((string) $url);

            if ('' === $url) {
                continue;
            }

            $metaKey = sprintf('_eventmesh_provider_%s', sanitize_key($provider));
            update_post_meta($postId, $metaKey, esc_url_raw($url));
        }

        $this->logger->info(
            sprintf(
                'Applied provider enrichment for artist "%s" to post %d.',
                $artistName,
                $postId
            )
        );
    }

    private function artistNameFromEvent(Event $event): string
    {
        $title = trim($event->title());

        if ('' === $title) {
            return '';
        }

        $parts = preg_split('/\s*[-–—]\s*/', $title) ?: [$title];

        $candidate = trim($parts[0] ?? '');

        if ('' === $candidate) {
            return '';
        }

        return $candidate;
    }

    private function attachPerformerTerm(int $postId, string $artistName): void
    {
        $term = term_exists($artistName, PerformerTaxonomy::NAME);

        if (! $term) {
            $term = wp_insert_term($artistName, PerformerTaxonomy::NAME);
        }

        if (is_wp_error($term)) {
            return;
        }

        wp_set_object_terms($postId, [(int) $term['term_id']], PerformerTaxonomy::NAME, false);
    }
}
