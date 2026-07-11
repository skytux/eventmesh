<?php

declare(strict_types=1);

namespace EventMesh\Contracts;

use EventMesh\Models\Event;

interface ConnectorInterface
{
    /**
     * Unique connector ID.
     */
    public function id(): string;

    /**
     * Human-readable connector name.
     */
    public function label(): string;

    /**
     * Fetch remote events.
     *
     * @return array<int, Event>
     */
    public function fetch(): array;

    /**
     * Number of source fetches that failed during the most recent fetch() call.
     *
     * Used to gate destructive follow-up actions (like pruning events that
     * disappeared) so a transient network failure is never mistaken for an
     * event genuinely no longer existing at the source.
     */
    public function fetchErrors(): int;
}
