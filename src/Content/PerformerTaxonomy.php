<?php

declare(strict_types=1);

namespace EventMesh\Content;

final class PerformerTaxonomy
{
    public const NAME = 'eventmesh_performer';

    public function boot(): void
    {
        add_action('init', [$this, 'register']);
    }

    public function register(): void
    {
        register_taxonomy(
            self::NAME,
            [EventPostType::NAME],
            [
                'labels' => [
                    'name' => _x('Performers', 'taxonomy general name', 'eventmesh'),
                    'singular_name' => _x('Performer', 'taxonomy singular name', 'eventmesh'),
                    'search_items' => __('Search performers', 'eventmesh'),
                    'popular_items' => __('Popular performers', 'eventmesh'),
                    'all_items' => __('All performers', 'eventmesh'),
                    'edit_item' => __('Edit performer', 'eventmesh'),
                    'update_item' => __('Update performer', 'eventmesh'),
                    'add_new_item' => __('Add New performer', 'eventmesh'),
                    'new_item_name' => __('New performer name', 'eventmesh'),
                    'separate_items_with_commas' => __('Separate performers with commas', 'eventmesh'),
                    'add_or_remove_items' => __('Add or remove performers', 'eventmesh'),
                    'choose_from_most_used' => __('Choose from the most used performers', 'eventmesh'),
                    'not_found' => __('No performers found.', 'eventmesh'),
                    'menu_name' => __('Performers', 'eventmesh'),
                ],
                'public' => true,
                'show_ui' => true,
                'show_in_rest' => true,
                'hierarchical' => false,
                'show_admin_column' => true,
                'rewrite' => [
                    'slug' => 'performers',
                    'with_front' => false,
                ],
                'capabilities' => [
                    'manage_terms' => 'edit_posts',
                    'edit_terms' => 'edit_posts',
                    'delete_terms' => 'edit_posts',
                    'assign_terms' => 'edit_posts',
                ],
            ]
        );
    }
}
