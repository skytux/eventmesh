<?php

declare(strict_types=1);

namespace EventMesh\Naming;

use EventMesh\Content\EventPostType;

/**
 * The browser tab title of an event page: the event's title without the date,
 * in the language of the page (eventmesh_display_title()).
 *
 * WordPress builds the title of a single post page from single_post_title(),
 * both in wp_get_document_title() (the <title> of block themes and themes
 * with title-tag support) and in the older wp_title(). Filtering
 * single_post_title covers both. Admin screens keep the stored title.
 */
final class DocumentTitle
{
    public function boot(): void
    {
        add_filter('single_post_title', [$this, 'filterTitle'], 10, 2);
    }

    /**
     * @param mixed $title The title WordPress made from the post.
     * @param mixed $post  The post being shown.
     *
     * @return mixed The date-free title for an event, else $title unchanged.
     */
    public function filterTitle(mixed $title, mixed $post = null): mixed
    {
        if (! is_string($title) || ! $post instanceof \WP_Post || EventPostType::NAME !== $post->post_type) {
            return $title;
        }

        if (is_admin()) {
            return $title;
        }

        $display = DisplayTitle::of($post);

        return '' !== $display ? $display : $title;
    }
}
