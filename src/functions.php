<?php

/**
 * EventMesh's public functions, for themes and other plugins. Check with
 * function_exists() before calling, so nothing breaks while EventMesh is off.
 */

declare(strict_types=1);

if (! function_exists('eventmesh_display_title')) {
    /**
     * The event's title in $lang, without the date: the stored translation
     * when there is a current one, else the original title. Plain text, not
     * escaped.
     *
     * @param int|\WP_Post $post The event or its id.
     * @param string       $lang Two-letter language code; '' = the language
     *                           of the current page.
     *
     * @return string '' when the post is not an event.
     */
    function eventmesh_display_title(int|\WP_Post $post, string $lang = ''): string
    {
        return EventMesh\Naming\DisplayTitle::of($post, $lang);
    }
}
