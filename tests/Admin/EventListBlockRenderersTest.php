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
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_url')->alias(static fn ($value) => $value);
        Functions\when('esc_html')->alias(static fn ($value) => $value);
        Functions\when('get_block_wrapper_attributes')->alias(
            static fn (array $extra = []) => 'class="wp-block-eventmesh-test"'
                . implode('', array_map(static fn ($k, $v) => sprintf(' %s="%s"', $k, $v), array_keys($extra), $extra))
        );
        Functions\when('date_i18n')->alias(static fn (string $format, int $timestamp) => gmdate($format, $timestamp));
        Functions\when('wp_kses')->returnArg(1);
        // Real function names/signatures (matching WordPress core) so a
        // wrong-arity call would surface here as a MissingFunctionExpectations
        // error, the way the live ArgumentCountError fatal did - see the
        // dedicated enqueue test below.
        Functions\when('wp_style_is')->justReturn(true);
        Functions\when('wp_enqueue_style')->justReturn(true);
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

    public function testRenderEventFieldTitleStripsTheDateAndDefaultsToAnUnlinkedParagraph(): void
    {
        Functions\when('get_the_title')->justReturn('Some Band 12.8.2026');
        Functions\when('get_permalink')->justReturn('https://example.test/events/some-band/');
        Functions\when('get_post_meta')->justReturn('');

        $html = $this->block()->renderEventField(['field' => 'title'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('Some Band', $html);
        self::assertStringNotContainsString('12.8.2026', $html);
        self::assertStringStartsWith('<p ', $html, 'Fields default to a paragraph, not a heading.');
        self::assertStringNotContainsString('<a ', $html, 'Nothing links by default; the link is an opt-in toggle.');
    }

    public function testRenderEventFieldTitleLinksWhenLinkedIsTurnedOn(): void
    {
        Functions\when('get_the_title')->justReturn('Some Band 12.8.2026');
        Functions\when('get_permalink')->justReturn('https://example.test/events/some-band/');
        Functions\when('get_post_meta')->justReturn('');

        $html = $this->block()->renderEventField(
            ['field' => 'title', 'linked' => true],
            '',
            $this->blockInstance(['postId' => 42])
        );

        self::assertStringContainsString('https://example.test/events/some-band/', $html);
        self::assertStringContainsString('<a ', $html);
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

    public function testRenderEventFieldVenueDoesNotLinkByDefault(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.test/events/some-band/');
        Functions\when('get_post_meta')->justReturn('The Basement Club');

        $html = $this->block()->renderEventField(['field' => 'venue'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringStartsWith('<p ', $html);
        self::assertStringNotContainsString('<a ', $html, 'The venue is unlinked by default.');
        self::assertStringContainsString('The Basement Club', $html);
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

    public function testRenderEventFieldRendersAnEditorSetPrefixBeforeTheValue(): void
    {
        Functions\when('get_post_meta')->justReturn('The Basement Club');

        $html = $this->block()->renderEventField(
            ['field' => 'venue', 'prefix' => 'at '],
            '',
            $this->blockInstance(['postId' => 42])
        );

        self::assertStringContainsString('at The Basement Club', $html);
    }

    public function testRenderEventFieldPrefixSitsOutsideTheLink(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.test/events/some-band/');
        Functions\when('get_post_meta')->justReturn('The Basement Club');

        $html = $this->block()->renderEventField(
            ['field' => 'venue', 'prefix' => 'at ', 'linked' => true],
            '',
            $this->blockInstance(['postId' => 42])
        );

        self::assertStringContainsString('at <a ', $html, 'The prefix is plain text before the link, not part of it.');
    }

    public function testRenderEventFieldPrefixIsHiddenWhenTheValueIsEmpty(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $html = $this->block()->renderEventField(
            ['field' => 'venue', 'prefix' => 'at '],
            '',
            $this->blockInstance(['postId' => 42])
        );

        self::assertSame('', $html, 'With no venue there is no value, so the prefix must not appear on its own.');
    }

    public function testRenderEventFieldReturnsEmptyStringWithoutAPostIdInContext(): void
    {
        $html = $this->block()->renderEventField(['field' => 'starts_at'], '', $this->blockInstance([]));

        self::assertSame('', $html);
    }

    public function testRenderEventFieldPriceRendersThePriceUnlinked(): void
    {
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key) => '_eventmesh_price' === $key ? '€15' : ''
        );

        $html = $this->block()->renderEventField(['field' => 'price'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('€15', $html);
        self::assertStringStartsWith('<p ', $html);
        self::assertStringNotContainsString('<a ', $html);
    }

    public function testRenderEventFieldPriceReturnsEmptyStringWhenThereIsNoPrice(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $html = $this->block()->renderEventField(['field' => 'price'], '', $this->blockInstance(['postId' => 42]));

        self::assertSame('', $html);
    }

    /**
     * Meta for an event that starts 13 June 2026 18:00 and ends the next day
     * at 21:00, so every granular date/time field has something to show.
     */
    private function twoDayTimedEventMeta(): void
    {
        Functions\when('get_the_title')->justReturn('Some Band');
        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key) {
                return match ($key) {
                    '_eventmesh_starts_at' => '2026-06-13T18:00:00+00:00',
                    '_eventmesh_ends_at' => '2026-06-14T21:00:00+00:00',
                    '_eventmesh_starts_at_year_known' => '1',
                    default => '',
                };
            }
        );
    }

    public function testRenderEventFieldStartDateShowsTheDateWithoutTime(): void
    {
        $this->twoDayTimedEventMeta();

        $html = $this->block()->renderEventField(['field' => 'start_date'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('Sat 13 June 2026', $html);
        self::assertStringNotContainsString('18:00', $html);
    }

    public function testRenderEventFieldEndDateShowsTheEndDate(): void
    {
        $this->twoDayTimedEventMeta();

        $html = $this->block()->renderEventField(['field' => 'end_date'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('Sun 14 June 2026', $html);
    }

    public function testRenderEventFieldDateRangeSpansBothDays(): void
    {
        $this->twoDayTimedEventMeta();

        $html = $this->block()->renderEventField(['field' => 'date_range'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('Sat 13 June 2026 - Sun 14 June 2026', $html);
    }

    public function testRenderEventFieldStartTimeShowsOnlyTheTime(): void
    {
        $this->twoDayTimedEventMeta();

        $html = $this->block()->renderEventField(['field' => 'start_time'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('18:00', $html);
        self::assertStringNotContainsString('June', $html);
    }

    public function testRenderEventFieldTimeRangeShowsStartAndEndTimes(): void
    {
        $this->twoDayTimedEventMeta();

        $html = $this->block()->renderEventField(['field' => 'time_range'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('18:00 - 21:00', $html);
    }

    public function testRenderEventFieldTimeFieldsAreEmptyForADateOnlyEvent(): void
    {
        // Midnight start, no end: no time-of-day is known.
        Functions\when('get_the_title')->justReturn('Some Band');
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key) => '_eventmesh_starts_at' === $key ? '2026-06-13T00:00:00+00:00' : ''
        );

        $block = $this->block();

        self::assertSame(
            '',
            $block->renderEventField(['field' => 'start_time'], '', $this->blockInstance(['postId' => 42]))
        );
        self::assertSame(
            '',
            $block->renderEventField(['field' => 'end_time'], '', $this->blockInstance(['postId' => 42]))
        );
        self::assertSame(
            '',
            $block->renderEventField(['field' => 'time_range'], '', $this->blockInstance(['postId' => 42]))
        );
    }

    public function testRenderEventFieldDateFieldIsStruckThroughWhenCanceled(): void
    {
        Functions\when('get_the_title')->justReturn('Some Band CANCELED');
        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key) {
                return match ($key) {
                    '_eventmesh_starts_at' => '2026-06-13T18:00:00+00:00',
                    '_eventmesh_starts_at_year_known' => '1',
                    default => '',
                };
            }
        );

        $html = $this->block()->renderEventField(['field' => 'start_date'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('text-decoration:line-through', $html);
    }

    /**
     * Ticket-button meta with only a URL set: not past, not sold out, no
     * price - the plain "buyable now" case most of these tests want.
     */
    private function ticketUrlOnlyMeta(string $url = 'https://holvi.com/shop/MiaRenwall/product/abc123/'): void
    {
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key) => '_eventmesh_url' === $key ? $url : ''
        );
    }

    public function testRenderTicketButtonUsesTheRealUrlFromPostMeta(): void
    {
        $this->ticketUrlOnlyMeta();

        $html = $this->block()->renderTicketButton([], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('href="https://holvi.com/shop/MiaRenwall/product/abc123/"', $html);
        self::assertStringContainsString('Tickets', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
        self::assertStringStartsWith('<a ', $html);
    }

    public function testRenderTicketButtonUsesTheEventPriceAsTheLabelWhenAvailable(): void
    {
        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key) {
                return match ($key) {
                    '_eventmesh_url' => 'https://holvi.com/shop/MiaRenwall/product/abc123/',
                    '_eventmesh_price' => '€15',
                    default => '',
                };
            }
        );

        $html = $this->block()->renderTicketButton([], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('€15', $html);
        self::assertStringNotContainsString('Tickets', $html, 'The real price replaces the generic "Tickets" label.');
    }

    public function testRenderTicketButtonUsesACustomTextAttributeWhenSetAndNoPrice(): void
    {
        $this->ticketUrlOnlyMeta();

        $html = $this->block()->renderTicketButton(['text' => 'Buy now'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('Buy now', $html);
    }

    public function testRenderTicketButtonIsHiddenForPastEvents(): void
    {
        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key) {
                return match ($key) {
                    '_eventmesh_url' => 'https://holvi.com/shop/MiaRenwall/product/abc123/',
                    '_eventmesh_starts_at' => '2000-01-01T00:00:00+00:00',
                    default => '',
                };
            }
        );

        $html = $this->block()->renderTicketButton([], '', $this->blockInstance(['postId' => 42]));

        self::assertSame('', $html, 'A past event can no longer sell tickets, so the button is dropped entirely.');
    }

    public function testRenderTicketButtonEnqueuesTheCoreButtonStylesheetByHandle(): void
    {
        $this->ticketUrlOnlyMeta();

        $registeredCheck = null;
        $enqueued = null;
        Functions\when('wp_style_is')->alias(
            static function (string $handle, string $status) use (&$registeredCheck): bool {
                $registeredCheck = [$handle, $status];

                return true;
            }
        );
        Functions\when('wp_enqueue_style')->alias(
            static function (string $handle) use (&$enqueued): bool {
                $enqueued = $handle;

                return true;
            }
        );

        $this->block()->renderTicketButton([], '', $this->blockInstance(['postId' => 42]));

        // Guards against the live ArgumentCountError: it must enqueue core's
        // already-registered button style handle (single-arg wp_enqueue_style),
        // gated behind a registered-check - never call wp_enqueue_block_style(),
        // whose required second argument caused the fatal.
        self::assertSame(['wp-block-button', 'registered'], $registeredCheck);
        self::assertSame('wp-block-button', $enqueued);
    }

    public function testRenderTicketButtonDoesNotEnqueueWhenTheCoreButtonStyleIsNotRegistered(): void
    {
        $this->ticketUrlOnlyMeta();
        Functions\when('wp_style_is')->justReturn(false);

        $enqueueCalled = false;
        Functions\when('wp_enqueue_style')->alias(
            static function () use (&$enqueueCalled): bool {
                $enqueueCalled = true;

                return true;
            }
        );

        $html = $this->block()->renderTicketButton([], '', $this->blockInstance(['postId' => 42]));

        self::assertFalse($enqueueCalled, 'Must not enqueue an unregistered handle.');
        self::assertStringStartsWith('<a ', $html, 'The button itself must still render regardless.');
    }

    public function testRenderTicketButtonShowsSoldOutAsANonLinkWithTheSecondaryStyle(): void
    {
        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key) {
                return match ($key) {
                    '_eventmesh_url' => 'https://holvi.com/shop/MiaRenwall/product/abc123/',
                    '_eventmesh_sold_out' => '1',
                    '_eventmesh_price' => '€15',
                    default => '',
                };
            }
        );

        $html = $this->block()->renderTicketButton(['text' => 'Buy now'], '', $this->blockInstance(['postId' => 42]));

        self::assertStringContainsString('Sold out', $html);
        self::assertStringNotContainsString('Buy now', $html, 'Sold-out status overrides any custom label.');
        self::assertStringNotContainsString('€15', $html, 'Sold-out status overrides the price too.');
        self::assertStringContainsString('eventmesh-ticket-button--secondary', $html);
        self::assertStringContainsString(
            'wp-block-button__link wp-element-button',
            $html,
            'The sold-out marker must share the base button classes so it is the same size and shape.'
        );
        self::assertStringStartsWith('<span ', $html, 'Sold out is a status, not a call to action - it must not be a link.');
        self::assertStringNotContainsString('href=', $html, 'A sold-out event offers nothing to click through to.');
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

    public function testScopeQueryLoopTimeTimeScopesOnlyItsOwnVariationQuery(): void
    {
        $captured = null;
        Functions\when('add_filter')->alias(
            static function (string $hook, callable $callback) use (&$captured): bool {
                if ('query_loop_block_query_vars' === $hook) {
                    $captured = $callback;
                }

                return true;
            }
        );

        $block = $this->block();

        // A core/query without an eventmesh namespace registers nothing and is
        // returned untouched.
        self::assertNull($block->scopeQueryLoopTime(null, [
            'blockName' => 'core/query',
            'attrs' => ['namespace' => 'someone/else', 'queryId' => 5],
        ]));
        self::assertNull($captured);

        // The upcoming variation registers a query-vars filter.
        self::assertNull($block->scopeQueryLoopTime(null, [
            'blockName' => 'core/query',
            'attrs' => ['namespace' => 'eventmesh/upcoming-events', 'queryId' => 7],
        ]));
        self::assertIsCallable($captured);

        // It time-scopes only the matching queryId + events post type.
        $matching = $captured(['post_type' => 'eventmesh_event'], $this->blockInstance(['queryId' => 7]));
        self::assertArrayHasKey('meta_query', $matching, 'The matching loop must be time-scoped.');
        self::assertSame('ASC', $matching['order'], 'Upcoming events sort soonest-first.');

        // A different queryId, or a non-events query sharing the id, is left alone.
        self::assertArrayNotHasKey(
            'meta_query',
            $captured(['post_type' => 'eventmesh_event'], $this->blockInstance(['queryId' => 8]))
        );
        self::assertArrayNotHasKey(
            'meta_query',
            $captured(['post_type' => 'post'], $this->blockInstance(['queryId' => 7]))
        );
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
