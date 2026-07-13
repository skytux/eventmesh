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
     */
    public static function format(int $timestamp): string
    {
        if (0 === $timestamp) {
            return __('Never', 'eventmesh');
        }

        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }
}
