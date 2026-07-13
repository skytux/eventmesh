<?php

declare(strict_types=1);

namespace EventMesh\Services;

use EventMesh\Contracts\ConnectorInterface;
use EventMesh\Core\ConnectorRegistry;
use InvalidArgumentException;

final class ConnectorManager
{
    public function __construct(
        private readonly ConnectorRegistry $registry
    ) {
    }

    public function register(ConnectorInterface $connector): void
    {
        $id = $connector->id();

        if (1 !== preg_match('/^[a-z0-9_-]+$/', $id)) {
            throw new InvalidArgumentException(
                sprintf('Connector ID "%s" is invalid.', $id)
            );
        }

        if ($this->registry->has($id)) {
            throw new InvalidArgumentException(
                sprintf('Connector ID "%s" is already registered.', $id)
            );
        }

        $this->registry->register($connector);
    }

    /**
     * @return array<string, ConnectorInterface>
     */
    public function all(): array
    {
        return $this->registry->all();
    }

    public function count(): int
    {
        return count($this->registry->all());
    }

    public function has(string $id): bool
    {
        return $this->registry->has($id);
    }

    public function get(string $id): ?ConnectorInterface
    {
        return $this->registry->get($id);
    }
}
