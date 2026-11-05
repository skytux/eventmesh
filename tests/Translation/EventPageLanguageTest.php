<?php

declare(strict_types=1);

namespace EventMesh\Tests\Translation;

use Brain\Monkey\Functions;
use EventMesh\Content\EventPostType;
use EventMesh\Tests\TestCase;
use EventMesh\Translation\EventPageLanguage;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * In separate processes: the Polylang functions stubbed here would otherwise
 * stay defined and make the tests "without Polylang" skip.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EventPageLanguageTest extends TestCase
{
    /** The language Polylang reads from the address in the current test. */
    private string $addressLanguage = '';

    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $_SERVER;
        $_GET = [];
        $_COOKIE = [];
        $this->addressLanguage = '';

        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('is_feed')->justReturn(false);
        Functions\when('home_url')->justReturn('https://example.test/');
        Functions\when('wp_parse_url')->alias(static fn ($url, $component = -1) => parse_url($url, $component));
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_key')->alias(
            static fn ($key) => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key))
        );
        Functions\when('sanitize_text_field')->alias(static fn ($text) => trim((string) $text));
        Functions\when('sanitize_url')->returnArg(1);
        Functions\when('pll_languages_list')->justReturn(['en', 'fi']);
        Functions\when('add_query_arg')->alias(
            static fn ($key, $value, $url) => $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . $value
        );
        Functions\when('remove_query_arg')->alias(
            static fn ($key, $url) => rtrim((string) preg_replace('/([?&])' . $key . '=[^&]*&?/', '$1', $url), '?&')
        );

        $links = new class ($this) {
            public function __construct(private EventPageLanguageTest $test)
            {
            }

            public function get_language_from_url(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName
            {
                return $this->test->addressLanguage();
            }
        };
        $model = new class {
            public function get_language(string $slug): object // phpcs:ignore PSR1.Methods.CamelCapsMethodName
            {
                return (object) ['slug' => $slug];
            }
        };
        $polylang = (object) ['links_model' => $links, 'model' => $model];
        Functions\when('PLL')->justReturn($polylang);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = [];
        $_COOKIE = [];
        parent::tearDown();
    }

    public function addressLanguage(): string
    {
        return $this->addressLanguage;
    }

    private function request(string $uri, string $acceptLanguage = ''): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $acceptLanguage;
    }

    public function testTheMarkerWinsOverTheSessionAndTheBrowser(): void
    {
        self::assertSame(
            ['language' => 'fi', 'source' => 'marker'],
            EventPageLanguage::choose('fi', 'en', 'en-US,en', ['en', 'fi'])
        );
    }

    public function testTheSessionWinsOverTheBrowser(): void
    {
        self::assertSame(
            ['language' => 'fi', 'source' => 'session'],
            EventPageLanguage::choose('', 'fi', 'en-US,en', ['en', 'fi'])
        );
    }

    public function testAnUnknownMarkerFallsThroughToTheNextRule(): void
    {
        self::assertSame(
            ['language' => 'en', 'source' => 'browser'],
            EventPageLanguage::choose('de', '', 'en-GB', ['en', 'fi'])
        );
    }

    public function testNothingKnownLeavesPolylangsDefault(): void
    {
        self::assertSame(
            ['language' => '', 'source' => 'default'],
            EventPageLanguage::choose('', '', 'de-DE,sv;q=0.5', ['en', 'fi'])
        );
    }

    public function testTheBrowserLanguageWithTheHighestQualityIsPicked(): void
    {
        self::assertSame('fi', EventPageLanguage::preferredInBrowser('de-DE,en;q=0.5,fi-FI;q=0.8', ['en', 'fi']));
        self::assertSame(
            'en',
            EventPageLanguage::preferredInBrowser('en-US,fi', ['en', 'fi']),
            'Equal quality: the first listed.'
        );
        self::assertSame('', EventPageLanguage::preferredInBrowser('', ['en', 'fi']));
    }

    public function testOnlyEventAddressesUnderTheHomePathAreEventPaths(): void
    {
        self::assertTrue(EventPageLanguage::isEventPath('/events/dance-night/', '/'));
        self::assertTrue(EventPageLanguage::isEventPath('/events/', '/'));
        self::assertTrue(EventPageLanguage::isEventPath('/site/events/x/', '/site/'));
        self::assertFalse(EventPageLanguage::isEventPath('/fi/tietoa/', '/'));
        self::assertFalse(EventPageLanguage::isEventPath('/eventsx/', '/'));
        self::assertFalse(EventPageLanguage::isEventPath('/events/x/', '/site/'));
    }

    public function testAMarkedEventPageIsGivenToPolylangInThatLanguage(): void
    {
        $this->request('/events/dance-night/?em_lang=fi', 'en-US');
        $_GET[EventPageLanguage::MARKER] = 'fi';

        $language = (new EventPageLanguage())->filterPolylangLanguage((object) ['slug' => 'en']);

        self::assertSame('fi', $language->slug);
    }

    public function testTheBrowserLanguageIsUsedWithoutAMarkerOrSession(): void
    {
        $this->request('/events/dance-night/', 'fi-FI,fi;q=0.9,en;q=0.8');

        $language = (new EventPageLanguage())->filterPolylangLanguage((object) ['slug' => 'en']);

        self::assertSame('fi', $language->slug);
    }

    public function testOtherPagesAreLeftToPolylang(): void
    {
        $this->request('/tietoa/?em_lang=fi', 'fi-FI');
        $_GET[EventPageLanguage::MARKER] = 'fi';
        $polylangChoice = (object) ['slug' => 'en'];

        self::assertSame($polylangChoice, (new EventPageLanguage())->filterPolylangLanguage($polylangChoice));
    }

    public function testALanguageInTheAddressWins(): void
    {
        $this->request('/events/dance-night/?em_lang=fi');
        $_GET[EventPageLanguage::MARKER] = 'fi';
        $this->addressLanguage = 'en';
        $polylangChoice = (object) ['slug' => 'en'];

        self::assertSame($polylangChoice, (new EventPageLanguage())->filterPolylangLanguage($polylangChoice));
    }

    public function testTheAdminIsLeftToPolylang(): void
    {
        $this->request('/events/dance-night/', 'fi-FI');
        Functions\when('is_admin')->justReturn(true);

        self::assertFalse((new EventPageLanguage())->filterPolylangLanguage(false));
    }

    public function testEventLinksCarryTheCurrentLanguage(): void
    {
        Functions\when('pll_current_language')->justReturn('fi');
        $event = new \WP_Post(7, EventPostType::NAME, 'Dance night');
        $page = new \WP_Post(8, 'page', 'Info');
        $languages = new EventPageLanguage();

        self::assertSame(
            'https://example.test/events/x/?em_lang=fi',
            $languages->markLink('https://example.test/events/x/', $event)
        );
        self::assertSame('https://example.test/info/', $languages->markLink('https://example.test/info/', $page));
        self::assertSame(
            'https://example.test/events/x/?em_lang=en',
            $languages->markLink('https://example.test/events/x/?em_lang=en', $event),
            'A link with a marker keeps it.'
        );
    }

    public function testTheLanguageSwitchOnAnEventPageStaysOnTheEvent(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_permalink')->justReturn('https://example.test/events/x/?em_lang=en');

        $url = (new EventPageLanguage())->filterTranslationUrl(null, (object) ['slug' => 'fi'], 7);

        self::assertSame('https://example.test/events/x/?em_lang=fi', $url);
    }

    public function testTheLanguageSwitchElsewhereIsLeftToPolylang(): void
    {
        Functions\when('is_singular')->justReturn(false);
        Functions\when('is_post_type_archive')->justReturn(false);

        self::assertNull((new EventPageLanguage())->filterTranslationUrl(null, (object) ['slug' => 'fi'], 8));
        self::assertSame(
            'https://example.test/fi/',
            (new EventPageLanguage())->filterTranslationUrl('https://example.test/fi/', (object) ['slug' => 'fi'], 8)
        );
    }
}
