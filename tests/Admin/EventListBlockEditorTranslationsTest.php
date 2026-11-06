<?php

declare(strict_types=1);

namespace EventMesh\Tests\Admin;

use Brain\Monkey\Functions;
use EventMesh\Admin\EventListBlock;
use EventMesh\Connectors\Holvi\HolviHtmlParser;
use EventMesh\Content\EventQuery;
use EventMesh\Tests\TestCase;

/**
 * Each block's editor script is pointed at the plugin's own languages/
 * folder, where tools/build-zip.php puts the JSON translations.
 */
final class EventListBlockEditorTranslationsTest extends TestCase
{
    public function testEveryEditorScriptLoadsTranslationsFromThePluginLanguagesFolder(): void
    {
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('register_block_pattern_category')->justReturn(null);
        Functions\when('register_block_pattern')->justReturn(null);
        Functions\when('register_block_type')->alias(
            static function (string $dir): object {
                $type = new \stdClass();
                $type->editor_script_handles = ['eventmesh-' . basename($dir) . '-editor-script'];

                return $type;
            }
        );

        $calls = [];
        Functions\when('wp_set_script_translations')->alias(
            static function (string $handle, string $domain, string $path) use (&$calls): bool {
                $calls[$handle] = [$domain, $path];

                return true;
            }
        );

        (new EventListBlock(new EventQuery(), new HolviHtmlParser()))->register();

        $blocks = array_map('basename', glob(EVENTMESH_PLUGIN_DIR . 'src/blocks/*', GLOB_ONLYDIR) ?: []);
        self::assertNotEmpty($blocks);

        foreach ($blocks as $block) {
            self::assertSame(
                ['eventmesh', EVENTMESH_PLUGIN_DIR . 'languages'],
                $calls['eventmesh-' . $block . '-editor-script'] ?? null,
                $block
            );
        }
    }

    public function testAFailedRegistrationIsSkipped(): void
    {
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('register_block_pattern_category')->justReturn(null);
        Functions\when('register_block_pattern')->justReturn(null);
        Functions\when('register_block_type')->justReturn(false);
        Functions\expect('wp_set_script_translations')->never();

        (new EventListBlock(new EventQuery(), new HolviHtmlParser()))->register();

        $this->addToAssertionCount(1);
    }
}
