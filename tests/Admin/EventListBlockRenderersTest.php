<?php

declare(strict_types=1);

namespace EventMesh\Tests\Admin;

use Brain\Monkey\Functions;
use EventMesh\Admin\EventListBlock;
use EventMesh\Connectors\Holvi\HolviHtmlParser;
use EventMesh\Content\EventQuery;
use EventMesh\Tests\TestCase;

final class EventListBlockRenderersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('__')->returnArg(1);
        Functions\when('esc_url')->alias(static fn ($value) => $value);
        Functions\when('esc_html')->alias(static fn ($value) => $value);
        Functions\when('get_block_wrapper_attributes')->alias(
            static fn (array $extra = []) => 'class="wp-block-eventmesh-test"'
                . implode('', array_map(static fn ($k, $v) => sprintf(' %s="%s"', $k, $v), array_keys($extra), $extra))
        );
        Functions\when('date_i18n')->alias(static fn (string $format, int $timestamp) => gmdate($format, $timestamp));
        Functions\when('wp_kses')->returnArg(1);
        Functions\when('wp_enqueue_block_style')->justReturn(null);
    }

    private function block(): EventListBlock
    {
        return new EventListBlock(new EventQuery(), new HolviHtmlParser());
    }

    /**
     * @param array<string, mixed> $context
     */
    private function blockInstance(array $context): object
    {
        return new class ($context) {
            /**
             * @param array<string, mixed> $context
             */
            public function __construct(public array $context)
            {
            }
        };
    }

    public function testRenderEventFieldStartsAtRendersTheFormattedDate(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.test/events/some-band/');
        Functions\when('get_the_title')->justReturn('Some Band 12.8.2026');
        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key) {
                return match ($key) {
                    '_eventmesh_starts_at' => '2026-06-13T18:00:00+00:00',
                    '_eventmesh_starts_at_year_known' => '1',
                    default => '',
                };
            }
        );

        $html = $this->block()->renderEventField(['field' => 'starts_at'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('Sat 13 June 2026 18:00', $html);
        self::assertStringStartsWith('<p ', $html);
        self::assertStringNotContainsString('text-decoration:line-through', $html);
    }

    public function testRenderEventFieldStartsAtIsStruckThroughWhenTitleContainsCanceled(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.test/events/some-band/');
        Functions\when('get_the_title')->justReturn('Some Band 12.8.2026 CANCELED');
        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key) {
                return match ($key) {
                    '_eventmesh_starts_at' => '2026-06-13T18:00:00+00:00',
                    '_eventmesh_starts_at_year_known' => '1',
                    default => '',
                };
            }
        );

        $html = $this->block()->renderEventField(['field' => 'starts_at'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('text-decoration:line-through', $html);
    }

    public function testRenderEventFieldStartsAtReturnsEmptyStringWithoutAStartDate(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $html = $this->block()->renderEventField(['field' => 'starts_at'], '', $this->blockInstance(['postId' => 42]));

        self::assertSame('', $html);
    }

    public function testRenderEventFieldTitleStripsTheDateAndLinksToThePost(): void
    {
        Functions\when('get_the_title')->justReturn('Some Band 12.8.2026');
        Functions\when('get_permalink')->justReturn('https://example.test/events/some-band/');
        Functions\when('get_post_meta')->justReturn('');

        $html = $this->block()->renderEventField(['field' => 'title'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('Some Band', $html);
        self::assertStringNotContainsString('12.8.2026', $html);
        self::assertStringContainsString('https://example.test/events/some-band/', $html);
        self::assertStringStartsWith('<h4 ', $html);
    }

    public function testRenderEventFieldTitleIsNotStruckThroughWhenMerelySoldOut(): void
    {
        Functions\when('get_the_title')->justReturn('Some Band 12.8.2026');
        Functions\when('get_permalink')->justReturn('https://example.test/events/some-band/');
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key) => '_eventmesh_sold_out' === $key ? '1' : ''
        );

        $html = $this->block()->renderEventField(['field' => 'title'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringNotContainsString(
            'text-decoration:line-through',
            $html,
            'Sold out no longer implies canceled - striking it through would suggest the event was canceled.'
        );
    }

    public function testRenderEventFieldTitleIsStruckThroughWhenTitleContainsCanceled(): void
    {
        Functions\when('get_the_title')->justReturn('Some Band 12.8.2026 CANCELED');
        Functions\when('get_permalink')->justReturn('https://example.test/events/some-band/');
        Functions\when('get_post_meta')->justReturn('');

        $html = $this->block()->renderEventField(['field' => 'title'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('text-decoration:line-through', $html);
        self::assertStringContainsString('CANCELED', $html, 'The keyword must stay visible, not be stripped.');
    }

    public function testRenderEventFieldTitleRendersAnUnlinkedHeadingTagWhenConfigured(): void
    {
        Functions\when('get_the_title')->justReturn('Some Band 12.8.2026');
        Functions\when('get_post_meta')->justReturn('');

        $html = $this->block()->renderEventField(
            ['field' => 'title', 'tag' => 'h1', 'linked' => false],
            '',
            $this->blockInstance(['postId' => 42])
        );

        self::assertStringStartsWith('<h1 ', $html);
        self::assertStringEndsWith('</h1>', $html);
        self::assertStringNotContainsString('<a ', $html);
        self::assertStringContainsString('Some Band', $html);
    }

    public function testRenderEventFieldTitleIsNotStruckThroughForAnOrdinaryTitle(): void
    {
        Functions\when('get_the_title')->justReturn('Some Band 12.8.2026');
        Functions\when('get_permalink')->justReturn('https://example.test/events/some-band/');
        Functions\when('get_post_meta')->justReturn('');

        $html = $this->block()->renderEventField(['field' => 'title'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringNotContainsString('text-decoration:line-through', $html);
    }

    public function testRenderEventFieldVenueRendersTheVenueName(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.test/events/some-band/');
        Functions\when('get_post_meta')->justReturn('The Basement Club');

        $html = $this->block()->renderEventField(['field' => 'venue'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('The Basement Club', $html);
    }

    public function testRenderEventFieldVenueReturnsEmptyStringWhenThereIsNoVenue(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $html = $this->block()->renderEventField(['field' => 'venue'], '', $this->blockInstance(['postId' => 42]));

        self::assertSame('', $html);
    }

    public function testRenderEventFieldVenueLinksToTheEventPageByDefault(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.test/events/some-band/');
        Functions\when('get_post_meta')->justReturn('The Basement Club');

        $html = $this->block()->renderEventField(['field' => 'venue'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('<a href="https://example.test/events/some-band/">', $html);
    }

    public function testRenderEventFieldVenueCanBeConfiguredAsAnH2Unlinked(): void
    {
        Functions\when('get_post_meta')->justReturn('The Basement Club');

        $html = $this->block()->renderEventField(
            ['field' => 'venue', 'tag' => 'h2', 'linked' => false],
            '',
            $this->blockInstance(['postId' => 42])
        );

        self::assertStringStartsWith('<h2 ', $html);
        self::assertStringEndsWith('</h2>', $html);
        self::assertStringNotContainsString('<a ', $html);
    }

    public function testRenderEventFieldReturnsEmptyStringWithoutAPostIdInContext(): void
    {
        $html = $this->block()->renderEventField(['field' => 'starts_at'], '', $this->blockInstance([]));

        self::assertSame('', $html);
    }

    public function testRenderTicketButtonUsesTheRealUrlFromPostMeta(): void
    {
        Functions\when('get_post_meta')->justReturn('https://holvi.com/shop/MiaRenwall/product/abc123/');

        $html = $this->block()->renderTicketButton([], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('href="https://holvi.com/shop/MiaRenwall/product/abc123/"', $html);
        self::assertStringContainsString('Tickets', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
        self::assertStringStartsWith('<a ', $html);
    }

    public function testRenderTicketButtonUsesACustomTextAttributeWhenSet(): void
    {
        Functions\when('get_post_meta')->justReturn('https://holvi.com/shop/MiaRenwall/product/abc123/');

        $html = $this->block()->renderTicketButton(['text' => 'Buy now'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('Buy now', $html);
    }

    public function testRenderTicketButtonShowsSoldOutLabelAndSecondaryStyleWhenSoldOut(): void
    {
        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key) {
                return match ($key) {
                    '_eventmesh_url' => 'https://holvi.com/shop/MiaRenwall/product/abc123/',
                    '_eventmesh_sold_out' => '1',
                    default => '',
                };
            }
        );

        $html = $this->block()->renderTicketButton(['text' => 'Buy now'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('Sold out', $html);
        self::assertStringNotContainsString('Buy now', $html, 'Sold-out status overrides any custom button label.');
        self::assertStringContainsString('eventmesh-ticket-button--secondary', $html);
        self::assertStringContainsString(
            'wp-block-button__link wp-element-button',
            $html,
            'The sold-out button must share the same base classes as the regular one so they are the same size and shape.'
        );
        self::assertStringContainsString(
            'href="https://holvi.com/shop/MiaRenwall/product/abc123/"',
            $html,
            'Sold-out events still link through to the store.'
        );
    }

    public function testRenderTicketButtonReturnsEmptyStringWithoutAUrl(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $html = $this->block()->renderTicketButton([], '', $this->blockInstance(['postId' => 42]));

        self::assertSame('', $html);
    }

    public function testRenderTicketButtonReturnsEmptyStringWithoutAPostIdInContext(): void
    {
        $html = $this->block()->renderTicketButton([], '', $this->blockInstance([]));

        self::assertSame('', $html);
    }

    public function testMarkEventRowStatusAddsThePastClassWhenTheEventHasAlreadyHappened(): void
    {
        Functions\when('get_post_meta')->justReturn('2000-01-01T00:00:00+00:00');

        $parsedBlock = ['blockName' => 'core/columns', 'attrs' => ['className' => 'eventmesh-event-row']];
        $content = '<div class="wp-block-columns eventmesh-event-row">stuff</div>';

        $result = $this->block()->markEventRowStatus($content, $parsedBlock, $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('eventmesh-event-past', $result);
    }

    public function testMarkEventRowStatusLeavesFutureEventsAlone(): void
    {
        Functions\when('get_post_meta')->justReturn('2999-01-01T00:00:00+00:00');

        $parsedBlock = ['blockName' => 'core/columns', 'attrs' => ['className' => 'eventmesh-event-row']];
        $content = '<div class="wp-block-columns eventmesh-event-row">stuff</div>';

        $result = $this->block()->markEventRowStatus($content, $parsedBlock, $this->blockInstance(['postId' => 42]));

        self::assertSame($content, $result);
    }

    public function testMarkEventRowStatusIgnoresBlocksWithoutTheEventRowClass(): void
    {
        $parsedBlock = ['blockName' => 'core/columns', 'attrs' => ['className' => 'something-else']];
        $content = '<div class="wp-block-columns something-else">stuff</div>';

        $result = $this->block()->markEventRowStatus($content, $parsedBlock, $this->blockInstance(['postId' => 42]));

        self::assertSame($content, $result);
    }

    public function testMarkEventRowStatusIgnoresUnrelatedBlockTypes(): void
    {
        $parsedBlock = ['blockName' => 'core/paragraph', 'attrs' => []];
        $content = '<p>stuff</p>';

        $result = $this->block()->markEventRowStatus($content, $parsedBlock, $this->blockInstance(['postId' => 42]));

        self::assertSame($content, $result);
    }

    public function testMarkEventRowStatusLeavesContentAloneWithoutAPostIdInContext(): void
    {
        $parsedBlock = ['blockName' => 'core/columns', 'attrs' => ['className' => 'eventmesh-event-row']];
        $content = '<div class="wp-block-columns eventmesh-event-row">stuff</div>';

        $result = $this->block()->markEventRowStatus($content, $parsedBlock, $this->blockInstance([]));

        self::assertSame($content, $result);
    }

    public function testMarkEventRowStatusAddsTheSoldOutClassForAnUpcomingSoldOutEvent(): void
    {
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key) => '_eventmesh_sold_out' === $key ? '1' : '2999-01-01T00:00:00+00:00'
        );

        $parsedBlock = ['blockName' => 'core/columns', 'attrs' => ['className' => 'eventmesh-event-row']];
        $content = '<div class="wp-block-columns eventmesh-event-row">stuff</div>';

        $result = $this->block()->markEventRowStatus($content, $parsedBlock, $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('eventmesh-sold-out-row', $result);
        self::assertStringNotContainsString('eventmesh-event-past', $result);
    }

    public function testMarkEventRowStatusAddsBothClassesForAPastSoldOutEvent(): void
    {
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key) => '_eventmesh_sold_out' === $key ? '1' : '2000-01-01T00:00:00+00:00'
        );

        $parsedBlock = ['blockName' => 'core/columns', 'attrs' => ['className' => 'eventmesh-event-row']];
        $content = '<div class="wp-block-columns eventmesh-event-row">stuff</div>';

        $result = $this->block()->markEventRowStatus($content, $parsedBlock, $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('eventmesh-sold-out-row', $result);
        self::assertStringContainsString('eventmesh-event-past', $result);
    }

    public function testRenderProviderEmbedRendersTheCachedHtml(): void
    {
        Functions\when('get_post_meta')->justReturn('<iframe src="https://open.spotify.com/embed/track/abc"></iframe>');

        $html = $this->block()->renderProviderEmbed([], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('<iframe src="https://open.spotify.com/embed/track/abc"></iframe>', $html);
        self::assertStringStartsWith('<div ', $html);
    }

    public function testRenderProviderEmbedReturnsEmptyStringWithoutACachedEmbed(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $html = $this->block()->renderProviderEmbed([], '', $this->blockInstance(['postId' => 42]));

        self::assertSame('', $html);
    }

    public function testRenderProviderEmbedReturnsEmptyStringWithoutAPostIdInContext(): void
    {
        $html = $this->block()->renderProviderEmbed([], '', $this->blockInstance([]));

        self::assertSame('', $html);
    }

    public function testRenderOtherProviderLinksListsLinksExceptTheEmbeddedOne(): void
    {
        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key = '', bool $single = false) {
                if ('_eventmesh_embed_source_url' === $key) {
                    return 'https://open.spotify.com/track/abc';
                }

                if ('' === $key) {
                    return [
                        '_eventmesh_provider_spotify' => ['https://open.spotify.com/track/abc'],
                        '_eventmesh_provider_youtube' => ['https://youtube.com/watch?v=xyz'],
                        '_eventmesh_provider_instagram' => [''],
                    ];
                }

                return '';
            }
        );

        $html = $this->block()->renderOtherProviderLinks([], '', $this->blockInstance(['postId' => 42]));

        self::assertStringNotContainsString(
            'open.spotify.com',
            $html,
            'The provider already shown as a compact embed must not also appear as a plain link.'
        );
        self::assertStringContainsString('youtube.com/watch?v=xyz', $html);
        self::assertStringContainsString('YouTube', $html);
        self::assertStringStartsWith('<ul ', $html);
    }

    public function testRenderOtherProviderLinksReturnsEmptyStringWhenNoOtherLinksExist(): void
    {
        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key = '', bool $single = false) {
                if ('' === $key) {
                    return ['_eventmesh_provider_spotify' => ['https://open.spotify.com/track/abc']];
                }

                return '_eventmesh_embed_source_url' === $key ? 'https://open.spotify.com/track/abc' : '';
            }
        );

        $html = $this->block()->renderOtherProviderLinks([], '', $this->blockInstance(['postId' => 42]));

        self::assertSame('', $html);
    }

    public function testRenderOtherProviderLinksReturnsEmptyStringWithoutAPostIdInContext(): void
    {
        $html = $this->block()->renderOtherProviderLinks([], '', $this->blockInstance([]));

        self::assertSame('', $html);
    }

    public function testRenderPastEventsMarkerRendersOnceForTheFirstPastEvent(): void
    {
        Functions\when('get_post_meta')->justReturn('2000-01-01T00:00:00+00:00');

        $block = $this->block();
        $first = $block->renderPastEventsMarker([], '', $this->blockInstance(['postId' => 1]));
        $second = $block->renderPastEventsMarker([], '', $this->blockInstance(['postId' => 2]));

        self::assertStringContainsString('Past Events', $first);
        self::assertStringStartsWith('<h3 ', $first);
        self::assertSame('', $second, 'Must only render once per loop, not for every subsequent past event.');
    }

    public function testRenderPastEventsMarkerReturnsEmptyStringForAnUpcomingEvent(): void
    {
        Functions\when('get_post_meta')->justReturn('2999-01-01T00:00:00+00:00');

        $html = $this->block()->renderPastEventsMarker([], '', $this->blockInstance(['postId' => 1]));

        self::assertSame('', $html);
    }

    public function testRenderPastEventsMarkerReturnsEmptyStringWithoutAPostIdInContext(): void
    {
        $html = $this->block()->renderPastEventsMarker([], '', $this->blockInstance([]));

        self::assertSame('', $html);
    }

    public function testRenderPastEventsMarkerRespectsCustomTextAndTag(): void
    {
        Functions\when('get_post_meta')->justReturn('2000-01-01T00:00:00+00:00');

        $html = $this->block()->renderPastEventsMarker(
            ['text' => 'Archive', 'tag' => 'h2'],
            '',
            $this->blockInstance(['postId' => 1])
        );

        self::assertStringStartsWith('<h2 ', $html);
        self::assertStringContainsString('Archive', $html);
    }

    public function testResetPastEventsMarkerAllowsTheMarkerToRenderAgainForANewLoop(): void
    {
        Functions\when('get_post_meta')->justReturn('2000-01-01T00:00:00+00:00');

        $block = $this->block();
        $block->renderPastEventsMarker([], '', $this->blockInstance(['postId' => 1]));

        $block->resetPastEventsMarker([]);

        $afterReset = $block->renderPastEventsMarker([], '', $this->blockInstance(['postId' => 2]));

        self::assertStringContainsString('Past Events', $afterReset);
    }
}
