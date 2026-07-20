<?php

declare(strict_types=1);

namespace EventMesh\Support;

final class DateTimeFormat
{
    /**
     * Formats a Unix timestamp with the site's configured date and time
     * format, or "Never" for a zero timestamp. Shared by the dashboard panel,
     * the diagnostics screen, and the sync-status shortcode so a change to how
     * EventMesh renders timestamps lives in one place.
     *
     * Unlike an event's own start time (a naive wall-clock the source
     * published), these are true instants - `time()` when a sync ran or a log
     * line was written. wp_date() converts that UTC instant into the site's
     * configured timezone; date_i18n() does not apply the offset to a raw
     * timestamp, so these read hours off wherever the server clock was not the
     * site's own local time.
     */
    public static function format(int $timestamp): string
    {
        if (0 === $timestamp) {
            return __('Never', 'eventmesh');
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }
}
