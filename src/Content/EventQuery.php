<?php

declare(strict_types=1);

namespace EventMesh\Content;

use DateTimeImmutable;
use WP_Query;

final class EventQuery
{
    /**
     * Query var used to opt a WP_Query into applyUpcomingFirstClauses()'s
     * reordering - set by recent() itself, and by markQueryLoopUpcomingFirst()
     * for the Query Loop block's own internal query. Scoped as an explicit
     * opt-in (rather than matching on post_type alone in the posts_clauses
     * filter) so this never touches queries that don't ask for it - wp-admin's
     * events list table, REST API requests, etc.
     */
    private const UPCOMING_FIRST_FLAG = 'eventmesh_upcoming_first';

    public function boot(): void
    {
        add_filter('posts_clauses', [$this, 'applyUpcomingFirstClauses'], 10, 2);
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

        $now = (new DateTimeImmutable('now'))->format(DATE_ATOM);
        $events = [];

        foreach ($query->posts as $post) {
            $postId = (int) $post->ID;
            $meta = get_post_meta($postId);
            $providers = [];

            foreach ($meta as $key => $values) {
                if (! str_starts_with($key, '_eventmesh_provider_')) {
                    continue;
                }

                $providers[substr($key, strlen('_eventmesh_provider_'))] = (string) ($values[0] ?? '');
            }

            $startsAt = (string) get_post_meta($postId, '_eventmesh_starts_at', true);

            $events[] = [
                'id' => $postId,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $post->post_excerpt,
                'url' => get_permalink($post),
                'image' => get_the_post_thumbnail_url($post, 'large'),
                'image_id' => (int) get_post_thumbnail_id($post),
                'starts_at' => $startsAt,
                'ends_at' => get_post_meta($postId, '_eventmesh_ends_at', true),
                'venue_name' => get_post_meta($postId, '_eventmesh_venue_name', true),
                'source_url' => get_post_meta($postId, '_eventmesh_url', true),
                'sold_out' => '1' === (string) get_post_meta($postId, '_eventmesh_sold_out', true),
                'embed_html' => get_post_meta($postId, '_eventmesh_embed_html', true),
                'is_past' => '' !== $startsAt && $startsAt < $now,
                'providers' => $providers,
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

        $now = (new DateTimeImmutable('now'))->format(DATE_ATOM);

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
     * @return array<int|string, mixed>|null Null means "no filter" (all events).
     */
    private function timeMetaQuery(string $time): ?array
    {
        // Compared as strings against DATE_ATOM values, consistent with the
        // existing orderby => meta_value sort — not reliable across mixed
        // UTC offsets, but no worse than the sorting behaviour already relied on.
        $now = (new DateTimeImmutable('now'))->format(DATE_ATOM);

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
