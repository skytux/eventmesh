<?php

declare(strict_types=1);

namespace EventMesh\Contracts;

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
     * @return array
     */
    public function fetch(): array;
}