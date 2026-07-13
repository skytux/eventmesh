<?php

declare(strict_types=1);

namespace EventMesh\Support;

final class SyncStatus
{
    /**
     * Human-readable, translated label for a stored sync-status key. Shared by
     * the admin dashboard panel and the [eventmesh_status] shortcode so both
     * render every status - including 'completed_with_errors' - identically,
     * rather than one prettifying it and the other showing the raw key.
     */
    public static function label(string $status): string
    {
        return match ($status) {
            'idle' => __('Idle', 'eventmesh'),
            'running' => __('Running', 'eventmesh'),
            'completed' => __('Completed', 'eventmesh'),
            'completed_with_errors' => __('Completed with errors', 'eventmesh'),
            'error' => __('Error', 'eventmesh'),
            default => ucfirst($status),
        };
    }
}
