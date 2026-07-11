<?php

declare(strict_types=1);

namespace EventMesh\Tests\Services;

use EventMesh\Core\ConnectorRegistry;
use EventMesh\Services\ConnectorManager;
use EventMesh\Tests\Fixtures\FakeConnector;
use EventMesh\Tests\TestCase;
use InvalidArgumentException;

final class ConnectorManagerTest extends TestCase
{
    private function manager(): ConnectorManager
    {
        return new ConnectorManager(new ConnectorRegistry());
    }

    public function testRegistersValidConnector(): void
    {
        $manager = $this->manager();
        $manager->register(new FakeConnector('holvi'));

        self::assertTrue($manager->has('holvi'));
        self::assertSame(1, $manager->count());
        self::assertSame(
            [['id' => 'holvi', 'label' => 'Fake']],
            $manager->sourceRows()
        );
    }

    public function testRejectsInvalidConnectorId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->manager()->register(new FakeConnector('Not Valid!'));
    }

    public function testRejectsDuplicateConnectorId(): void
    {
        $manager = $this->manager();
        $manager->register(new FakeConnector('holvi'));

        $this->expectException(InvalidArgumentException::class);

        $manager->register(new FakeConnector('holvi'));
    }
}
