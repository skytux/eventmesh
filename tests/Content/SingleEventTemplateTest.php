<?php

declare(strict_types=1);

namespace EventMesh\Tests\Content;

use Brain\Monkey\Functions;
use EventMesh\Content\EventPostType;
use EventMesh\Content\SingleEventTemplate;
use EventMesh\Tests\TestCase;

final class SingleEventTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('__')->returnArg(1);
    }

    public function testRegistersTheBlockTemplateWithTheExpectedArguments(): void
    {
        $capturedName = null;
        $capturedArgs = null;

        Functions\when('register_block_template')->alias(
            static function (string $name, array $args) use (&$capturedName, &$capturedArgs) {
                $capturedName = $name;
                $capturedArgs = $args;

                return null;
            }
        );

        (new SingleEventTemplate())->registerBlockTemplate();

        self::assertSame('eventmesh//single-eventmesh_event', $capturedName);
        self::assertSame([EventPostType::NAME], $capturedArgs['post_types'] ?? null);
        self::assertStringContainsString(
            'wp:eventmesh/provider-embed',
            $capturedArgs['content'] ?? '',
            'The default layout must include the provider-embed block.'
        );
        self::assertGreaterThan(
            strpos($capturedArgs['content'], 'wp:post-featured-image'),
            strpos($capturedArgs['content'], 'wp:eventmesh/provider-embed'),
            'The provider embed must come after the featured image in the default layout.'
        );
    }

    public function testResolveContentFallsBackToTheDefaultWhenNoTemplateIsStored(): void
    {
        Functions\when('get_block_template')->justReturn(null);

        $content = (new SingleEventTemplate())->resolveContent();

        self::assertStringContainsString('wp:post-featured-image', $content);
        self::assertStringContainsString('wp:eventmesh/ticket-button', $content);
    }

    public function testResolveContentUsesTheStoredTemplatesContentWhenPresent(): void
    {
        Functions\when('get_block_template')->justReturn((object) ['content' => '<!-- wp:paragraph --><p>Custom</p><!-- /wp:paragraph -->']);

        $content = (new SingleEventTemplate())->resolveContent();

        self::assertSame('<!-- wp:paragraph --><p>Custom</p><!-- /wp:paragraph -->', $content);
    }

    public function testResolveContentFallsBackToTheDefaultWhenTheStoredContentIsEmpty(): void
    {
        Functions\when('get_block_template')->justReturn((object) ['content' => '']);

        $content = (new SingleEventTemplate())->resolveContent();

        self::assertStringContainsString('wp:post-featured-image', $content);
    }
}
