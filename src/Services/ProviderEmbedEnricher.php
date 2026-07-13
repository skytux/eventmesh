<?php

declare(strict_types=1);

namespace EventMesh\Services;

use EventMesh\Support\EmbedHtmlSanitizer;
use EventMesh\Support\EventMeta;
use EventMesh\Support\Logger;

/**
 * Resolves a compact player-widget embed for whichever of an event's
 * provider links (Spotify, SoundCloud, Mixcloud - the three with official
 * oEmbed endpoints and compact-friendly players) is highest priority.
 *
 * Deliberately source-agnostic: it reads the already-persisted
 * _eventmesh_provider_{name} post meta rather than an Event object, since
 * that meta can come from three different places - Holvi's own page,
 * the manually-configured artist map, or a site owner typing/correcting a
 * link directly on the event's edit screen. Callers invoke enrich()
 * whenever any of those may have just changed.
 */
final class ProviderEmbedEnricher
{
    /**
     * Detail-page/media enrichment already caps itself for the same reason:
     * a large backlog of events would otherwise risk pushing a single sync
     * run past the host's execution-time limit. Since enrich() is called
     * once per event from a loop the caller controls, this budget is
     * tracked as instance state rather than reset per call.
     */
    private const MAX_EMBED_FETCHES_PER_RUN = 15;
    private const EMBED_FETCH_TIME_BUDGET_SECONDS = 15.0;
    private const FETCH_TIMEOUT = 8;

    /**
     * Only these three have official oEmbed endpoints and produce
     * genuinely compact players. When more than one is present on the same
     * event, the first match here wins.
     */
    private const PRIORITY_PROVIDERS = ['spotify', 'soundcloud', 'mixcloud'];

    private const OEMBED_ENDPOINTS = [
        'spotify' => 'https://open.spotify.com/oembed',
        'soundcloud' => 'https://soundcloud.com/oembed',
        'mixcloud' => 'https://www.mixcloud.com/oembed/',
    ];

    private const EXPECTED_HOSTS = [
        'spotify' => 'open.spotify.com',
        'soundcloud' => 'w.soundcloud.com',
        'mixcloud' => 'mixcloud.com',
    ];

    private const COMPACT_HEIGHT = 70;

    private int $fetchesRemaining = self::MAX_EMBED_FETCHES_PER_RUN;
    private float $deadline;

    public function __construct(
        private readonly Logger $logger
    ) {
        $this->deadline = microtime(true) + self::EMBED_FETCH_TIME_BUDGET_SECONDS;
    }

    public function enrich(int $postId): void
    {
        if ($postId <= 0) {
            return;
        }

        [$provider, $url] = $this->pickProvider($postId);

        if (null === $provider) {
            $this->clearCachedEmbed($postId);

            return;
        }

        $cachedUrl = (string) get_post_meta($postId, '_eventmesh_embed_source_url', true);

        if ($cachedUrl === $url) {
            return;
        }

        if (! $this->hasBudget()) {
            return;
        }

        $html = $this->fetchEmbed($provider, $url);

        if (null === $html) {
            $this->clearCachedEmbed($postId);

            return;
        }

        update_post_meta($postId, '_eventmesh_embed_html', EmbedHtmlSanitizer::sanitize($html));
        update_post_meta($postId, '_eventmesh_embed_source_url', $url);
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function pickProvider(int $postId): array
    {
        foreach (self::PRIORITY_PROVIDERS as $provider) {
            // Resolve so a manually-entered provider link on the edit screen
            // drives the embed, overriding whatever was scraped.
            $url = EventMeta::resolve($postId, 'provider_' . $provider);

            if ('' !== $url) {
                return [$provider, $url];
            }
        }

        return [null, ''];
    }

    private function hasBudget(): bool
    {
        if ($this->fetchesRemaining <= 0 || microtime(true) >= $this->deadline) {
            return false;
        }

        --$this->fetchesRemaining;

        return true;
    }

    /**
     * A cached embed is only ever for a URL that's no longer current once
     * we get here (either nothing matched anymore, or a fetch for a new URL
     * failed) - clearing it avoids showing a widget for the wrong link.
     */
    private function clearCachedEmbed(int $postId): void
    {
        if ('' === (string) get_post_meta($postId, '_eventmesh_embed_html', true)) {
            return;
        }

        update_post_meta($postId, '_eventmesh_embed_html', '');
        update_post_meta($postId, '_eventmesh_embed_source_url', '');
    }

    private function fetchEmbed(string $provider, string $url): ?string
    {
        $endpoint = sprintf(
            '%s?url=%s&format=json&maxheight=%d',
            self::OEMBED_ENDPOINTS[$provider],
            rawurlencode($url),
            self::COMPACT_HEIGHT
        );

        $response = wp_remote_get($endpoint, ['timeout' => self::FETCH_TIMEOUT]);

        if (is_wp_error($response)) {
            $this->logger->warning(
                sprintf('Provider embed fetch failed for "%s": %s', $url, $response->get_error_message())
            );

            return null;
        }

        $status = wp_remote_retrieve_response_code($response);

        if (200 > $status || 299 < $status) {
            $this->logger->warning(sprintf('Provider embed fetch returned HTTP %d for "%s".', $status, $url));

            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $html = is_array($decoded) ? (string) ($decoded['html'] ?? '') : '';

        if ('' === $html || ! $this->looksLikeAnIframeEmbed($html, $provider)) {
            $this->logger->warning(sprintf('Provider embed response for "%s" did not look like a valid embed.', $url));

            return null;
        }

        return $this->clampSize($html);
    }

    private function looksLikeAnIframeEmbed(string $html, string $provider): bool
    {
        return str_contains($html, '<iframe') && str_contains($html, self::EXPECTED_HOSTS[$provider]);
    }

    /**
     * Providers vary in how compact their default embed is (Spotify's
     * default is far taller than its own compact layout, for instance) -
     * requesting maxheight is only a hint some providers may not honor, so
     * this clamps the returned iframe's own height/width attributes
     * regardless, guaranteeing a compact, responsive result either way.
     */
    private function clampSize(string $html): string
    {
        $html = (string) preg_replace('/height="\d+"/', 'height="' . self::COMPACT_HEIGHT . '"', $html, 1);
        $html = (string) preg_replace('/width="\d+"/', 'width="100%"', $html, 1);

        return $html;
    }
}
