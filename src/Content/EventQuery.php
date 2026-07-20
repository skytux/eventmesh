<?php

declare(strict_types=1);

namespace EventMesh\Content;

use EventMesh\Support\EventMeta;
use EventMesh\Support\EventStatus;
use EventMesh\Support\LocalTime;
use WP_Query;

final class EventQuery
{
    /**
     * "Now" as a naive local wall-clock string, in the same form the event
     * dates are stored - so a string comparison against `_eventmesh_starts_at`
     * is comparing like with like. Both sides are zone-less local time, which
     * is exactly what makes the comparison well-defined now that no offset is
     * stored.
     */
    private function now(): string
    {
        return wp_date(LocalTime::STORAGE_FORMAT);
    }

    /**
     * Query var used to opt a WP_Query into applyUpcomingFirstClauses()'s
     * reordering - set by recent() itself, and by markQueryLoopUpcomingFirst()
     * for the Query Loop block's own internal query. Scoped as an explicit
     * opt-in (rather than matching on post_type alone in the posts_clauses
     * filter) so this never touches queries that don't ask for it - wp-admin's
     * events list table, REST API requests, etc.
     */
    private const UPCOMING_FIRST_FLAG = 'eventmesh_upcoming_first';

    /**
     * Query var opting a WP_Query into excludeHiddenClause(). Set on every
     * front-end listing query (recent() and the Query Loop query-var filters)
     * so an editor's "Hide"/"Disable" flag keeps an event out of listings,
     * while wp-admin's events table, REST, etc. - which never set it - still
     * show every event.
     */
    private const EXCLUDE_HIDDEN_FLAG = 'eventmesh_exclude_hidden';

    public function boot(): void
    {
        add_filter('posts_clauses', [$this, 'applyUpcomingFirstClauses'], 10, 2);
        add_filter('posts_where', [$this, 'excludeHiddenClause'], 10, 2);
    }

    /**
     * @param array<string, mixed> $args Standard WP_Query args, plus an optional
     *                                    'eventmesh_time' key: 'all' (default,
     *                                    upcoming events first, past events
     *                                    sorted to the bottom), 'upcoming'
     *                                    (excludes past events entirely), or
     *                                    'past'.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(array $args = []): array
    {
        $time = (string) ($args['eventmesh_time'] ?? 'all');
        unset($args['eventmesh_time']);

        $defaults = [
            'post_type' => EventPostType::NAME,
            'post_status' => 'publish',
            'posts_per_page' => 6,
            'no_found_rows' => true,
            self::EXCLUDE_HIDDEN_FLAG => true,
        ];

        if (! isset($args['meta_query'])) {
            $timeQuery = $this->timeMetaQuery($time);

            if (null !== $timeQuery) {
                $defaults['meta_query'] = $timeQuery;
                $defaults['meta_key'] = '_eventmesh_starts_at';
                $defaults['orderby'] = 'meta_value';
                $defaults['order'] = 'ASC';
            } else {
                $defaults[self::UPCOMING_FIRST_FLAG] = true;
            }
        }

        $query = new WP_Query(array_merge($defaults, $args));

        $now = $this->now();
        $events = [];

        foreach ($query->posts as $post) {
            $postId = (int) $post->ID;

            // Every overridable field is read through EventMeta so a manual
            // edit-screen value wins over the scraped one on the front end.
            $startsAt = EventMeta::resolve($postId, 'starts_at');

            $events[] = [
                'id' => $postId,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $post->post_excerpt,
                'url' => get_permalink($post),
                'image' => get_the_post_thumbnail_url($post, 'large'),
                'image_id' => (int) get_post_thumbnail_id($post),
                'starts_at' => $startsAt,
                'ends_at' => EventMeta::resolve($postId, 'ends_at'),
                'venue_name' => EventMeta::resolve($postId, 'venue_name'),
                'price' => EventMeta::resolve($postId, 'price'),
                'source_url' => get_post_meta($postId, '_eventmesh_url', true),
                'sold_out' => EventMeta::isSoldOut($postId),
                'embed_html' => get_post_meta($postId, '_eventmesh_embed_html', true),
                'is_past' => '' !== $startsAt && $startsAt < $now,
                'is_canceled' => EventStatus::isCanceled($post->post_title),
                'providers' => EventMeta::resolvedProviders($postId),
                'post' => $post,
            ];
        }

        return $events;
    }

    /**
     * Forces the Query Loop block's own internal WP_Query onto the same
     * upcoming-first ordering as recent(), regardless of whatever order/
     * orderBy the block's own attributes declare in the editor - those
     * settings don't support "upcoming first, past events last" natively.
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function markQueryLoopUpcomingFirst(array $query): array
    {
        if (EventPostType::NAME !== ($query['post_type'] ?? '')) {
            return $query;
        }

        $query[self::UPCOMING_FIRST_FLAG] = true;
        $query[self::EXCLUDE_HIDDEN_FLAG] = true;

        return $query;
    }

    /**
     * Reorders a flagged query's results into two buckets: upcoming (or
     * unknown-date) events first, soonest first; past events after, most
     * recently ended first. A plain `orderby => meta_value` can't express
     * that grouping, so this joins postmeta directly and builds the ORDER BY
     * as a pair of CASE expressions keyed off the same bucket test.
     *
     * @param array<string, string> $clauses
     *
     * @return array<string, string>
     */
    public function applyUpcomingFirstClauses(array $clauses, WP_Query $query): array
    {
        if (! $query->get(self::UPCOMING_FIRST_FLAG)) {
            return $clauses;
        }

        global $wpdb;

        $now = $this->now();

        $clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} AS eventmesh_starts_at" .
            " ON eventmesh_starts_at.post_id = {$wpdb->posts}.ID" .
            " AND eventmesh_starts_at.meta_key = '_eventmesh_starts_at'";

