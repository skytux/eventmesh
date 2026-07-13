<?php

declare(strict_types=1);

namespace EventMesh\Tests\Connectors\Holvi;

use Brain\Monkey\Functions;
use EventMesh\Connectors\Holvi\HolviConnector;
use EventMesh\Connectors\Holvi\HolviHtmlParser;
use EventMesh\Services\HolviSourceManager;
use EventMesh\Support\Logger;
use EventMesh\Tests\TestCase;

final class HolviConnectorTest extends TestCase
{
    private const LISTING_URL = 'https://shop.holvi.com/venue/events';
    private const DETAIL_URL = 'https://shop.holvi.com/shop/MiaRenwall/product/f72ca8c0f481c0ced1d1be5f0c96493b/';

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('__')->returnArg(1);
        Functions\when('esc_url_raw')->alias(static fn ($value) => $value);
        Functions\when('apply_filters')->alias(static fn ($tag, $value) => $value);
        Functions\when('wp_parse_url')->alias('parse_url');
        Functions\when('update_option')->justReturn(true);
        Functions\when('is_wp_error')->alias(static fn ($thing) => $thing instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_body')->alias(static fn ($response) => $response['__body'] ?? '');
        Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn ($response) => $response['__status'] ?? 200
        );

        Functions\when('get_option')->justReturn(
            [
                ['id' => 'main', 'url' => self::LISTING_URL, 'enabled' => true],
            ]
        );
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/fixtures/holvi/' . $name);
        self::assertIsString($contents);

