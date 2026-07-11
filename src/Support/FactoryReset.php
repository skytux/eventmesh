<?php

declare(strict_types=1);

namespace EventMesh\Support;

use EventMesh\Content\EventPostType;
use EventMesh\Content\PerformerTaxonomy;
use WP_Query;

/**
 * Wipes everything EventMesh has created or stored, back to a fresh-install
 * state - synced events, taxonomy terms, options, transients, and the
 * scheduled background sync. Used by both the Settings page's "Factory
 * Reset" button (plugin stays active) and uninstall.php (plugin is being
 * removed).
 */
final class FactoryReset
{
    /**
     * @return array{deleted_events: int, deleted_terms: int}
     */
    public static function run(): array
    {
        $deletedEvents = self::deleteAllEvents();
        $deletedTerms = self::deleteAllPerformerTerms();

        self::deleteOptions();
        self::deleteTransients();

        wp_clear_scheduled_hook('eventmesh/background_sync');

        return [
            'deleted_events' => $deletedEvents,
            'deleted_terms' => $deletedTerms,
        ];
    }

    private static function deleteAllEvents(): int
    {
        $query = new WP_Query(
            [
                'post_type' => EventPostType::NAME,
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
            ]
        );

        $deleted = 0;

        foreach ($query->posts as $postId) {
            if (wp_delete_post((int) $postId, true)) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    private static function deleteAllPerformerTerms(): int
    {
        $terms = get_terms(
            [
                'taxonomy' => PerformerTaxonomy::NAME,
                'hide_empty' => false,
                'fields' => 'ids',
            ]
        );

        if (! is_array($terms)) {
            return 0;
        }

        $deleted = 0;

        foreach ($terms as $termId) {
            if (! is_wp_error(wp_delete_term((int) $termId, PerformerTaxonomy::NAME))) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    private static function deleteOptions(): void
    {
        $options = [
            'eventmesh_holvi_sources',
            'eventmesh_holvi_source_urls',
            'eventmesh_source_settings',
            'eventmesh_artist_map',
            'eventmesh_enable_background_sync',
            'eventmesh_sync_interval',
            'eventmesh_recent_logs',
        ];

        foreach ($options as $option) {
            delete_option($option);
        }
    }

    private static function deleteTransients(): void
    {
        $transients = [
            'eventmesh_sync_status',
            'eventmesh_last_sync',
            'eventmesh_sync_notice',
        ];

        foreach ($transients as $transient) {
            delete_transient($transient);
        }
    }
}
