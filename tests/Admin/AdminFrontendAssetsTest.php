<?php

declare(strict_types=1);

namespace EventMesh\Tests\Admin;

use Brain\Monkey\Functions;
use EventMesh\Admin\Admin;
use EventMesh\Core\Container;
use EventMesh\Tests\TestCase;

final class AdminFrontendAssetsTest extends TestCase
{
    public function testEnqueueFrontendStylesRegistersTheStylesheetWithACacheBustingVersion(): void
    {
        $registered = [];
        Functions\when('wp_enqueue_style')->alias(
            static function (string $handle, string $src, array $deps, string $version) use (&$registered) {
                $registered = [$handle, $src, $version];
            }
        );

        (new Admin(new Container()))->enqueueFrontendStyles();

        self::assertSame('eventmesh-frontend', $registered[0]);
        self::assertStringContainsString('assets/css/frontend.css', $registered[1]);
        self::assertNotSame('', $registered[2]);
    }
}
