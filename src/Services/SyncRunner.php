<?php

declare(strict_types=1);

namespace EventMesh\Services;

use EventMesh\Support\Logger;
use EventMesh\Sync\EventSynchronizer;

final class SyncRunner
{
    /**
     * Guards against two sync runs executing concurrently - WP-Cron's
     * well-known double-fire behavior, or a manual "Sync now" click while a
     * background run is in flight, would otherwise race
     * EventSynchronizer::sync()'s find-then-insert and risk duplicate posts.
     * TTL is a crash backstop only: released in a finally block on every
     * normal exit, so this is just insurance against a run that died
     * mid-way without ever releasing it.
     */
    private const LOCK_TRANSIENT = 'eventmesh_sync_lock';
    private const LOCK_TTL_SECONDS = 300;

    /**
     * Updated after every actual run attempt (lock permitting), regardless
     * of outcome - the authoritative "is the sync pipeline still alive"
     * signal used by CronFallbackTrigger, independent of DashboardPage's
     * user-facing "last sync summary" transient.
     */
    private const LAST_ATTEMPT_OPTION = 'eventmesh_last_sync_attempt_at';

    public function __construct(
        private readonly ConnectorManager $connectors,
        private readonly EventSynchronizer $synchronizer,
        private readonly Logger $logger,
        private readonly SourceSettings $sourceSettings
    ) {
    }

    /**
     * @param array<int, string>|null $connectorIds
     *
     * @return array{
     *     success: bool,
     *     processed: int,
     *     created: int,
     *     updated: int,
     *     failed: int,
     *     skipped: int,
     *     archived: int,
     *     connectors: array<int, array{
     *         id: string,
     *         label: string,
     *         events: int,
     *         created: int,
     *         updated: int,
     *         failed: int,
     *         skipped: int,
     *         archived: int
     *     }>
     * }
     */
    public function run(?array $connectorIds = null): array
    {
        $existingLock = get_transient(self::LOCK_TRANSIENT);

        if (false !== $existingLock && ! $this->lockIsStale($existingLock)) {
            $this->logger->warning('Skipped sync run: another sync is already in progress.');

            return $this->emptySummary();
        }

        if (false !== $existingLock) {
            // The transient's own TTL should normally expire the lock, but a
            // run that died mid-way (PHP timeout, fatal) - or an object-cache
            // backend that doesn't strictly honor TTLs - can leave it behind,
            // silently blocking every subsequent cron/fallback run until it
            // clears. Reclaiming a demonstrably-too-old lock self-heals that.
            $this->logger->warning(
                sprintf(
                    'Reclaiming a stale sync lock acquired at %s - a previous run likely died without releasing it.',
                    gmdate('c', (int) $existingLock)
                )
            );
        }

        set_transient(self::LOCK_TRANSIENT, time(), self::LOCK_TTL_SECONDS);

        try {
            return $this->runLocked($connectorIds);
        } finally {
            delete_transient(self::LOCK_TRANSIENT);
            update_option(self::LAST_ATTEMPT_OPTION, time());
        }
    }

    /**
     * A lock is stale once it is older than its own intended lifetime: no
     * legitimately-running sync should ever hold it that long.
     *
     * @param mixed $lock the stored lock value (its acquisition timestamp)
     */
    private function lockIsStale($lock): bool
    {
        return time() - (int) $lock >= self::LOCK_TTL_SECONDS;
    }

    /**
     * @param array<int, string>|null $connectorIds
     *
     * @return array{
     *     success: bool,
     *     processed: int,
     *     created: int,
     *     updated: int,
     *     failed: int,
     *     skipped: int,
     *     archived: int,
     *     connectors: array<int, array{
     *         id: string,
     *         label: string,
     *         events: int,
     *         created: int,
     *         updated: int,
     *         failed: int,
     *         skipped: int,
     *         archived: int
     *     }>
     * }
     */
    private function runLocked(?array $connectorIds): array
    {
        $ids = [];

        if (null === $connectorIds) {
            $ids = array_keys($this->connectors->all());
        } else {
            $ids = array_values(array_filter(array_map('strval', $connectorIds)));
        }

        $summary = $this->emptySummary();

        foreach ($ids as $connectorId) {
            $connector = $this->connectors->get($connectorId);

            if (null === $connector) {
                continue;
            }

            if (! $this->sourceSettings->isEnabled($connectorId)) {
                // A disabled source shouldn't keep publishing content it can
                // no longer vouch for: archive (draft) everything it owns
                // instead of skipping silently. pruneStale() with an empty
                // seen-list drafts every published post for the source, and
                // re-enabling + syncing republishes them (sync() finds posts
                // regardless of status and always writes publish).
                $archived = $this->synchronizer->pruneStale($connectorId, []);

                if ($archived > 0) {
                    $this->logger->info(
                        sprintf(
                            'Archived %d event(s) for disabled source "%s".',
                            $archived,
                            $connector->label()
                        )
                    );
                }

                $summary['archived'] += $archived;
                $summary['connectors'][] = [
                    'id' => $connectorId,
                    'label' => $connector->label(),
                    'events' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'failed' => 0,
                    'skipped' => 0,
                    'archived' => $archived,
                ];

                continue;
            }

            $events = $connector->fetch();
            $eventCount = count($events);
            $syncResult = $this->synchronizer->syncMany($events);
            $archived = 0;

            if (0 === $connector->fetchErrors()) {
                $seenExternalIds = array_map(
                    static fn ($event) => $event->externalId(),
                    $events
                );

                $archived = $this->synchronizer->pruneStale($connectorId, $seenExternalIds);
            } else {
                $this->logger->warning(
                    sprintf(
                        'Skipped stale-event cleanup for connector "%s": %d source fetch(es) failed.',
                        $connector->label(),
                        $connector->fetchErrors()
                    )
                );
            }

            $summary['processed'] += $eventCount;
            $summary['created'] += $syncResult['created'];
            $summary['updated'] += $syncResult['updated'];
            $summary['failed'] += $syncResult['failed'];
            $summary['skipped'] += $syncResult['skipped'];
            $summary['archived'] += $archived;
            $summary['connectors'][] = [
                'id' => $connectorId,
                'label' => $connector->label(),
                'events' => $eventCount,
                'created' => $syncResult['created'],
                'updated' => $syncResult['updated'],
                'failed' => $syncResult['failed'],
                'skipped' => $syncResult['skipped'],
                'archived' => $archived,
            ];

            $this->logger->info(
                sprintf(
                    'Completed sync for connector "%s": created=%d updated=%d failed=%d skipped=%d archived=%d',
                    $connector->label(),
                    $syncResult['created'],
                    $syncResult['updated'],
                    $syncResult['failed'],
                    $syncResult['skipped'],
                    $archived
                )
            );
        }

        return $summary;
    }

    /**
     * @return array{
     *     success: bool,
     *     processed: int,
     *     created: int,
     *     updated: int,
     *     failed: int,
     *     skipped: int,
     *     archived: int,
     *     connectors: array<int, array{
     *         id: string,
     *         label: string,
     *         events: int,
     *         created: int,
     *         updated: int,
     *         failed: int,
     *         skipped: int,
     *         archived: int
     *     }>
     * }
     */
    private function emptySummary(): array
    {
        return [
            'success' => true,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'skipped' => 0,
            'archived' => 0,
            'connectors' => [],
        ];
    }
}
