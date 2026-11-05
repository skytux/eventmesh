<?php

declare(strict_types=1);

namespace EventMesh\Tests\Naming;

use Brain\Monkey\Functions;
use EventMesh\Content\EventPostType;
use EventMesh\Naming\DisplayTitle;
use EventMesh\Tests\TestCase;
use EventMesh\Translation\TranslationStore;

final class DisplayTitleTest extends TestCase
{
    private \WP_Post $post;

    /** @var array<string, string> */
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->post = new \WP_Post(7, EventPostType::NAME, 'Ecstatic Dance 14.11.2026 / Ihmeidentekijä', '<p>Tanssi</p>');
        $this->meta = [];

        Functions\when('get_post')->alias(fn ($id) => 7 === (int) $id ? $this->post : null);
        Functions\when('get_post_meta')->alias(fn (int $id, string $key) => $this->meta[$key] ?? '');
        Functions\when('determine_locale')->justReturn('en_US');
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Helsinki'));
    }

    private function storeTranslation(string $lang, string $title, ?string $hash = null): void
    {
        $this->meta[TranslationStore::META_ORIGINAL_LANG] = 'fi';
        $this->meta[TranslationStore::metaKey($lang, 'title')] = $title;
        $this->meta[TranslationStore::metaKey($lang, 'hash')] = $hash ?? TranslationStore::originalHash($this->post);
        $this->meta[TranslationStore::metaKey($lang, 'status')] = TranslationStore::STATUS_AUTO;
    }

    public function testOriginalTitleWithoutTheDate(): void
    {
        self::assertSame('Ecstatic Dance / Ihmeidentekijä', DisplayTitle::of(7, 'fi'));
        self::assertSame('Ecstatic Dance / Ihmeidentekijä', eventmesh_display_title($this->post, 'en'));
    }

    public function testStoredTranslationForThatLanguage(): void
    {
        $this->storeTranslation('en', 'Ecstatic Dance 14.11.2026 / Miracle Maker');

        self::assertSame('Ecstatic Dance / Miracle Maker', eventmesh_display_title(7, 'en'));
        self::assertSame('Ecstatic Dance / Miracle Maker', eventmesh_display_title(7, 'en_GB'));
        self::assertSame('Ecstatic Dance / Ihmeidentekijä', eventmesh_display_title(7, 'fi'));
    }

    public function testEmptyLanguageMeansTheCurrentPageLanguage(): void
    {
        if (function_exists('pll_current_language')) {
            self::markTestSkipped('Polylang stub defined by another test.');
        }

        $this->storeTranslation('en', 'Ecstatic Dance 14.11.2026 / Miracle Maker');

        self::assertSame('Ecstatic Dance / Miracle Maker', eventmesh_display_title(7));
    }

    public function testATranslationOfAnOlderOriginalIsNotUsed(): void
    {
        $this->storeTranslation('en', 'Old name 1.1.2026', 'old-hash');

        self::assertSame('Ecstatic Dance / Ihmeidentekijä', eventmesh_display_title(7, 'en'));
    }

    public function testNotAnEventGivesAnEmptyString(): void
    {
        self::assertSame('', eventmesh_display_title(8, 'en'));
        self::assertSame('', eventmesh_display_title(new \WP_Post(9, 'post', 'A post 1.1.2026'), 'en'));
    }
}
