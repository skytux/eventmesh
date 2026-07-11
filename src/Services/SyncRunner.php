<?php

declare(strict_types=1);

namespace EventMesh\Services;

use EventMesh\Support\Logger;
use EventMesh\Sync\EventSynchronizer;

final class SyncRunner
{
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
        $ids = [];

        if (null === $connectorIds) {
            $ids = array_keys($this->connectors->all());
        } else {
            $ids = array_values(array_filter(array_map('strval', $connectorIds)));
        }

        $summary = [
            'success' => true,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'skipped' => 0,
            'archived' => 0,
            'connectors' => [],
        ];

        foreach ($ids as $connectorId) {
            if (! $this->sourceSettings->isEnabled($connectorId)) {
                continue;
            }

            $connector = $this->connectors->get($connectorId);

            if (null === $connector) {
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
}
