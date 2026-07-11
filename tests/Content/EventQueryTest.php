<?php

declare(strict_types=1);

namespace EventMesh\Tests\Content;

use Brain\Monkey\Functions;
use EventMesh\Content\EventQuery;
use EventMesh\Tests\TestCase;

final class EventQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_permalink')->justReturn('');
        Functions\when('get_the_post_thumbnail_url')->justReturn('');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
    }

    public function testDefaultIncludesPastEventsInsteadOfExcludingThem(): void
    {
        $this->queueQueryResults([]);

        (new EventQuery())->recent();

        $args = \WP_Query::$lastArgs;

        self::assertArrayNotHasKey(
            'meta_query',
            $args,
            'Past events must no longer be excluded outright by default - they sort to the bottom instead.'
        );
        self::assertTrue($args['eventmesh_upcoming_first'] ?? false);
        self::assertArrayNotHasKey('eventmesh_time', $args);
    }

    public function testUpcomingOptInExcludesPastEvents(): void
    {
        $this->queueQueryResults([]);

        (new EventQuery())->recent(['eventmesh_time' => 'upcoming']);

        $args = \WP_Query::$lastArgs;

        self::assertSame('OR', $args['meta_query']['relation']);
        self::assertArrayNotHasKey('eventmesh_upcoming_first', $args);
    }

    public function testPastOptInExcludesEventsWithNoStartDate(): void
    {
        $this->queueQueryResults([]);

        (new EventQuery())->recent(['eventmesh_time' => 'past']);

        $args = \WP_Query::$lastArgs;

        self::assertSame('AND', $args['meta_query']['relation']);
        self::assertSame('!=', $args['meta_query'][0]['compare']);
        self::assertSame('<', $args['meta_query'][1]['compare']);
    }

    public function testAllOptInIsEquivalentToTheDefault(): void
    {
        $this->queueQueryResults([]);

        (new EventQuery())->recent(['eventmesh_time' => 'all']);

        self::assertArrayNotHasKey('meta_query', \WP_Query::$lastArgs);
        self::assertTrue(\WP_Query::$lastArgs['eventmesh_upcoming_first'] ?? false);
    }

    public function testExplicitMetaQueryOverridesTheDefault(): void
    {
        $this->queueQueryResults([]);

        $custom = [['key' => '_eventmesh_venue_name', 'value' => 'Test Venue']];
        (new EventQuery())->recent(['meta_query' => $custom]);

        self::assertSame($custom, \WP_Query::$lastArgs['meta_query']);
        self::assertArrayNotHasKey('eventmesh_upcoming_first', \WP_Query::$lastArgs);
    }

    public function testIsPastFlagReflectsWhetherTheEventsStartDateHasAlreadyPassed(): void
    {
        $pastPost = new \WP_Post(1, 'eventmesh_event', 'Past Event');
        $futurePost = new \WP_Post(2, 'eventmesh_event', 'Future Event');
        $this->queueQueryResults([$pastPost, $futurePost]);

        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key = '', bool $single = false) {
                if ('' === $key) {
                    return [];
                }

                if ('_eventmesh_starts_at' === $key) {
                    return 1 === $postId ? '2000-01-01T00:00:00+00:00' : '2999-01-01T00:00:00+00:00';
                }

                return '';
            }
        );

        $events = (new EventQuery())->recent();

        self::assertTrue($events[0]['is_past']);
        self::assertFalse($events[1]['is_past']);
    }

    public function testRecentIncludesTheCachedEmbedHtml(): void
    {
        $post = new \WP_Post(1, 'eventmesh_event', 'Event With Embed');
        $this->queueQueryResults([$post]);

        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key = '', bool $single = false) {
                if ('' === $key) {
                    return [];
                }

                return '_eventmesh_embed_html' === $key
                    ? '<iframe src="https://open.spotify.com/embed/track/abc"></iframe>'
                    : '';
            }
        );

        $events = (new EventQuery())->recent();

        self::assertSame(
            '<iframe src="https://open.spotify.com/embed/track/abc"></iframe>',
            $events[0]['embed_html']
        );
    }

    public function testMarkQueryLoopUpcomingFirstSetsTheFlagForEventmeshQueries(): void
    {
        $result = (new EventQuery())->markQueryLoopUpcomingFirst(['post_type' => 'eventmesh_event']);

        self::assertTrue($result['eventmesh_upcoming_first'] ?? false);
    }

    public function testMarkQueryLoopUpcomingFirstLeavesOtherPostTypesAlone(): void
    {
        $result = (new EventQuery())->markQueryLoopUpcomingFirst(['post_type' => 'post']);

        self::assertArrayNotHasKey('eventmesh_upcoming_first', $result);
    }

    public function testApplyUpcomingFirstClausesLeavesUnflaggedQueriesUntouched(): void
    {
        $query = new \WP_Query(['post_type' => 'eventmesh_event']);
        $clauses = ['join' => '', 'orderby' => 'wp_posts.post_date DESC'];

        $result = (new EventQuery())->applyUpcomingFirstClauses($clauses, $query);

        self::assertSame($clauses, $result, 'Queries that did not opt in must not be touched.');
    }

    public function testApplyUpcomingFirstClausesJoinsPostmetaAndReordersWhenFlagged(): void
    {
        $query = new \WP_Query(['post_type' => 'eventmesh_event', 'eventmesh_upcoming_first' => true]);
        $clauses = ['join' => '', 'orderby' => 'wp_posts.post_date DESC'];

        $result = (new EventQuery())->applyUpcomingFirstClauses($clauses, $query);

        self::assertStringContainsString('wp_postmeta', $result['join']);
        self::assertStringContainsString('_eventmesh_starts_at', $result['join']);
        self::assertNotSame($clauses['orderby'], $result['orderby']);
    }
}
