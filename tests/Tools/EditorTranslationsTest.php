<?php

declare(strict_types=1);

namespace EventMesh\Tests\Tools;

use EventMesh\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/tools/i18n.php';

/**
 * The JSON translations the block editor scripts load (tools/i18n.php, used
 * by tools/make-json.php and tools/build-zip.php).
 */
final class EditorTranslationsTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ('' !== $this->tmp) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }

            rmdir($this->tmp);
        }

        parent::tearDown();
    }

    public function testReadsLiteralCallsWithTheirTranslatorsComment(): void
    {
        $source = <<<'JS'
var __ = i18n.__;
var a = __( 'Plain', 'eventmesh' );
var b = sprintf(
	/* translators: %s: the ticket price. */
	__( 'Tickets %s', 'eventmesh' ),
	price
);
var c = __(
	'Say "hi", it\'s fine',
	'eventmesh'
);
var d = _x( 'Post', 'noun', 'eventmesh' );
var e = __( 'Other', 'other-domain' );
var f = __( name, 'eventmesh' );
JS;

        $found = eventmesh_js_strings($source);

        self::assertSame(
            ['Plain', 'Tickets %s', 'Say "hi", it\'s fine', 'Post'],
            array_column($found['strings'], 'msgid')
        );
        self::assertSame('%s: the ticket price.', $found['strings'][1]['comment']);
        self::assertSame(5, $found['strings'][1]['line']);
        self::assertSame('noun', $found['strings'][3]['context']);
        self::assertSame([13, 14], $found['skipped']);
    }

    public function testEveryEditorStringHasAFinnishTranslation(): void
    {
        $root = dirname(__DIR__, 2);
        $finnish = eventmesh_read_po($root . '/languages/eventmesh-fi.po');
        $missing = [];

        foreach (array_keys(eventmesh_block_editor_scripts($root)) as $relative) {
            $found = eventmesh_js_strings((string) file_get_contents($root . '/' . $relative));
            self::assertSame([], $found['skipped'], $relative);

            foreach ($found['strings'] as $string) {
                if (! isset($finnish[$string['msgid']])) {
                    $missing[] = $relative . ': ' . $string['msgid'];
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testWritesOneJsonFilePerScriptNamedByHandle(): void
    {
        $root = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/eventmesh-json-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/languages', 0o777, true);
        mkdir($this->tmp . '/src/blocks/ticket-button', 0o777, true);
        copy($root . '/languages/eventmesh-fi.po', $this->tmp . '/languages/eventmesh-fi.po');

        foreach (['block.json', 'index.js'] as $file) {
            copy($root . '/src/blocks/ticket-button/' . $file, $this->tmp . '/src/blocks/ticket-button/' . $file);
        }

        // A file from an earlier run is replaced, not kept.
        file_put_contents($this->tmp . '/languages/eventmesh-fi-stale-editor-script.json', '{}');

        $written = eventmesh_make_json($this->tmp);

        $target = $this->tmp . '/languages/eventmesh-fi-eventmesh-ticket-button-editor-script.json';
        self::assertSame([$target], $written);
        self::assertFileDoesNotExist($this->tmp . '/languages/eventmesh-fi-stale-editor-script.json');

        $json = json_decode((string) file_get_contents($target), true);
        self::assertIsArray($json);
        $messages = $json['locale_data']['messages'];

        self::assertSame('fi', $messages['']['lang']);
        self::assertSame('nplurals=2; plural=(n != 1);', $messages['']['plural-forms']);
        self::assertSame(['Liput %s'], $messages['Tickets %s']);
        self::assertSame(['Näytä hinta'], $messages['Show price']);
        self::assertSame('src/blocks/ticket-button/index.js', $json['source']);
    }
}
