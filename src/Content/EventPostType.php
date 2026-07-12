<?php

declare(strict_types=1);

namespace EventMesh\Content;

use EventMesh\Services\ProviderEmbedEnricher;
use EventMesh\Support\KnownProviders;

final class EventPostType
{
    public const NAME = 'eventmesh_event';

    /**
     * Nullable/optional: eventmesh.php's activation hook constructs this
     * class directly (new EventPostType()) just to call register() before
     * the DI container exists yet, and never touches the code path that
     * needs this - only saveMetaBox() does, which only ever runs through
     * the normal Kernel-booted instance.
     */
    public function __construct(
        private readonly ?ProviderEmbedEnricher $providerEmbedEnricher = null
    ) {
    }

    public function boot(): void
    {
        add_action('init', [$this, 'register']);
        add_action('add_meta_boxes', [$this, 'registerMetaBox']);
        add_action('save_post_' . self::NAME, [$this, 'saveMetaBox'], 10, 2);
        add_filter('single_template', [$this, 'singleTemplate']);
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

    public function singleTemplate(string $template): string
    {
        // Once someone has actually built their own single-eventmesh_event
        // template in the Site Editor, defer to it entirely - that's a
        // deliberate opt-in. Until then, keep using the plugin's bundled
        // template (with its working venue/date/tickets output) as the
        // default on every theme, block or classic - a block theme existing
        // is not by itself a reason to silently drop that content.
        if ($this->hasCustomBlockTemplate()) {
            return $template;
        }

        $post = get_post();

        if (! $post instanceof \WP_Post || self::NAME !== $post->post_type) {
            return $template;
        }

        $customTemplate = EVENTMESH_PLUGIN_DIR . 'templates/frontend/single-event.php';

        if (is_readable($customTemplate)) {
            return $customTemplate;
        }

        return $template;
    }

    private function hasCustomBlockTemplate(): bool
    {
        if (! function_exists('wp_is_block_theme') || ! wp_is_block_theme()) {
            return false;
        }

        if (! function_exists('get_block_template')) {
            return false;
        }

        return null !== get_block_template(get_stylesheet() . '//single-' . self::NAME, 'wp_template');
    }

    public function registerMetaBox(): void
    {
        add_meta_box(
            'eventmesh_event_details',
            __('EventMesh details', 'eventmesh'),
            [$this, 'renderMetaBox'],
            self::NAME,
            'side',
            'default'
        );
    }

    public function renderMetaBox(
        \WP_Post $post
    ): void {
        $sourceId = get_post_meta($post->ID, '_eventmesh_source_id', true);
        $externalId = get_post_meta($post->ID, '_eventmesh_external_id', true);
        $sourceUrl = get_post_meta($post->ID, '_eventmesh_url', true);

        echo '<p><strong>' . esc_html__('Source', 'eventmesh') . ':</strong> ' . esc_html((string) $sourceId) . '</p>';
        echo '<p><strong>' . esc_html__('External ID', 'eventmesh') . ':</strong> ' . esc_html((string) $externalId) . '</p>';
        echo '<p><strong>' . esc_html__('Remote URL', 'eventmesh') . ':</strong> ' . esc_url((string) $sourceUrl) . '</p>';

        wp_nonce_field('eventmesh_save_providers_' . $post->ID, 'eventmesh_providers_nonce');

        echo '<p><strong>' . esc_html__('Providers', 'eventmesh') . '</strong></p>';
        echo '<p class="description">' . esc_html__(
            'Auto-filled from Holvi when it links to one of these; fill in manually otherwise.',
            'eventmesh'
        ) . '</p>';

        foreach (KnownProviders::labels() as $key => $label) {
            $value = get_post_meta($post->ID, '_eventmesh_provider_' . $key, true);

            printf(
                '<p><label>%s<br /><input type="url" style="width:100%%" ' .
                'name="eventmesh_provider_%s" value="%s" /></label></p>',
                esc_html($label),
                esc_attr($key),
                esc_attr((string) $value)
            );
        }
    }

    public function saveMetaBox(int $postId, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (! current_user_can('edit_post', $postId)) {
            return;
        }

        if ($post->post_type !== self::NAME) {
            return;
        }

        $this->saveProviderFields($postId);
    }

    private function saveProviderFields(int $postId): void
    {
        $nonce = isset($_POST['eventmesh_providers_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['eventmesh_providers_nonce']))
            : '';

        if ('' === $nonce || ! wp_verify_nonce($nonce, 'eventmesh_save_providers_' . $postId)) {
            return;
        }

        foreach (KnownProviders::labels() as $key => $label) {
            $field = 'eventmesh_provider_' . $key;

            if (! isset($_POST[$field])) {
                continue;
            }

            $value = esc_url_raw(sanitize_text_field(wp_unslash((string) $_POST[$field])));
            update_post_meta($postId, '_eventmesh_provider_' . $key, $value);
        }

        $this->providerEmbedEnricher?->enrich($postId);
    }

    private function registerMeta(): void
    {
        $fields = [
            '_eventmesh_source_id',
            '_eventmesh_external_id',
            '_eventmesh_starts_at',
            '_eventmesh_starts_at_year_known',
            '_eventmesh_ends_at',
            '_eventmesh_url',
            '_eventmesh_image_url',
            '_eventmesh_venue_name',
            '_eventmesh_sold_out',
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

        // Computed by ProviderEmbedEnricher from a trusted oEmbed response,
        // never meant to be user-edited - kept out of REST entirely rather
        // than relying on auth_callback, since there's no legitimate case
        // for any REST client to write raw embed HTML directly.
        foreach (['_eventmesh_embed_html', '_eventmesh_embed_source_url'] as $field) {
            register_post_meta(
                self::NAME,
                $field,
                [
                    'type' => 'string',
                    'single' => true,
                    'show_in_rest' => false,
                ]
            );
        }
    }
}