        return $contents;
    }

    private function connector(): HolviConnector
    {
        return new HolviConnector(new HolviHtmlParser(), new Logger(), new HolviSourceManager());
    }

    public function testFetchEnrichesEventsFromTheirDetailPage(): void
    {
        $listingBody = $this->fixture('listing-with-detail-link.html');
        $detailBody = $this->fixture('detail-page.html');

        Functions\when('wp_remote_get')->alias(
            function (string $url) use ($listingBody, $detailBody) {
                return match ($url) {
                    self::LISTING_URL => ['__body' => $listingBody],
                    self::DETAIL_URL => ['__body' => $detailBody],
                    default => new \WP_Error('unexpected', 'Unexpected URL: ' . $url),
                };
            }
        );

        $connector = $this->connector();
        $events = $connector->fetch();

        self::assertCount(1, $events);

        $event = $events[0];

        self::assertSame('Authentic Dating 26.5.2026', $event->title());
        self::assertStringContainsString('Haluatko tutustua', $event->description());
        self::assertSame(self::DETAIL_URL, $event->url());
        self::assertStringContainsString(
            '39.00',
            $event->price(),
            'The detail-page price must survive the enrichment merge, not be dropped.'
        );
        self::assertSame(0, $connector->fetchErrors());
    }

    public function testFetchKeepsTheListingPageThumbnailInsteadOfTheDetailPageImage(): void
    {
        $listingBody = $this->fixture('listing-with-detail-link.html');
        $detailBody = $this->fixture('detail-page.html');

        Functions\when('wp_remote_get')->alias(
            function (string $url) use ($listingBody, $detailBody) {
                return match ($url) {
                    self::LISTING_URL => ['__body' => $listingBody],
                    self::DETAIL_URL => ['__body' => $detailBody],
                    default => new \WP_Error('unexpected', 'Unexpected URL: ' . $url),
                };
            }
        );

        $events = $this->connector()->fetch();

        self::assertCount(1, $events);
        self::assertSame(
            'https://shop.holvi.com/img/listing-thumb.jpg',
            $events[0]->imageUrl(),
            'The listing thumbnail is already appropriately sized; the detail page image is the full-size original ' .
            'and should never override it.'
        );
    }

    public function testDetailPageFailureFallsBackToListingDataWithoutCountingAsAFetchError(): void
    {
        $listingBody = $this->fixture('listing-with-detail-link.html');

        Functions\when('wp_remote_get')->alias(
            function (string $url) use ($listingBody) {
                return match ($url) {
                    self::LISTING_URL => ['__body' => $listingBody],
                    self::DETAIL_URL => new \WP_Error('timeout', 'Detail page timed out'),
                    default => new \WP_Error('unexpected', 'Unexpected URL: ' . $url),
                };
            }
        );

        $connector = $this->connector();
        $events = $connector->fetch();

        self::assertCount(1, $events);
        self::assertSame('Authentic Dating 26.5.2026', $events[0]->title());
        self::assertSame('Short excerpt only.', $events[0]->description());
        self::assertSame(0, $connector->fetchErrors(), 'A failed detail-page enrichment must not gate stale-event pruning.');
    }

    public function testListingPageFailureIncrementsFetchErrors(): void
    {
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('timeout', 'Listing page timed out'));

        $connector = $this->connector();
        $events = $connector->fetch();

        self::assertSame([], $events);
        self::assertSame(1, $connector->fetchErrors());
    }

    public function testDetailPageEnrichmentIsCappedPerRunToAvoidTimeouts(): void
    {
        $itemCount = 20;
        $items = '';

        for ($i = 1; $i <= $itemCount; ++$i) {
            $items .= sprintf(
                '<div class="product"><h2 itemprop="name">Band %1$d 1.1.2030</h2><a href="/product/%1$d/">Details</a></div>',
                $i
            );
        }

        $listingBody = '<!DOCTYPE html><html><body>' . $items . '</body></html>';
        $detailFetchCount = 0;

        Functions\when('wp_remote_get')->alias(
            function (string $url) use ($listingBody, &$detailFetchCount) {
                if (self::LISTING_URL === $url) {
                    return ['__body' => $listingBody];
                }

                ++$detailFetchCount;

                return new \WP_Error('unreached', 'not needed for this test');
            }
        );

        $events = $this->connector()->fetch();

        self::assertCount($itemCount, $events, 'All discovered events should still be returned, even unenriched ones.');
        self::assertSame(15, $detailFetchCount, 'Only MAX_DETAIL_FETCHES_PER_RUN detail pages should be fetched in a single run.');
    }

    public function testDetailPageEnrichmentPrioritizesEventsNotYetEnrichedOverPreviousRuns(): void
    {
        $itemCount = 20;
        $items = '';

        for ($i = 1; $i <= $itemCount; ++$i) {
            $items .= sprintf(
                '<div class="product"><h2 itemprop="name">Band %1$d 1.1.2030</h2><a href="/product/%1$d/">Details</a></div>',
                $i
            );
        }

        $listingBody = '<!DOCTYPE html><html><body>' . $items . '</body></html>';
        $fetchedDetailUrls = [];
        $storedEnrichedAt = [];

        Functions\when('get_option')->alias(
            function (string $name, $default = false) use (&$storedEnrichedAt) {
                if ('eventmesh_holvi_detail_enriched_at' === $name) {
                    return $storedEnrichedAt;
                }

                return [['id' => 'main', 'url' => self::LISTING_URL, 'enabled' => true]];
            }
        );
        Functions\when('update_option')->alias(
            function (string $name, $value) use (&$storedEnrichedAt) {
                if ('eventmesh_holvi_detail_enriched_at' === $name) {
                    $storedEnrichedAt = $value;
                }

                return true;
            }
        );

        Functions\when('wp_remote_get')->alias(
            function (string $url) use ($listingBody, &$fetchedDetailUrls) {
                if (self::LISTING_URL === $url) {
                    return ['__body' => $listingBody];
                }

                $fetchedDetailUrls[] = $url;

                return new \WP_Error('unreached', 'not needed for this test');
            }
        );

        // First run: no prior enrichment history, so the first 15 (by
        // listing order, since all are equally "never enriched") get
        // attempted, leaving events 16-20 untouched.
        $this->connector()->fetch();

        self::assertCount(15, $fetchedDetailUrls);
        self::assertContains('https://shop.holvi.com/product/1/', $fetchedDetailUrls);
        self::assertNotContains('https://shop.holvi.com/product/16/', $fetchedDetailUrls);

        // Second run: events 1-15 now have a recent timestamp, so events
        // 16-20 (never enriched) must be prioritized first this time -
        // proving the budget doesn't starve the same trailing events forever.
        $fetchedDetailUrls = [];
        $this->connector()->fetch();

        foreach (range(16, 20) as $i) {
            self::assertContains(
                "https://shop.holvi.com/product/{$i}/",
                $fetchedDetailUrls,
                "Event {$i}, never enriched in run 1, must be prioritized in run 2."
            );
        }
    }
}
