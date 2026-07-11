<?php

declare(strict_types=1);

namespace EventMesh\Core;

use EventMesh\Contracts\ConnectorInterface;

final class ConnectorRegistry
{
    /**
     * @var array<string, ConnectorInterface>
     */
    private array $connectors = [];

    public function register(ConnectorInterface $connector): void
    {
        $this->connectors[$connector->id()] = $connector;
    }

    /**
     * @return array<string, ConnectorInterface>
     */
    public function all(): array
    {
        return $this->connectors;
    }

    public function has(string $id): bool
    {
        return isset($this->connectors[$id]);
    }

    public function get(string $id): ?ConnectorInterface
    {
        return $this->connectors[$id] ?? null;
    }
}
