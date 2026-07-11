<?php

declare(strict_types=1);

namespace EventMesh\Tests\Content;

use Brain\Monkey\Functions;
use EventMesh\Content\EventPostType;
use EventMesh\Tests\TestCase;

final class EventPostTypeSingleTemplateTest extends TestCase
{
    public function testUsesTheBundledTemplateOnABlockThemeWithNoCustomTemplateYet(): void
    {
        Functions\when('wp_is_block_theme')->justReturn(true);
        Functions\when('get_stylesheet')->justReturn('mytheme');
        Functions\when('get_block_template')->justReturn(null);

        $post = new \WP_Post();
        $post->post_type = EventPostType::NAME;
        Functions\when('get_post')->justReturn($post);

        $result = (new EventPostType())->singleTemplate('/theme/single.php');

        self::assertStringContainsString(
            'templates/frontend/single-event.php',
            $result,
            'A block theme alone should not silently drop the bundled template (with its working venue/date/tickets output) until the user has actually built their own.'
        );
    }

    public function testDefersToTheThemesOwnTemplateOnceACustomBlockTemplateExists(): void
    {
        Functions\when('wp_is_block_theme')->justReturn(true);
        Functions\when('get_stylesheet')->justReturn('mytheme');
        Functions\when('get_block_template')->justReturn((object) ['slug' => 'single-eventmesh_event']);

        $result = (new EventPostType())->singleTemplate('/theme/single.php');

        self::assertSame(
            '/theme/single.php',
            $result,
            'Once the user has built their own single-eventmesh_event template in the Site Editor, defer to it entirely.'
        );
    }

    public function testUsesTheBundledTemplateOnAClassicTheme(): void
    {
        Functions\when('wp_is_block_theme')->justReturn(false);

        $post = new \WP_Post();
        $post->post_type = EventPostType::NAME;
        Functions\when('get_post')->justReturn($post);

        $result = (new EventPostType())->singleTemplate('/theme/single.php');

        self::assertStringContainsString('templates/frontend/single-event.php', $result);
    }
}
