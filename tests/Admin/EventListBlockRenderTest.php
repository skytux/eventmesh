<?php

declare(strict_types=1);

namespace EventMesh\Tests\Admin;

use Brain\Monkey\Functions;
use EventMesh\Admin\EventListBlock;
use EventMesh\Connectors\Holvi\HolviHtmlParser;
use EventMesh\Content\EventQuery;
use EventMesh\Tests\TestCase;

/**
 * Covers EventListBlock::render() end to end - it's what actually turns a
 * "count"/"template" attribute pair into HTML, by including one of the
 * plain PHP templates in templates/frontend/. Only the field-level
 * renderers (renderTitleField() etc.) had coverage before; this fills the
 * one remaining gap the codebase review flagged.
 */
final class EventListBlockRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_html_e')->alias(
            static function (string $text): void {
                echo $text;
            }
        );
        Functions\when('esc_html')->alias(static fn ($value) => $value);
        Functions\when('esc_attr')->alias(static fn ($value) => $value);
        Functions\when('esc_url')->alias(static fn ($value) => $value);
        Functions\when('wp_kses')->returnArg(1);
        Functions\when('sanitize_file_name')->alias(static fn (string $name) => $name);
        Functions\when('get_permalink')->justReturn('');
        Functions\when('get_the_post_thumbnail_url')->justReturn('');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key = '', bool $single = false) {
                return '' === $key ? [] : '';
            }
        );
    }

    private function block(): EventListBlock
    {
        return new EventListBlock(new EventQuery(), new HolviHtmlParser());
    }

    public function testRenderShowsTheNoEventsMessageWhenThereAreNone(): void
    {
        $this->queueQueryResults([]);

        $html = $this->block()->render([]);

        self::assertStringContainsString('No events are available yet.', $html);
    }

    public function testRenderDefaultsToTheListTemplate(): void
    {
        $this->queueQueryResults([]);

        $html = $this->block()->render([]);

        self::assertStringContainsString('eventmesh-events-list', $html);
    }

    public function testRenderUsesTheCardTemplateWhenRequested(): void
    {
        $this->queueQueryResults([]);

        $html = $this->block()->render(['template' => 'events-card']);

        self::assertStringContainsString('eventmesh-events-grid', $html);
        self::assertStringNotContainsString('eventmesh-events-list__items', $html);
    }

    public function testRenderFallsBackToTheListTemplateForAnUnknownTemplateName(): void
    {
        $this->queueQueryResults([]);

        $html = $this->block()->render(['template' => 'does-not-exist']);

        self::assertStringContainsString('eventmesh-events-list', $html);
    }

    public function testRenderPassesTheCountAttributeThroughAsPostsPerPage(): void
    {
        $this->queueQueryResults([]);

        $this->block()->render(['count' => 3]);

        self::assertSame(3, \WP_Query::$lastArgs['posts_per_page']);
    }

    public function testRenderDefaultsCountToSixWhenNotProvided(): void
    {
        $this->queueQueryResults([]);

        $this->block()->render([]);

        self::assertSame(6, \WP_Query::$lastArgs['posts_per_page']);
    }

    public function testRenderShowsAnEventsTitle(): void
    {
        $post = new \WP_Post(1, 'eventmesh_event', 'A Real Event');
        $this->queueQueryResults([$post]);

        $html = $this->block()->render([]);

        self::assertStringContainsString('A Real Event', $html);
    }
}
