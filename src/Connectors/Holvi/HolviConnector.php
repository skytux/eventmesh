<?php

declare(strict_types=1);

namespace EventMesh\Connectors\Holvi;

use EventMesh\Contracts\ConnectorInterface;
use EventMesh\Models\Event;
use EventMesh\Services\HolviSourceManager;
use EventMesh\Support\Logger;

final class HolviConnector implements ConnectorInterface
{
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

    /**
     * @return array<int, Event>
     */
    public function fetch(): array
    {
        $events = [];

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

        return $events;
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
