<?php

declare(strict_types=1);

namespace EventMesh\Connectors\Holvi;

use EventMesh\Contracts\ConnectorInterface;
use EventMesh\Models\Event;
use EventMesh\Services\HolviSourceManager;
use EventMesh\Support\Logger;

final class HolviConnector implements ConnectorInterface
{
    /**
     * Detail-page enrichment is a second HTTP round trip per event on top of
     * the listing fetch (and, for new events, a further image download)
     * within a single sync run. Left uncapped, a large event list can push a
     * single run past the host's execution-time limit, aborting mid-sync
     * before later events even get their featured image downloaded.
     *
     * Capped by wall-clock time rather than a fixed count, since hosts vary
     * wildly in per-request time limits (and some enforce it at the process
     * level regardless of PHP's own max_execution_time) - a time budget
     * degrades gracefully everywhere, where a fixed count could still be too
     * slow on a tightly-limited host. MAX_DETAIL_FETCHES_PER_RUN remains as a
     * hard backstop. A large backlog enriches gradually across multiple
     * scheduled runs instead of risking any single one.
     */
    private const MAX_DETAIL_FETCHES_PER_RUN = 15;
    private const DETAIL_FETCH_TIME_BUDGET_SECONDS = 20.0;
    private const DETAIL_FETCH_TIMEOUT = 10;

    /**
     * Persists a per-externalId "last enrichment attempt" timestamp so
     * priority across runs isn't just listing order: never-enriched events
     * go first, then the longest-stale ones - a source with more listings
     * than one run's budget still gets every event enriched within a
     * bounded number of runs, instead of the same leading events winning
     * every time and everything past the budget never being reached.
     */
    private const DETAIL_ENRICHED_AT_OPTION = 'eventmesh_holvi_detail_enriched_at';

    private int $fetchErrors = 0;

    public function __construct(
        private readonly HolviHtmlParser $parser,
        private readonly Logger $logger,
        private readonly HolviSourceManager $sourceManager
    ) {
    }

    public function id(): string
    {
        return 'holvi';
    }

