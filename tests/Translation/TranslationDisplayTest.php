<?php

declare(strict_types=1);

namespace EventMesh\Tests\Translation;

use Brain\Monkey\Functions;
use EventMesh\Tests\TestCase;
use EventMesh\Translation\TranslationDisplay;

final class TranslationDisplayTest extends TestCase
{
    /**
     * @return array{title: string, content: string, hash: string, status: string}
     */
    private static function translation(string $hash = 'h1'): array
    {
        return ['title' => 'Dance night', 'content' => '<p>Text</p>', 'hash' => $hash, 'status' => 'auto'];
    }

    public function testShowsTranslationForAnotherPageLanguage(): void
    {
        self::assertTrue(TranslationDisplay::choose('en', 'fi', self::translation(), 'h1'));
    }

    public function testShowsOriginalWhenPageIsInTheOriginalLanguage(): void
    {
        self::assertFalse(TranslationDisplay::choose('fi', 'fi', self::translation(), 'h1'));
    }

    public function testShowsOriginalWhenTheOriginalHasChanged(): void
    {
        self::assertFalse(TranslationDisplay::choose('en', 'fi', self::translation('h0'), 'h1'));
        self::assertFalse(TranslationDisplay::choose('en', 'fi', self::translation(''), 'h1'));
    }

    public function testShowsOriginalWhenLanguageUnknownOrTranslationEmpty(): void
    {
        self::assertFalse(TranslationDisplay::choose('', 'fi', self::translation(), 'h1'));
        self::assertFalse(TranslationDisplay::choose('en', '', self::translation(), 'h1'));
        self::assertFalse(
            TranslationDisplay::choose('en', 'fi', ['title' => '', 'content' => '', 'hash' => 'h1', 'status' => 'auto'], 'h1')
        );
    }

    public function testPageLanguageComesFromTheLocaleWithoutPolylang(): void
    {
        if (function_exists('pll_current_language')) {
            self::markTestSkipped('Polylang stub defined by another test.');
        }

        Functions\when('determine_locale')->justReturn('en_GB');

        self::assertSame('en', TranslationDisplay::currentLanguage());
    }

    public function testPolylangLanguageWinsOverTheLocale(): void
    {
        Functions\when('pll_current_language')->justReturn('sv');
        Functions\when('determine_locale')->justReturn('en_GB');

        self::assertSame('sv', TranslationDisplay::currentLanguage());
    }

    /**
     * @param array<string, array{title: string, content: string, hash: string, status: string}> $stored
     */
    private static function reader(array $stored): \Closure
    {
        $empty = ['title' => '', 'content' => '', 'hash' => '', 'status' => ''];

        return static fn (string $lang): array => $stored[$lang] ?? $empty;
    }

    public function testPicksThePageLanguageTranslationFirst(): void
    {
        $sv = ['title' => 'Dansafton', 'content' => '<p>Text</p>', 'hash' => 'h1', 'status' => 'auto'];

        self::assertSame(
            $sv,
            TranslationDisplay::pick('sv', 'en', 'fi', 'h1', self::reader(['sv' => $sv, 'en' => self::translation()]))
        );
    }

    public function testFallsBackToTheDefaultLanguageNotTheOriginal(): void
    {
        // A Finnish event with only an English translation, on a Swedish page.
        self::assertSame(
            self::translation(),
            TranslationDisplay::pick('sv', 'en', 'fi', 'h1', self::reader(['en' => self::translation()]))
        );
    }

    public function testFallsBackToTheOriginalWhenItIsInTheDefaultLanguage(): void
    {
        self::assertNull(
            TranslationDisplay::pick('sv', 'en', 'en', 'h1', self::reader(['fi' => self::translation()]))
        );
    }

    public function testFallsBackToTheOriginalWhenTheDefaultTranslationIsMissingOrStale(): void
    {
        self::assertNull(TranslationDisplay::pick('sv', 'en', 'fi', 'h1', self::reader([])));
        self::assertNull(
            TranslationDisplay::pick('sv', 'en', 'fi', 'h1', self::reader(['en' => self::translation('h0')]))
        );
        self::assertNull(TranslationDisplay::pick('sv', '', 'fi', 'h1', self::reader(['en' => self::translation()])));
    }

    public function testShowsTheOriginalOnAPageInTheOriginalLanguage(): void
    {
        self::assertNull(TranslationDisplay::pick('fi', 'en', 'fi', 'h1', self::reader(['en' => self::translation()])));
    }

    public function testDefaultLanguageComesFromPolylang(): void
    {
        Functions\when('pll_default_language')->justReturn('en');
        Functions\when('get_locale')->justReturn('fi');

        self::assertSame('en', TranslationDisplay::defaultLanguage());
    }

    public function testLabelHasItsClass(): void
    {
        Functions\when('esc_html__')->returnArg();

        self::assertSame(
            '<p class="eventmesh-auto-translated">Automatically translated</p>',
            TranslationDisplay::label()
        );
    }
}
