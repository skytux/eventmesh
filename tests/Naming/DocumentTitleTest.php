<?php

declare(strict_types=1);

namespace EventMesh\Tests\Naming;

use Brain\Monkey\Functions;
use EventMesh\Content\EventPostType;
use EventMesh\Naming\DocumentTitle;
use EventMesh\Tests\TestCase;
use EventMesh\Translation\TranslationStore;

final class DocumentTitleTest extends TestCase
{
    private \WP_Post $post;

    /** @var array<string, string> */
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->post = new \WP_Post(7, EventPostType::NAME, 'Ecstatic Dance 14.11.2026 / Ihmeidentekijä');
        $this->meta = [];

        Functions\when('get_post_meta')->alias(fn (int $id, string $key) => $this->meta[$key] ?? '');
        Functions\when('determine_locale')->justReturn('en_US');
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Helsinki'));
        Functions\when('is_admin')->justReturn(false);
    }

    public function testBootAddsTheSinglePostTitleFilter(): void
    {
        $filter = new DocumentTitle();
        $filter->boot();

        self::assertNotFalse(has_filter('single_post_title', [$filter, 'filterTitle']));
    }

    public function testEventTabTitleHasNoDate(): void
    {
        self::assertSame(
            'Ecstatic Dance / Ihmeidentekijä',
            (new DocumentTitle())->filterTitle($this->post->post_title, $this->post)
        );
    }

    public function testEventTabTitleIsInThePageLanguage(): void
    {
        $this->meta[TranslationStore::META_ORIGINAL_LANG] = 'fi';
        $this->meta[TranslationStore::metaKey('en', 'title')] = 'Ecstatic Dance 14.11.2026 / Miracle Maker';
        $this->meta[TranslationStore::metaKey('en', 'hash')] = TranslationStore::originalHash($this->post);
        $this->meta[TranslationStore::metaKey('en', 'status')] = TranslationStore::STATUS_AUTO;

        self::assertSame(
            'Ecstatic Dance / Miracle Maker',
            (new DocumentTitle())->filterTitle($this->post->post_title, $this->post)
        );
    }

    public function testOtherPostsAndAdminKeepTheirTitle(): void
    {
        $page = new \WP_Post(8, 'page', 'About 1.1.2026');

        self::assertSame('About 1.1.2026', (new DocumentTitle())->filterTitle('About 1.1.2026', $page));

        Functions\when('is_admin')->justReturn(true);

        self::assertSame(
            'Ecstatic Dance 14.11.2026 / Ihmeidentekijä',
            (new DocumentTitle())->filterTitle($this->post->post_title, $this->post)
        );
    }
}
