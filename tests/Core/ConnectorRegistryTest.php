<?php

declare(strict_types=1);

namespace EventMesh\Tests\Core;

use EventMesh\Core\ConnectorRegistry;
use EventMesh\Tests\Fixtures\FakeConnector;
use EventMesh\Tests\TestCase;

final class ConnectorRegistryTest extends TestCase
{
    public function testRegisterAndRetrieveById(): void
    {
        $registry = new ConnectorRegistry();
        $connector = new FakeConnector('fake');

        $registry->register($connector);

        self::assertTrue($registry->has('fake'));
        self::assertSame($connector, $registry->get('fake'));
        self::assertSame(['fake' => $connector], $registry->all());
    }

    public function testUnknownIdIsNotFound(): void
    {
        $registry = new ConnectorRegistry();

        self::assertFalse($registry->has('missing'));
        self::assertNull($registry->get('missing'));
    }

    public function testRegisteringSameIdTwiceOverwrites(): void
    {
        $registry = new ConnectorRegistry();
        $first = new FakeConnector('fake');
        $second = new FakeConnector('fake');

        $registry->register($first);
        $registry->register($second);

        self::assertSame($second, $registry->get('fake'));
    }
}
