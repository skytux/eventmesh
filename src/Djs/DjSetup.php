<?php

declare(strict_types=1);

namespace EventMesh\Djs;

/**
 * Creates or updates the DJ table, and seeds it once, on an admin page load.
 *
 * This runs in wp-admin only, not on visitor page loads: seeding is followed
 * by a check of every future event, which can fetch players from Spotify,
 * SoundCloud and Mixcloud, and a visitor should not wait for that. Until it
 * has run, the DJ list is simply empty and matching does nothing.
 */
final class DjSetup
{
    public function __construct(
        private readonly DjTable $table,
        private readonly DjSeeder $seeder,
        private readonly DjLinkApplier $applier
    ) {
    }

    public function boot(): void
    {
        add_action('admin_init', [$this, 'run']);
    }

    public function run(): void
    {
        $this->table->maybeUpgrade();

        if ($this->seeder->seedOnce() > 0) {
            $this->applier->recheckFutureEvents();
        }
    }
}
