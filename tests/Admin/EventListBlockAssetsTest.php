<?php

declare(strict_types=1);

namespace EventMesh\Tests\Admin;

use EventMesh\Tests\TestCase;

/**
 * The event-list block ships without a Node/npm build step: block.json,
 * index.js, and index.asset.php are hand-authored and must stay in sync.
 * These tests catch exactly what a broken `wp-scripts build` would - a
 * missing file, invalid JSON, or a mismatched attribute/template - without
 * needing Node installed to run them.
 */
final class EventListBlockAssetsTest extends TestCase
{
    private const BLOCK_DIR = __DIR__ . '/../../src/blocks/event-list';

    public function testBlockJsonIsValidAndReferencesExistingFiles(): void
    {
        $contents = file_get_contents(self::BLOCK_DIR . '/block.json');
        self::assertIsString($contents);

        $metadata = json_decode($contents, true);
        self::assertIsArray($metadata);

        self::assertSame('eventmesh/event-list', $metadata['name']);
        self::assertArrayNotHasKey(
            'render',
            $metadata,
            'render_callback is registered in PHP; a "render" key here would point at a file that does not exist.'
        );

        self::assertSame('file:./index.js', $metadata['editorScript']);
        self::assertFileExists(self::BLOCK_DIR . '/index.js');
    }

    public function testAssetManifestDeclaresRequiredWordPressDependencies(): void
    {
        $asset = require self::BLOCK_DIR . '/index.asset.php';

        self::assertIsArray($asset);
        self::assertIsArray($asset['dependencies']);

        foreach (['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render'] as $handle) {
            self::assertContains($handle, $asset['dependencies']);
        }

        self::assertIsString($asset['version']);
        self::assertNotSame('', $asset['version']);
    }

    public function testEveryTemplateOfferedInTheEditorExistsOnDisk(): void
    {
        $contents = file_get_contents(self::BLOCK_DIR . '/index.js');
        self::assertIsString($contents);

        preg_match_all("/value: '([a-z-]+)'/", $contents, $matches);
        $offeredTemplates = array_values(array_unique($matches[1]));

        self::assertNotEmpty($offeredTemplates);

        foreach ($offeredTemplates as $template) {
            self::assertFileExists(
                self::BLOCK_DIR . '/../../../templates/frontend/' . $template . '.php',
                sprintf('Template "%s" is offered in the block editor but has no matching template file.', $template)
            );
        }
    }

    public function testDefaultTemplateAttributeExistsOnDisk(): void
    {
        $metadata = json_decode(file_get_contents(self::BLOCK_DIR . '/block.json'), true);
        $default = $metadata['attributes']['template']['default'];

        self::assertFileExists(self::BLOCK_DIR . '/../../../templates/frontend/' . $default . '.php');
    }
}
