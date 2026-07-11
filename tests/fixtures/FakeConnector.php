<?php

declare(strict_types=1);

namespace EventMesh\Tests\Fixtures;

use EventMesh\Contracts\ConnectorInterface;
use EventMesh\Models\Event;

final class FakeConnector implements ConnectorInterface
{
    /**
     * @param array<int, Event> $events
     */
    public function __construct(
        private readonly string $connectorId = 'fake',
        private readonly array $events = [],
        private readonly int $errors = 0
    ) {
    }

    public function id(): string
    {
        return $this->connectorId;
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function fetch(): array
    {
        return $this->events;
    }

    public function fetchErrors(): int
    {
        return $this->errors;
    }
}
