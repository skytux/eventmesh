<?php

declare(strict_types=1);

namespace EventMesh\Content;

use WP_Query;

final class EventQuery
{
    /**
     * @param array<string, mixed> $args
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(array $args = []): array
    {
        $defaults = [
            'post_type' => EventPostType::NAME,
            'post_status' => 'publish',
            'posts_per_page' => 6,
            'meta_key' => '_eventmesh_starts_at',
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'no_found_rows' => true,
        ];

        $query = new WP_Query(array_merge($defaults, $args));

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

            $events[] = [
                'id' => $postId,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $post->post_excerpt,
                'url' => get_permalink($post),
                'image' => get_the_post_thumbnail_url($post, 'large'),
                'image_id' => (int) get_post_thumbnail_id($post),
                'starts_at' => get_post_meta($postId, '_eventmesh_starts_at', true),
                'ends_at' => get_post_meta($postId, '_eventmesh_ends_at', true),
                'venue_name' => get_post_meta($postId, '_eventmesh_venue_name', true),
                'source_url' => get_post_meta($postId, '_eventmesh_url', true),
                'providers' => $providers,
                'post' => $post,
            ];
        }

        return $events;
    }
}
