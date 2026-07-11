<?php

declare(strict_types=1);

namespace EventMesh\Tests\Admin;

use EventMesh\Tests\TestCase;

/**
 * Both blocks ship without a Node/npm build step, same as event-list:
 * block.json, index.js, and index.asset.php are hand-authored and must stay
 * in sync. These are plain PHP-rendered dynamic blocks (no "render" file,
 * no Block Bindings) - see EventListBlock for why.
 */
final class EventFieldAndTicketButtonBlockAssetsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function blockDirsAndNames(): array
    {
        return [
            'event-field' => [__DIR__ . '/../../src/blocks/event-field', 'eventmesh/event-field'],
            'ticket-button' => [__DIR__ . '/../../src/blocks/ticket-button', 'eventmesh/ticket-button'],
            'provider-embed' => [__DIR__ . '/../../src/blocks/provider-embed', 'eventmesh/provider-embed'],
            'other-provider-links' => [__DIR__ . '/../../src/blocks/other-provider-links', 'eventmesh/other-provider-links'],
        ];
    }

    /**
     * @dataProvider blockDirsAndNames
     */
    public function testBlockJsonIsValidAndReferencesExistingFiles(string $blockDir, string $blockName): void
    {
        $contents = file_get_contents($blockDir . '/block.json');
        self::assertIsString($contents);

        $metadata = json_decode($contents, true);
        self::assertIsArray($metadata);

        self::assertSame($blockName, $metadata['name']);
        self::assertArrayNotHasKey(
            'render',
            $metadata,
            'render_callback is registered in PHP; a "render" key here would point at a file that does not exist.'
        );
        self::assertArrayNotHasKey('bindings', $metadata['supports'] ?? [], 'This block must not rely on Block Bindings.');

        self::assertSame('file:./index.js', $metadata['editorScript']);
        self::assertFileExists($blockDir . '/index.js');
    }

    /**
     * @dataProvider blockDirsAndNames
     */
    public function testAssetManifestIsValid(string $blockDir): void
    {
        $asset = require $blockDir . '/index.asset.php';

        self::assertIsArray($asset);
        self::assertIsArray($asset['dependencies']);
        self::assertContains('wp-blocks', $asset['dependencies']);
        self::assertContains('wp-block-editor', $asset['dependencies']);

        self::assertIsString($asset['version']);
        self::assertNotSame('', $asset['version']);
    }

    public function testEventFieldDefaultFieldAttributeIsARecognizedField(): void
    {
        $metadata = json_decode(
            file_get_contents(__DIR__ . '/../../src/blocks/event-field/block.json'),
            true
        );

        self::assertContains($metadata['attributes']['field']['default'], ['starts_at', 'title', 'venue']);
    }
}
