<?php

declare(strict_types=1);

namespace EventMesh\Tests\Admin;

use Brain\Monkey\Functions;
use EventMesh\Admin\Admin;
use EventMesh\Core\Container;
use EventMesh\Tests\TestCase;

final class AdminFrontendAssetsTest extends TestCase
{
    /** @var array<int, array{0: string, 1: mixed, 2: string}> */
    private array $registeredStyles = [];

    /** @var array<string, string> */
    private array $inlineStyles = [];

    /** @var array<int, string> */
    private array $registeredScripts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->registeredStyles = [];
        $this->inlineStyles = [];
        $this->registeredScripts = [];

        Functions\when('wp_register_style')->alias(
            function (string $handle, $src, array $deps = [], $version = ''): bool {
                $this->registeredStyles[] = [$handle, $src, (string) $version];

                return true;
            }
        );

        Functions\when('wp_enqueue_style')->justReturn(null);

        Functions\when('wp_add_inline_style')->alias(
            function (string $handle, string $css): bool {
                $this->inlineStyles[$handle] = $css;

                return true;
            }
        );

        Functions\when('wp_register_script')->alias(
            function (string $handle): bool {
                $this->registeredScripts[] = $handle;

                return true;
            }
        );
    }

    /**
     * The stylesheet is small enough that a separate <link> costs more in
     * round trips than it saves in bytes, so it is printed inline - which
     * means registering it with no source at all.
     */
    public function testPrintsTheStylesheetInlineRatherThanAsASeparateRequest(): void
    {
        (new Admin(new Container()))->enqueueFrontendStyles();

        self::assertSame([['eventmesh-frontend', false, '']], $this->maskStyleVersions());
        self::assertArrayHasKey('eventmesh-frontend', $this->inlineStyles);
        self::assertStringContainsString(
            '.eventmesh-embed-facade',
            $this->inlineStyles['eventmesh-frontend'],
            'The inlined CSS is the real stylesheet, not an empty placeholder.'
        );
    }

    public function testRegistersTheStylesheetWithACacheBustingVersion(): void
    {
        (new Admin(new Container()))->enqueueFrontendStyles();

        self::assertNotSame('', $this->registeredStyles[0][2]);
    }

    /**
     * Both embed strategies' scripts are registered but not enqueued, so
     * ProviderEmbedMarkup can add whichever one it uses only on pages that
     * actually render an embed needing it.
     */
    public function testRegistersBothEmbedScriptsForOnDemandEnqueueing(): void
    {
        (new Admin(new Container()))->enqueueFrontendStyles();

        self::assertContains('eventmesh-embed-lazy', $this->registeredScripts);
        self::assertContains('eventmesh-embed-facade', $this->registeredScripts);
    }

    /**
     * @return array<int, array{0: string, 1: mixed, 2: string}>
     */
    private function maskStyleVersions(): array
    {
        return array_map(
            static fn (array $style): array => [$style[0], $style[1], ''],
            $this->registeredStyles
        );
    }
}
