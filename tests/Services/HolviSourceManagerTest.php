<?php

declare(strict_types=1);

namespace EventMesh\Tests\Services;

use Brain\Monkey\Functions;
use EventMesh\Services\HolviSourceManager;
use EventMesh\Tests\TestCase;

final class HolviSourceManagerTest extends TestCase
{
    public function testAllNormalizesStoredRowsAndDropsEmptyUrls(): void
    {
        Functions\when('get_option')->justReturn(
            [
                ['id' => 'a', 'url' => 'https://a.test', 'enabled' => false],
                ['url' => ''],
                ['url' => 'https://b.test'],
            ]
        );

        $rows = (new HolviSourceManager())->all();

        self::assertSame(
            [
                ['id' => 'a', 'url' => 'https://a.test', 'enabled' => false],
                ['id' => '2', 'url' => 'https://b.test', 'enabled' => true],
            ],
            $rows
        );
    }

    public function testEnabledUrlsOnlyReturnsEnabledRows(): void
    {
        Functions\when('get_option')->justReturn(
            [
                ['id' => 'a', 'url' => 'https://a.test', 'enabled' => false],
                ['id' => 'b', 'url' => 'https://b.test', 'enabled' => true],
            ]
        );

        self::assertSame(['https://b.test'], (new HolviSourceManager())->enabledUrls());
    }

    public function testSaveNormalizesAndEscapesUrlsAndDropsEmptyOnes(): void
    {
        Functions\when('esc_url_raw')->alias(static fn ($url) => $url);

        $persisted = null;
        Functions\when('update_option')->alias(
            static function ($name, $value) use (&$persisted) {
                $persisted = $value;

                return true;
            }
        );

        (new HolviSourceManager())->save(
            [
                ['id' => 'a', 'url' => ' https://a.test ', 'enabled' => true],
                ['url' => '  '],
            ]
        );

        self::assertSame(
            [
                ['id' => 'a', 'url' => 'https://a.test', 'enabled' => true],
            ],
            $persisted
        );
    }
}
