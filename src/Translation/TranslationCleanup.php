<?php

declare(strict_types=1);

namespace EventMesh\Translation;

use EventMesh\Inference\EventFieldQueue;
use EventMesh\Inference\InferredFields;
use EventMesh\Naming\EventNameQueue;
use EventMesh\Naming\EventNameStore;

/**
 * Removes everything automatic translation stores: its options (including
 * the encrypted API key), the per-event translation meta, the chunk cache
 * transients, and the scheduled queue run. The same for the event names the
 * language model writes (EventNameQueue) and for its record of the event
 * fields it filled in (EventFieldQueue).
 */
final class TranslationCleanup
{
    /**
     * Options and the scheduled run only. Used by the factory reset, which
     * deletes the events themselves (and so their meta) separately.
     */
    public static function deleteSettings(): void
    {
        foreach (LlmSettings::OPTIONS as $option) {
            delete_option($option);
        }

        wp_clear_scheduled_hook(TranslationQueue::HOOK);

        delete_option(EventNameQueue::OPTION_QUEUE);
        wp_clear_scheduled_hook(EventNameQueue::HOOK);

        delete_option(EventFieldQueue::OPTION_QUEUE);
        wp_clear_scheduled_hook(EventFieldQueue::HOOK);
    }

    /**
     * Everything, including the meta on events that stay. Used on uninstall.
     */
    public static function run(): void
    {
        self::deleteSettings();

        global $wpdb;

        $metaPrefixes = [
            TranslationStore::META_PREFIX,
            TranslationStore::META_ORIGINAL_LANG,
            // _eventmesh_name, _eventmesh_name_fi, _eventmesh_name_hash, _eventmesh_name_fail.
            EventNameStore::META_NAME,
            EventNameStore::META_DJ_NAMES,
            // Which fields the language model filled in (the values stay).
            InferredFields::META_FIELDS,
            InferredFields::META_ASKED,
            InferredFields::META_FAIL,
        ];

        foreach ($metaPrefixes as $prefix) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup on uninstall.
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
                    $wpdb->esc_like($prefix) . '%'
                )
            );
        }

        foreach (['_transient_', '_transient_timeout_'] as $prefix) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup on uninstall.
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like($prefix . Translator::CACHE_PREFIX) . '%'
                )
            );
        }
    }
}
