<?php

declare(strict_types=1);

namespace EventMesh\Content;

final class EventPostType
{
    public const NAME = 'eventmesh_event';

    public function boot(): void
    {
        add_action('init', [$this, 'register']);
    }

    public function register(): void
    {
        register_post_type(
            self::NAME,
            [
                'labels' => [
                    'name' => _x('Events', 'post type general name', 'eventmesh'),
                    'singular_name' => _x('Event', 'post type singular name', 'eventmesh'),
                    'menu_name' => _x('Events', 'admin menu', 'eventmesh'),
                    'name_admin_bar' => _x('Event', 'add new on admin bar', 'eventmesh'),
                    'add_new' => _x('Add New', 'event', 'eventmesh'),
                    'add_new_item' => __('Add New Event', 'eventmesh'),
                    'new_item' => __('New Event', 'eventmesh'),
                    'edit_item' => __('Edit Event', 'eventmesh'),
                    'view_item' => __('View Event', 'eventmesh'),
                    'all_items' => __('Events', 'eventmesh'),
                    'search_items' => __('Search Events', 'eventmesh'),
                    'parent_item_colon' => __('Parent Events:', 'eventmesh'),
                    'not_found' => __('No events found.', 'eventmesh'),
                    'not_found_in_trash' => __('No events found in Trash.', 'eventmesh'),
                    'archives' => __('Event Archives', 'eventmesh'),
                    'attributes' => __('Event Attributes', 'eventmesh'),
                    'insert_into_item' => __('Insert into event', 'eventmesh'),
                    'uploaded_to_this_item' => __('Uploaded to this event', 'eventmesh'),
                    'filter_items_list' => __('Filter events list', 'eventmesh'),
                    'items_list_navigation' => __('Events list navigation', 'eventmesh'),
                    'items_list' => __('Events list', 'eventmesh'),
                ],
                'public' => true,
                'show_ui' => true,
                'show_in_menu' => true,
                'show_in_rest' => true,
                'menu_icon' => 'dashicons-calendar-alt',
                'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'custom-fields'],
                'has_archive' => true,
                'rewrite' => [
                    'slug' => 'events',
                    'with_front' => false,
                ],
                'capability_type' => 'post',
            ]
        );

        $this->registerMeta();
    }

    private function registerMeta(): void
    {
        $fields = [
            '_eventmesh_source_id',
            '_eventmesh_external_id',
            '_eventmesh_starts_at',
            '_eventmesh_ends_at',
            '_eventmesh_url',
            '_eventmesh_image_url',
            '_eventmesh_venue_name',
        ];

        foreach ($fields as $field) {
            register_post_meta(
                self::NAME,
                $field,
                [
                    'type' => 'string',
                    'single' => true,
                    'show_in_rest' => true,
                    'auth_callback' => static fn (): bool => current_user_can('edit_posts'),
                ]
            );
        }
    }
}
