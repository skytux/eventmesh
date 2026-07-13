<?php

declare(strict_types=1);

namespace EventMesh\Tests\Admin;

use Brain\Monkey\Functions;
use EventMesh\Admin\EventListBlock;
use EventMesh\Connectors\Holvi\HolviHtmlParser;
use EventMesh\Content\EventQuery;
use EventMesh\Tests\TestCase;

final class EventListBlockPatternsTest extends TestCase
{
    /**
     * @return array<string, array{content: string}>
     */
    private function registerAndCapturePatterns(): array
    {
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('register_block_type')->justReturn(null);
        Functions\when('register_block_pattern_category')->justReturn(null);

        $registered = [];
        Functions\when('register_block_pattern')->alias(
            static function (string $name, array $args) use (&$registered): void {
                $registered[$name] = $args;
            }
        );

        (new EventListBlock(new EventQuery(), new HolviHtmlParser()))->register();

        return $registered;
    }

    private function queryLoopPatternContent(): string
    {
        $patterns = $this->registerAndCapturePatterns();

        self::assertArrayHasKey('eventmesh/event-query-loop-pattern', $patterns);

        return $patterns['eventmesh/event-query-loop-pattern']['content'];
    }

    public function testQueryLoopPatternIsRegisteredWithWellFormedBlockMarkup(): void
    {
        $content = $this->queryLoopPatternContent();

        preg_match_all('/<!--\s*(\/?)wp:([a-z\/-]+)(\s+\{.*?\})?\s*(\/?)-->/s', $content, $tags, PREG_SET_ORDER);
        self::assertNotEmpty($tags, 'Pattern content contains no recognizable block comments.');

        $depth = 0;

        foreach ($tags as $tag) {
            $json = trim($tag[3]);

            if ('' !== $json) {
                self::assertIsArray(
                    json_decode($json, true),
                    sprintf('Invalid JSON in "%s" block comment: %s', $tag[2], $json)
                );
            }

            if ('/' === trim($tag[4])) {
                continue; // self-closing block, doesn't affect nesting depth
            }

            $depth += '/' === $tag[1] ? -1 : 1;
        }

        self::assertSame(0, $depth, 'Block comments are not balanced (a wp:x is missing its /wp:x, or vice versa).');
    }

    public function testUsesTheDynamicEventFieldBlockForDateTitleAndVenueInsteadOfBindings(): void
    {
        $content = $this->queryLoopPatternContent();

        self::assertStringNotContainsString(
            '"bindings"',
            $content,
            'Block Bindings proved unreliable in testing (even a raw meta value bound as plain text never applied); ' .
            'the pattern must not depend on it anywhere.'
        );

        self::assertStringContainsString('wp:eventmesh/event-field {"field":"starts_at"', $content);
        self::assertStringContainsString('wp:eventmesh/event-field {"field":"title"', $content);
        self::assertStringContainsString('wp:eventmesh/event-field {"field":"venue"}', $content);
    }

    public function testRegistersTwoNamespacedLoopsAndDropsThePastEventsMarker(): void
    {
        $content = $this->queryLoopPatternContent();

        self::assertStringContainsString('"namespace":"eventmesh/upcoming-events"', $content);
        self::assertStringContainsString('"namespace":"eventmesh/past-events"', $content);
        self::assertSame(2, substr_count($content, '<!-- wp:query '), 'Expected exactly two separate Query Loops.');
        self::assertStringNotContainsString(
            'past-events-marker',
            $content,
            'The single-loop divider block is replaced by two independent loops.'
        );
    }

    public function testTheTicketButtonAppearsOnlyInTheUpcomingLoop(): void
    {
        $content = $this->queryLoopPatternContent();

        self::assertSame(
            1,
            substr_count($content, 'wp:eventmesh/ticket-button'),
            'Only the upcoming loop shows a ticket button; a past event can no longer sell tickets.'
        );
    }

    public function testDetailsToggleWrapsVenueAndContentButNotAnotherImage(): void
    {
        $content = $this->queryLoopPatternContent();

        self::assertStringContainsString('<!-- wp:details', $content);
        self::assertStringContainsString('<!-- wp:post-content /-->', $content);

        self::assertSame(
            2,
            substr_count($content, 'wp:post-featured-image'),
            'One featured image per loop (upcoming and past), each in its own row - never duplicated within a row.'
        );
    }

    public function testColumnsUseTopAlignmentSoExpandingDetailsDoesNotShiftSiblingColumns(): void
    {
        $content = $this->queryLoopPatternContent();

        self::assertStringNotContainsString('"verticalAlignment":"center"', $content);
        self::assertStringContainsString('"verticalAlignment":"top"', $content);
    }

    public function testProviderEmbedIsAlwaysVisibleNotInsideTheCollapsedDetails(): void
    {
        $content = $this->queryLoopPatternContent();

        self::assertSame(
            1,
            substr_count($content, 'wp:eventmesh/provider-embed'),
            'Expected exactly one provider embed, in the always-visible row.'
        );

        $detailsPosition = strpos($content, '<!-- wp:details');
        $embedPosition = strpos($content, 'wp:eventmesh/provider-embed');

        self::assertNotFalse($detailsPosition);
        self::assertNotFalse($embedPosition);
        self::assertLessThan(
            $detailsPosition,
            $embedPosition,
            'The provider embed must appear before the collapsed "Show more" details, not inside it.'
        );
    }

    public function testShowMoreAndNoResultsTextAreTranslatedNotHardcoded(): void
    {
        $content = $this->queryLoopPatternContent();

        self::assertStringContainsString('<summary>Show more</summary>', $content);
        self::assertStringContainsString('<p>No upcoming events.</p>', $content);
        self::assertStringContainsString('<p>No past events.</p>', $content);
        self::assertStringContainsString('>Upcoming events</h2>', $content);
        self::assertStringContainsString('>Past events</h2>', $content);
        self::assertStringNotContainsString('__EVENTMESH_', $content, 'A translation placeholder was left unreplaced.');
    }
}
