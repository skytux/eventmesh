<?php

declare(strict_types=1);

namespace EventMesh\Djs;

use EventMesh\Content\EventPostType;

/**
 * The `eventmesh/event_djs` filter: which DJs an event names, with their
 * descriptions, for other plugins.
 *
 *     $djs = apply_filters('eventmesh/event_djs', [], $postId);
 *
 * gives a list of ['name' => string, 'description' => string], one item per
 * DJ the event's title or text names (matched the way the DJ links are, see
 * DjMatcher), first named first. 'description' is '' when the DJ has none.
 * The value passed in comes back unchanged when the post is not an event or
 * names no DJ.
 */
final class EventDjsFilter
{
    public const FILTER = 'eventmesh/event_djs';

    public function __construct(
        private readonly DjRepository $repository
    ) {
    }

    public function boot(): void
    {
        add_filter(self::FILTER, [$this, 'filter'], 10, 2);
    }

    /**
     * @param mixed $value  What earlier callbacks returned ([] from the caller).
     * @param mixed $postId The event's post id.
     *
     * @return mixed A list of ['name' => string, 'description' => string],
     *               or $value unchanged.
     */
    public function filter(mixed $value, mixed $postId = 0): mixed
    {
        // get_post(0) would give the current global post instead.
        if (! is_numeric($postId) || (int) $postId <= 0) {
            return $value;
        }

        $post = get_post((int) $postId);

        if (! $post instanceof \WP_Post || EventPostType::NAME !== $post->post_type) {
            return $value;
        }

        $matched = DjMatcher::matches($this->repository->all(), $post->post_title . "\n" . $post->post_content);

        if ([] === $matched) {
            return $value;
        }

        return array_map(
            static fn (Dj $dj): array => ['name' => $dj->name, 'description' => $dj->description],
            $matched
        );
    }
}