    public function label(): string
    {
        return __('Holvi', 'eventmesh');
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    /**
     * @return array<int, Event>
     */
    public function fetch(): array
    {
        $events = [];
        $this->fetchErrors = 0;

        foreach ($this->sourceUrls() as $sourceUrl) {
            $response = wp_remote_get(
                $sourceUrl,
                [
                    'timeout' => 20,
                    'redirection' => 5,
                    'headers' => [
                        'Accept' => 'text/html',
                    ],
                ]
            );

            if (is_wp_error($response)) {
                $this->logger->warning(
                    sprintf(
                        'Holvi fetch failed for "%s": %s',
                        $sourceUrl,
                        $response->get_error_message()
                    )
                );
                ++$this->fetchErrors;
                continue;
            }

            $status = wp_remote_retrieve_response_code($response);

            if (200 > $status || 299 < $status) {
                $this->logger->warning(
                    sprintf(
                        'Holvi fetch returned HTTP %d for "%s".',
                        $status,
                        $sourceUrl
                    )
                );
                ++$this->fetchErrors;
                continue;
            }

            $events = array_merge(
                $events,
                $this->parser->parse(
                    (string) wp_remote_retrieve_body($response),
                    $sourceUrl
                )
            );
        }

        return $this->enrichEvents($events);
    }

    public function fetchErrors(): int
    {
        return $this->fetchErrors;
    }

    /**
     * @param array<int, Event> $events
     *
     * @return array<int, Event>
     */
    private function enrichEvents(array $events): array
    {
        $enrichedAt = get_option(self::DETAIL_ENRICHED_AT_OPTION, []);

        if (! is_array($enrichedAt)) {
            $enrichedAt = [];
        }

        $indices = array_keys($events);

        usort(
            $indices,
            static function (int $a, int $b) use ($events, $enrichedAt): int {
                $timeA = (int) ($enrichedAt[$events[$a]->externalId()] ?? 0);
                $timeB = (int) ($enrichedAt[$events[$b]->externalId()] ?? 0);

                return $timeA <=> $timeB;
            }
        );

        $detailFetchesRemaining = self::MAX_DETAIL_FETCHES_PER_RUN;
        $deadline = microtime(true) + self::DETAIL_FETCH_TIME_BUDGET_SECONDS;

        foreach ($indices as $index) {
            $event = $events[$index];

            if ('' === $event->url()) {
                continue;
            }

            if ($detailFetchesRemaining <= 0 || microtime(true) >= $deadline) {
                break;
            }

            --$detailFetchesRemaining;
            $events[$index] = $this->enrichWithDetailPage($event);
            $enrichedAt[$event->externalId()] = time();
        }

        $currentExternalIds = array_map(
            static fn (Event $event): string => $event->externalId(),
            $events
        );

        update_option(
            self::DETAIL_ENRICHED_AT_OPTION,
            array_intersect_key($enrichedAt, array_flip($currentExternalIds))
        );

        return $events;
    }

    /**
     * Holvi's listing pages only expose an excerpt per event; the full
     * description (and often the only place a real venue/date is written,
     * buried in prose) lives on the event's own detail page. Fetch it and
     * prefer that richer parse - falling back to the listing-page version
     * on any failure, since this is enrichment, not discovery, and must
     * never cost us an event we already found.
     */
    private function enrichWithDetailPage(Event $event): Event
    {
        $response = wp_remote_get(
            $event->url(),
            [
                'timeout' => self::DETAIL_FETCH_TIMEOUT,
                'redirection' => 5,
                'headers' => [
                    'Accept' => 'text/html',
                ],
            ]
        );

        if (is_wp_error($response)) {
            $this->logger->warning(
                sprintf(
                    'Holvi detail page fetch failed for "%s": %s',
                    $event->url(),
                    $response->get_error_message()
                )
            );

            return $event;
        }

        $status = wp_remote_retrieve_response_code($response);

        if (200 > $status || 299 < $status) {
            $this->logger->warning(
                sprintf(
                    'Holvi detail page fetch returned HTTP %d for "%s".',
                    $status,
                    $event->url()
                )
            );

            return $event;
        }

        $detailed = $this->parser->parseDetailPage(
            (string) wp_remote_retrieve_body($response),
            $event->url()
        );

        if (null === $detailed) {
            return $event;
        }

        // Merge field-by-field rather than replacing outright: the detail
        // page is usually richer, but must never regress a field the
        // listing page already had. externalId/url are kept from the
        // original event so post identity never shifts between syncs.
        // imageUrl is deliberately always kept from the listing page: it's
        // already an appropriately-sized thumbnail, where the detail page's
        // image is the full-size original - not worth the extra bandwidth
        // for a featured image.
        return new Event(
            sourceId: $event->sourceId(),
            externalId: $event->externalId(),
            title: '' !== $detailed->title() ? $detailed->title() : $event->title(),
            startsAt: $detailed->startsAt() ?? $event->startsAt(),
            endsAt: $detailed->endsAt() ?? $event->endsAt(),
            url: $event->url(),
            description: '' !== $detailed->description() ? $detailed->description() : $event->description(),
            imageUrl: $event->imageUrl(),
            venueName: '' !== $detailed->venueName() ? $detailed->venueName() : $event->venueName(),
            startsAtYearKnown: null !== $detailed->startsAt()
                ? $detailed->startsAtYearKnown()
                : $event->startsAtYearKnown(),
            soldOut: $event->soldOut() || $detailed->soldOut(),
            providers: array_merge($event->providers(), $detailed->providers()),
            price: '' !== $detailed->price() ? $detailed->price() : $event->price()
        );
    }

    /**
     * @return array<int, string>
     */
    private function sourceUrls(): array
    {
        $urls = $this->sourceManager->enabledUrls();

        if ([] === $urls) {
            $legacyUrls = get_option('eventmesh_holvi_source_urls', []);

            if (is_string($legacyUrls)) {
                $legacyUrls = preg_split('/\r\n|\r|\n/', $legacyUrls) ?: [];
            }

            if (! is_array($legacyUrls)) {
                $legacyUrls = [];
            }

            $urls = array_values(
                array_filter(
                    array_map(
                        static fn (mixed $url): string => esc_url_raw((string) $url),
                        $legacyUrls
                    )
                )
            );
        }

        /**
         * Filters the configured Holvi source URLs.
         *
         * @param array<int, string> $urls Holvi source URLs.
         */
        $filtered = apply_filters('eventmesh/holvi/source_urls', $urls);

        if (! is_array($filtered)) {
            return $urls;
        }

        return array_values(
            array_filter(
                array_map(
                    static fn (mixed $url): string => esc_url_raw((string) $url),
                    $filtered
                )
            )
        );
    }
}
