<?php

declare(strict_types=1);

namespace EventMesh\Tests\Connectors\Holvi;

use Brain\Monkey\Functions;
use EventMesh\Connectors\Holvi\HolviHtmlParser;
use EventMesh\Tests\TestCase;

final class HolviHtmlParserTest extends TestCase
{
    private const SOURCE_URL = 'https://shop.holvi.com/venue/events';

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_parse_url')->alias('parse_url');
    }

    private function parser(): HolviHtmlParser
    {
        return new HolviHtmlParser();
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/fixtures/holvi/' . $name);

        self::assertIsString($contents);

        return $contents;
    }

    public function testParsesSingleJsonLdEvent(): void
    {
        $events = $this->parser()->parse($this->fixture('jsonld-single.html'), self::SOURCE_URL);

        self::assertCount(1, $events);

        $event = $events[0];

        self::assertSame('holvi', $event->sourceId());
        self::assertSame('Test Gig', $event->title());
        self::assertSame('https://shop.holvi.com/events/test-gig', $event->url());
        self::assertSame('https://shop.holvi.com/images/test-gig.jpg', $event->imageUrl());
        self::assertSame('A great show.', $event->description());
        self::assertSame('Test Venue', $event->venueName());
        self::assertNotNull($event->startsAt());
        self::assertSame('2026-08-01T20:00:00+03:00', $event->startsAt()->format('Y-m-d\TH:i:sP'));
        self::assertNotNull($event->endsAt());
        self::assertSame(
            hash('sha256', 'holvi:https://shop.holvi.com/events/test-gig'),
            $event->externalId()
        );
        self::assertSame(
            [
                'spotify' => 'https://open.spotify.com/artist/testartist',
                'facebook' => 'https://www.facebook.com/testgig',
            ],
            $event->providers()
        );
    }

    public function testParsesJsonLdGraphAndSkipsNonEventTypes(): void
    {
        $events = $this->parser()->parse($this->fixture('jsonld-graph.html'), self::SOURCE_URL);

        self::assertCount(1, $events);
        self::assertSame('Graph Event', $events[0]->title());
        self::assertSame('https://shop.holvi.com/events/graph-event', $events[0]->url());
    }

    public function testParsesTopLevelJsonLdList(): void
    {
        $events = $this->parser()->parse($this->fixture('jsonld-list.html'), self::SOURCE_URL);

        self::assertCount(1, $events);
        self::assertSame('List Event', $events[0]->title());
        self::assertSame('https://x.test/e1', $events[0]->url());
    }

    public function testJsonLdEventWithoutTitleIsSkipped(): void
    {
        $events = $this->parser()->parse($this->fixture('jsonld-missing-title.html'), self::SOURCE_URL);

        self::assertSame([], $events);
    }

    public function testInvalidDateBecomesNullInsteadOfFailing(): void
    {
        $events = $this->parser()->parse($this->fixture('jsonld-invalid-date.html'), self::SOURCE_URL);

        self::assertCount(1, $events);
        self::assertNull($events[0]->startsAt());
    }

    public function testFallsBackToMarkupWhenNoJsonLd(): void
    {
        $events = $this->parser()->parse($this->fixture('markup-fallback.html'), self::SOURCE_URL);

        self::assertCount(1, $events);

        $event = $events[0];

        self::assertSame('Markup Event', $event->title());
        self::assertSame('https://shop.holvi.com/e/markup-event', $event->url());
        self::assertSame('https://shop.holvi.com/img/markup.jpg', $event->imageUrl());
        self::assertSame('Desc here', $event->description());
        self::assertSame('Markup Venue', $event->venueName());
        self::assertNotNull($event->startsAt());
        self::assertSame('2026-10-01T18:00:00+00:00', $event->startsAt()->format('Y-m-d\TH:i:sP'));
    }

    public function testMarkupExtractsCssBackgroundImage(): void
    {
        $events = $this->parser()->parse($this->fixture('markup-background-image.html'), self::SOURCE_URL);

        self::assertCount(1, $events);
        self::assertSame('https://shop.holvi.com/img/bg.jpg', $events[0]->imageUrl());
    }

    public function testRealHolviListingImagePatternIsExtractedCorrectly(): void
    {
        $events = $this->parser()->parse($this->fixture('markup-real-holvi-listing-image.html'), self::SOURCE_URL);

        self::assertCount(1, $events);
        self::assertSame(
            'https://cdn.holvi.com/media/poolimage.image/2023/11/07/fe22f9681e6d3587cf9b622b4eeb184f322cc5ff_600x600_q85.jpg',
            $events[0]->imageUrl()
        );
        self::assertSame(
            'https://shop.holvi.com/shop/MiaRenwall/product/a133234a2d58e63329ae54712583469b/',
            $events[0]->url()
        );
    }

    public function testMalformedBackgroundImageStyleYieldsEmptyImageUrlInsteadOfGarbage(): void
    {
        $events = $this->parser()->parse($this->fixture('markup-malformed-image-url.html'), self::SOURCE_URL);

        self::assertCount(1, $events);
        self::assertSame(
            '',
            $events[0]->imageUrl(),
            'A style attribute with no real url(...) (e.g. only a gradient) must not be passed through as a fake image URL.'
        );
    }

    public function testMarkupElementWithoutTitleIsSkipped(): void
    {
        $events = $this->parser()->parse($this->fixture('markup-no-title.html'), self::SOURCE_URL);

        self::assertSame([], $events);
    }

    public function testMarkupItemWithNoDateAnywhereIsSkipped(): void
    {
        $events = $this->parser()->parse($this->fixture('markup-no-date-anywhere.html'), self::SOURCE_URL);

        self::assertSame(
            [],
            $events,
            'A product card with no <time> element and no date-like text in its title should not be treated as an event.'
        );
    }

    public function testMarkupItemWithDateOnlyInTitleExtractsDateButKeepsTitleIntact(): void
    {
        $events = $this->parser()->parse($this->fixture('markup-date-in-title-only.html'), self::SOURCE_URL);

        self::assertCount(1, $events);

        $event = $events[0];

        self::assertSame(
            'Some Band 12.8.2026',
            $event->title(),
            'The title is kept verbatim (with the date) so it stays identifiable in wp-admin.'
        );
        self::assertNotNull($event->startsAt());
        self::assertSame('2026-08-12', $event->startsAt()->format('Y-m-d'));
        self::assertTrue($event->startsAtYearKnown(), 'A year was explicitly present in the title.');
    }

    public function testMarkupItemWithDateInTitleButNoYearResolvesToNextOccurrence(): void
    {
        $events = $this->parser()->parse($this->fixture('markup-date-in-title-no-year.html'), self::SOURCE_URL);

        self::assertCount(1, $events);

        $event = $events[0];

        self::assertSame('Some Band 12.8.', $event->title());
        self::assertNotNull($event->startsAt());
        self::assertSame('08-12', $event->startsAt()->format('m-d'));
        self::assertFalse($event->startsAtYearKnown(), 'No year was present in the title.');
        self::assertGreaterThanOrEqual(
            (new \DateTimeImmutable('today')),
            $event->startsAt(),
            'A year-less date should resolve to the next upcoming occurrence, not a past one.'
        );
    }

    public function testMarkupItemWithDateRangeInTitleUsesTheStartDate(): void
    {
        $events = $this->parser()->parse($this->fixture('markup-date-range-in-title.html'), self::SOURCE_URL);

        self::assertCount(1, $events);

        $event = $events[0];

        self::assertSame('MUSH Weekend Retreat 27.6-28.6.2026', $event->title());
        self::assertNotNull($event->startsAt());
        self::assertSame('2026-06-27', $event->startsAt()->format('Y-m-d'), 'Should use the range start, not the end.');
        self::assertNotNull($event->endsAt());
        self::assertSame('2026-06-28', $event->endsAt()->format('Y-m-d'));
        self::assertTrue($event->startsAtYearKnown());
    }

    public function testStripDateForDisplayRemovesTheDateButKeepsTheStoredTitleUntouched(): void
    {
        $parser = $this->parser();

        self::assertSame('Some Band', $parser->stripDateForDisplay('Some Band 12.8.2026'));
        self::assertSame('Some Band', $parser->stripDateForDisplay('Some Band 12.8.'));
        self::assertSame(
            'MUSH Weekend Retreat',
            $parser->stripDateForDisplay('MUSH Weekend Retreat 27.6-28.6.2026')
        );
        self::assertSame(
            'No Date Here',
            $parser->stripDateForDisplay('No Date Here'),
            'A title with no recognizable date should be returned unchanged.'
        );
    }

    public function testVenueFallsBackToLabeledLineInDescriptionWhenNoStructuredLocation(): void
    {
        $events = $this->parser()->parse($this->fixture('markup-venue-labeled-in-description.html'), self::SOURCE_URL);

        self::assertCount(1, $events);
        self::assertSame('The Basement Club', $events[0]->venueName());
    }

    public function testParseDetailPageExtractsFullDescriptionDateAndTitle(): void
    {
        $event = $this->parser()->parseDetailPage(
            $this->fixture('detail-page.html'),
            'https://shop.holvi.com/shop/MiaRenwall/product/f72ca8c0f481c0ced1d1be5f0c96493b/'
        );

        self::assertNotNull($event);
        self::assertSame('Authentic Dating 26.5.2026', $event->title());
        self::assertNotNull($event->startsAt());
        self::assertSame('2026-05-26', $event->startsAt()->format('Y-m-d'));
        self::assertTrue($event->startsAtYearKnown());
        self::assertStringContainsString('Haluatko tutustua uusiin ihmisiin', $event->description());
        self::assertStringContainsString('Tuesday May 26th', $event->description());
    }

    public function testParseDetailPageExtractsImageFromTheAngularImageCarouselAttribute(): void
    {
        $event = $this->parser()->parseDetailPage(
            $this->fixture('detail-page.html'),
            'https://shop.holvi.com/shop/MiaRenwall/product/f72ca8c0f481c0ced1d1be5f0c96493b/'
        );

        self::assertNotNull($event);
        self::assertSame(
            'https://cdn.holvi.com/media/poolimage.image/2023/11/07/fe22f9681e6d3587cf9b622b4eeb184f322cc5ff.jpg',
            $event->imageUrl()
        );
    }

    public function testParseDetailPageExtractsTimeOfDayFromKloPatternInContent(): void
    {
        $event = $this->parser()->parseDetailPage(
            $this->fixture('detail-page.html'),
            'https://shop.holvi.com/shop/MiaRenwall/product/f72ca8c0f481c0ced1d1be5f0c96493b/'
        );

        self::assertNotNull($event);
        self::assertSame('18:00', $event->startsAt()->format('H:i'), '"klo 18:00-21:00" should set the start time.');
        self::assertNotNull($event->endsAt());
        self::assertSame('2026-05-26', $event->endsAt()->format('Y-m-d'), 'Same-day end time should keep the start date.');
        self::assertSame('21:00', $event->endsAt()->format('H:i'));
    }

    public function testParseDetailPageReturnsNullWithoutATitle(): void
    {
        $event = $this->parser()->parseDetailPage('<html><body><p>no title here</p></body></html>', self::SOURCE_URL);

        self::assertNull($event);
    }

    public function testBareTimeRangeWithoutKloPrefixIsAlsoDetected(): void
    {
        $event = $this->parser()->parseDetailPage(
            '<html><body><h1 itemprop="name">Bare Time Event 1.1.2030</h1>' .
            '<div itemprop="description"><p>Doors 19:30-23:00.</p></div></body></html>',
            'https://shop.holvi.com/e/bare-time/'
        );

        self::assertNotNull($event);
        self::assertSame('19:30', $event->startsAt()->format('H:i'));
        self::assertNotNull($event->endsAt());
        self::assertSame('23:00', $event->endsAt()->format('H:i'));
    }

    public function testMarkupDetectsASoldOutProductByItsHyphenatedClassName(): void
    {
        $events = $this->parser()->parse($this->fixture('markup-sold-out-listing.html'), self::SOURCE_URL);

        self::assertCount(1, $events);
        self::assertTrue($events[0]->soldOut());
    }

    public function testMarkupEventIsNotSoldOutByDefault(): void
    {
        $events = $this->parser()->parse($this->fixture('markup-fallback.html'), self::SOURCE_URL);

        self::assertCount(1, $events);
        self::assertFalse($events[0]->soldOut());
    }

    public function testParseDetailPageDetectsASoldOutProduct(): void
    {
        $event = $this->parser()->parseDetailPage(
            $this->fixture('detail-page-sold-out.html'),
            'https://shop.holvi.com/e/sold-out/'
        );

        self::assertNotNull($event);
        self::assertTrue($event->soldOut());
    }

    public function testParseDetailPageIsNotSoldOutByDefault(): void
    {
        $event = $this->parser()->parseDetailPage(
            $this->fixture('detail-page.html'),
            'https://shop.holvi.com/shop/MiaRenwall/product/f72ca8c0f481c0ced1d1be5f0c96493b/'
        );

        self::assertNotNull($event);
        self::assertFalse($event->soldOut());
    }

    public function testMarkupExtractsKnownProviderLinks(): void
    {
        $events = $this->parser()->parse($this->fixture('markup-with-provider-links.html'), self::SOURCE_URL);

        self::assertCount(1, $events);
        self::assertSame(
            [
                'spotify' => 'https://open.spotify.com/artist/abc123',
                'instagram' => 'https://www.instagram.com/providerband/',
            ],
            $events[0]->providers()
        );
    }

    public function testMarkupEventHasNoProvidersByDefault(): void
    {
        $events = $this->parser()->parse($this->fixture('markup-fallback.html'), self::SOURCE_URL);

        self::assertCount(1, $events);
        self::assertSame([], $events[0]->providers());
    }

    public function testParseDetailPageExtractsProviderLinksFromTheDescriptionOnly(): void
    {
        $event = $this->parser()->parseDetailPage(
            $this->fixture('detail-page-with-providers.html'),
            'https://shop.holvi.com/e/provider-band/'
        );

        self::assertNotNull($event);
        self::assertSame(
            [
                'mixcloud' => 'https://www.mixcloud.com/providerband/',
                'soundcloud' => 'https://soundcloud.com/providerband',
            ],
            $event->providers(),
            'A Facebook link in the page footer (Holvi\'s own share link, not the artist\'s) must not be picked up.'
        );
    }

    public function testEmptyHtmlYieldsNoEvents(): void
    {
        $events = $this->parser()->parse($this->fixture('empty.html'), self::SOURCE_URL);

        self::assertSame([], $events);
    }
}