        $clauses['orderby'] = $wpdb->prepare(
            "(eventmesh_starts_at.meta_value IS NULL OR eventmesh_starts_at.meta_value = ''" .
            " OR eventmesh_starts_at.meta_value >= %s) DESC," .
            ' CASE WHEN eventmesh_starts_at.meta_value >= %s' .
            " OR eventmesh_starts_at.meta_value IS NULL OR eventmesh_starts_at.meta_value = ''" .
            ' THEN eventmesh_starts_at.meta_value END ASC,' .
            " CASE WHEN eventmesh_starts_at.meta_value < %s AND eventmesh_starts_at.meta_value != ''" .
            ' THEN eventmesh_starts_at.meta_value END DESC',
            $now,
            $now,
            $now
        );

        return $clauses;
    }

    /**
     * Restricts a Query Loop block's query vars to only upcoming or only past
     * events, ordered soonest-first / most-recent-first respectively. Used by
     * the two namespaced core/query variations (eventmesh/upcoming-events and
     * eventmesh/past-events) so each loop can be styled independently. Wins
     * over markQueryLoopUpcomingFirst() by clearing its flag, since these two
     * are mutually exclusive orderings of the same post type.
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function applyTimeToQueryVars(array $query, string $time): array
    {
        $timeQuery = $this->timeMetaQuery($time);

        if (null === $timeQuery) {
            return $query;
        }

        unset($query[self::UPCOMING_FIRST_FLAG]);

        $query['meta_query'] = $timeQuery;
        $query['meta_key'] = '_eventmesh_starts_at';
        $query['orderby'] = 'meta_value';
        $query['order'] = 'past' === $time ? 'DESC' : 'ASC';
        $query[self::EXCLUDE_HIDDEN_FLAG] = true;

        return $query;
    }

    /**
     * Excludes events an editor has hidden or disabled from any query that
     * opted in via EXCLUDE_HIDDEN_FLAG. Done as a NOT IN subquery on postmeta
     * (rather than a meta_query) so it never disturbs the meta_key/orderby the
     * time-scoped and upcoming-first listings already depend on. Both flags are
     * manual-only meta most events never carry.
     */
    public function excludeHiddenClause(string $where, WP_Query $query): string
    {
        if (! $query->get(self::EXCLUDE_HIDDEN_FLAG)) {
            return $where;
        }

        global $wpdb;

        $where .= " AND {$wpdb->posts}.ID NOT IN ("
            . "SELECT post_id FROM {$wpdb->postmeta}"
            . " WHERE meta_key IN ('_eventmesh_manual_hidden', '_eventmesh_manual_disabled')"
            . " AND meta_value = '1')";

        return $where;
    }

    /**
     * @return array<int|string, mixed>|null Null means "no filter" (all events).
     */
    private function timeMetaQuery(string $time): ?array
    {
        // Compared as strings against the stored naive wall-clock values,
        // consistent with the orderby => meta_value sort. Both sides are
        // zone-less local time, so the string comparison is well-defined.
        $now = $this->now();

        return match ($time) {
            'past' => [
                'relation' => 'AND',
                [
                    'key' => '_eventmesh_starts_at',
                    'value' => '',
                    'compare' => '!=',
                ],
                [
                    'key' => '_eventmesh_starts_at',
                    'value' => $now,
                    'compare' => '<',
                    'type' => 'CHAR',
                ],
            ],
            'upcoming' => [
                'relation' => 'OR',
                [
                    'key' => '_eventmesh_starts_at',
                    'value' => '',
                    'compare' => '=',
                ],
                [
                    'key' => '_eventmesh_starts_at',
                    'value' => $now,
                    'compare' => '>=',
                    'type' => 'CHAR',
                ],
            ],
            default => null,
        };
    }
}
