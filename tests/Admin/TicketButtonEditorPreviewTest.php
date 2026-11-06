<?php

declare(strict_types=1);

namespace EventMesh\Tests\Admin;

use EventMesh\Tests\TestCase;

/**
 * The ticket button's editor preview (src/blocks/ticket-button/index.js) is
 * hand-written JavaScript without its own test runner, so these check that it
 * keeps reading the same data and texts as the PHP render.
 */
final class TicketButtonEditorPreviewTest extends TestCase
{
    private function script(): string
    {
        return (string) file_get_contents(EVENTMESH_PLUGIN_DIR . 'src/blocks/ticket-button/index.js');
    }

    public function testSoldOutPreviewReadsTheSameMetaAsTheFrontEnd(): void
    {
        $script = $this->script();

        self::assertStringContainsString("meta._eventmesh_manual_sold_out", $script);
        self::assertStringContainsString("meta._eventmesh_sold_out", $script);
        self::assertStringContainsString("__( 'Sold out', 'eventmesh' )", $script);
        self::assertStringContainsString(
            "esc_html__('Sold out', 'eventmesh')",
            (string) file_get_contents(EVENTMESH_PLUGIN_DIR . 'src/Admin/EventListBlock.php')
        );

        // The editor can only read meta the REST API exposes.
        $postType = (string) file_get_contents(EVENTMESH_PLUGIN_DIR . 'src/Content/EventPostType.php');
        self::assertMatchesRegularExpression(
            "/\\\$fields = \[[^\]]*'_eventmesh_sold_out'/s",
            $postType
        );
        self::assertStringContainsString("'_eventmesh_manual_sold_out'", $postType);
    }

    public function testLabelFieldEditsTheTextAttribute(): void
    {
        self::assertMatchesRegularExpression(
            "/label: __\( 'Label', 'eventmesh' \).*?setAttributes\( \{ text: value \} \)/s",
            $this->script()
        );
    }
}
