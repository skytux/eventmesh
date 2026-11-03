<?php

declare(strict_types=1);

namespace EventMesh\Naming;

use EventMesh\Content\EventPostType;
use EventMesh\Djs\EventDjsFilter;

/**
 * Two filters that give other plugins an event's clean name and its DJ names.
 *
 *     $name = apply_filters('eventmesh/event_name', '', $postId, $lang);
 *
 * gives the event's name without the date and the DJ names, in normal case
 * ("Ecstatic Dance" from "ECSTATIC DANCE 14.11.2026 / Ihmeidentekijä").
 * $lang 'fi' gives the name made from the event's Finnish title, or the
 * original-language name when there is no Finnish one; any other $lang
 * ('en') gives the original-language name. The value passed in ('') comes
 * back unchanged when the post is not an event, or when no name has been
 * written for the event's current title yet (names are written in the
 * background by the language model, see EventNameQueue).
 *
 *     $djs = apply_filters('eventmesh/event_dj_names', [], $postId);
 *
 * gives a list of DJ names (strings): the DJs from the DJ table the event
 * names (the 'name' of each `eventmesh/event_djs` item) when there are any,
 * otherwise the names the language model found in the event's current title.
 * The value passed in ([]) comes back unchanged when the post is not an event
 * or neither source has a name.
 */
final class EventNameFilters
{
    public const NAME_FILTER = 'eventmesh/event_name';
    public const DJ_NAMES_FILTER = 'eventmesh/event_dj_names';

    public function boot(): void
    {
        add_filter(self::NAME_FILTER, [$this, 'filterName'], 10, 3);
        add_filter(self::DJ_NAMES_FILTER, [$this, 'filterDjNames'], 10, 2);
    }

    /**
     * @param mixed $value  What earlier callbacks returned ('' from the caller).
     * @param mixed $postId The event's post id.
     * @param mixed $lang   'en' or 'fi'.
     *
     * @return mixed The clean name, or $value unchanged.
     */
    public function filterName(mixed $value, mixed $postId = 0, mixed $lang = 'en'): mixed
    {
        $post = self::event($postId);
        $stored = null !== $post ? EventNameStore::current($post) : null;

        if (null === $stored) {
            return $value;
        }

        if (is_string($lang) && 'fi' === strtolower(trim($lang)) && '' !== $stored['name_fi']) {
            return $stored['name_fi'];
        }

        return $stored['name'];
    }

    /**
     * @param mixed $value  What earlier callbacks returned ([] from the caller).
     * @param mixed $postId The event's post id.
     *
     * @return mixed A list of DJ names, or $value unchanged.
     */
    public function filterDjNames(mixed $value, mixed $postId = 0): mixed
    {
        $post = self::event($postId);

        if (null === $post) {
            return $value;
        }

        $matched = apply_filters(EventDjsFilter::FILTER, [], $post->ID);
        $names = [];

        if (is_array($matched)) {
            foreach ($matched as $dj) {
                if (is_array($dj) && isset($dj['name']) && is_string($dj['name']) && '' !== $dj['name']) {
                    $names[] = $dj['name'];
                }
            }
        }

        if ([] !== $names) {
            return $names;
        }

        $stored = EventNameStore::current($post);

        return null !== $stored && [] !== $stored['djs'] ? $stored['djs'] : $value;
    }

    private static function event(mixed $postId): ?\WP_Post
    {
        // get_post(0) would give the current global post instead.
        if (! is_numeric($postId) || (int) $postId <= 0) {
            return null;
        }

        $post = get_post((int) $postId);

        return $post instanceof \WP_Post && EventPostType::NAME === $post->post_type ? $post : null;
    }
}
