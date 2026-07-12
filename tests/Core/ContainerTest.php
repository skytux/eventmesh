<?php

declare(strict_types=1);

namespace EventMesh\Tests\Core;

use EventMesh\Core\Container;
use EventMesh\Tests\TestCase;
use RuntimeException;

final class ContainerTest extends TestCase
{
    public function testGetResolvesAndCachesASingleton(): void
    {
        $container = new Container();
        $calls = 0;

        $container->singleton('thing', function () use (&$calls) {
            ++$calls;

            return new \stdClass();
        });

        $first = $container->get('thing');
        $second = $container->get('thing');

        self::assertSame($first, $second);
        self::assertSame(1, $calls);
    }

    public function testGetThrowsForAnUnregisteredService(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);

        $container->get('missing');
    }

    public function testHasReflectsWhetherAServiceIsRegistered(): void
    {
        $container = new Container();

        self::assertFalse($container->has('thing'));

        $container->singleton('thing', static fn () => new \stdClass());

        self::assertTrue($container->has('thing'));
    }

    public function testRegisteredIdsListsEverySingletonRegistered(): void
    {
        $container = new Container();
        $container->singleton('a', static fn () => new \stdClass());
        $container->singleton('b', static fn () => new \stdClass());

        self::assertSame(['a', 'b'], $container->registeredIds());
    }
}
