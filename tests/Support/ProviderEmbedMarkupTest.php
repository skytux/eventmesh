<?php

declare(strict_types=1);

namespace EventMesh\Tests\Support;

use Brain\Monkey\Functions;
use EventMesh\Support\ProviderEmbedMarkup;
use EventMesh\Tests\TestCase;

final class ProviderEmbedMarkupTest extends TestCase
{
    private const IFRAME = '<iframe title="Spotify player" src="https://open.spotify.com/embed/track/abc"></iframe>';

    protected function setUp(): void
    {
        parent::setUp();

        // EmbedHtmlSanitizer::sanitize() runs wp_kses; pass it through so the
        // test exercises this class's own strategy logic, not wp_kses.
        Functions\when('wp_kses')->returnArg(1);
        Functions\when('__')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('wp_parse_url')->alias(static fn (string $url) => parse_url($url));
    }

    /**
     * The strategy depends on two options at once, so each test states both
     * rather than stubbing get_option to one blanket value.
     */
    private function withOptions(string $facade, string $defer): void
    {
        Functions\when('get_option')->alias(
            static function (string $name, $default = false) use ($facade, $defer) {
                return match ($name) {
                    'eventmesh_embed_facade' => $facade,
                    'eventmesh_defer_embeds' => $defer,
                    default => $default,
                };
            }
        );
    }

    /**
     * @return \ArrayObject<int, string> Index 0 holds the handle enqueued, '' if none was.
     *                                   A shared object rather than a by-reference
     *                                   local, so the stub and the assertion are
     *                                   certain to be looking at the same value.
     */
    private function captureEnqueuedHandle(): \ArrayObject
    {
        $capture = new \ArrayObject(['']);

        Functions\when('wp_enqueue_script')->alias(
            static function (string $enqueued) use ($capture): void {
                $capture[0] = $enqueued;
            }
        );

        return $capture;
    }

    public function testRendersAClickToPlayFacadeByDefault(): void
    {
        $this->withOptions('1', '0');
        $enqueued = $this->captureEnqueuedHandle();

        $html = ProviderEmbedMarkup::render(self::IFRAME);

        self::assertStringContainsString('eventmesh-embed-facade', $html);

        // Nothing is requested from the provider until the button is clicked -
        // asserted against the markup the browser actually runs, since the
        // <noscript> copy after it must keep a live src and would otherwise
        // mask a regression here.
        [$scripted, $noscript] = explode('<noscript>', $html, 2);

        self::assertStringContainsString('data-src="https://open.spotify.com/embed/track/abc"', $scripted);
        self::assertStringNotContainsString(' src="https://open.spotify.com', $scripted);
        self::assertStringContainsString(' src="https://open.spotify.com', $noscript);
        self::assertSame('eventmesh-embed-facade', $enqueued[0]);
    }

    public function testTheFacadeButtonUsesTheLabelItWasGiven(): void
    {
        $this->withOptions('1', '0');
        $this->captureEnqueuedHandle();

        $html = ProviderEmbedMarkup::render(self::IFRAME, ['playLabel' => 'Hear the set']);

        self::assertStringContainsString('>Hear the set</span>', $html);
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @dataProvider noLabelProvider
     */
    public function testTheFacadeButtonFallsBackToTheDefaultLabel(array $attributes): void
    {
        $this->withOptions('1', '0');
        $this->captureEnqueuedHandle();

        $html = ProviderEmbedMarkup::render(self::IFRAME, $attributes);

        self::assertStringContainsString('>Listen to this DJ</span>', $html);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function noLabelProvider(): array
    {
        return [
            'rendered outside a block' => [[]],
            'text cleared in the editor' => [['playLabel' => '']],
            'whitespace only' => [['playLabel' => '   ']],
        ];
    }

    /**
     * The whole point of the suffix: "Listen to this DJ" + " on " + "Spotify".
     */
    public function testComposesPrefixLabelSuffixAndPlatformName(): void
    {
        $this->withOptions('1', '0');
        $this->captureEnqueuedHandle();

        $html = ProviderEmbedMarkup::render(
            self::IFRAME,
            ['suffix' => ' on ', 'showPlatform' => true]
        );

        self::assertStringContainsString('>Listen to this DJ on Spotify</span>', $html);
    }

    public function testUsesPrefixAndSuffixSpacingExactlyAsTyped(): void
    {
        $this->withOptions('1', '0');
        $this->captureEnqueuedHandle();

        $html = ProviderEmbedMarkup::render(
            self::IFRAME,
            ['prefix' => 'Now: ', 'playLabel' => 'Hear the set', 'suffix' => ' now']
        );

        self::assertStringContainsString('>Now: Hear the set now</span>', $html);
    }

    /**
     * The suffix is independent of the platform name: it is still written out
     * as typed, trailing space and all, when the platform isn't asked for.
     */
    public function testLeavesThePlatformNameOffUntilItIsAskedFor(): void
    {
        $this->withOptions('1', '0');
        $this->captureEnqueuedHandle();

        $html = ProviderEmbedMarkup::render(self::IFRAME, ['suffix' => ' on ']);

        self::assertStringContainsString('>Listen to this DJ on </span>', $html);
        self::assertStringNotContainsString('Spotify</span>', $html);
    }

    /**
     * A joining suffix with nothing after it reads as a sentence cut off
     * mid-way ("Listen to this DJ on"), so it goes when the platform does.
     */
    public function testDropsTheJoiningSuffixWhenThePlatformCannotBeNamed(): void
    {
        $this->withOptions('1', '0');
        $this->captureEnqueuedHandle();

        $html = ProviderEmbedMarkup::render(
            '<iframe title="Embedded media player" src="https://players.example.com/x"></iframe>',
            ['suffix' => ' on ', 'showPlatform' => true]
        );

        self::assertStringContainsString('>Listen to this DJ</span>', $html);
    }

    /**
     * The visible text is the button's accessible name, so there must be no
     * aria-label competing with it - a stale one would silently override
     * whatever the editor typed for screen reader users.
     */
    public function testTheFacadeButtonHasNoCompetingAriaLabel(): void
    {
        $this->withOptions('1', '0');
        $this->captureEnqueuedHandle();

        $html = ProviderEmbedMarkup::render(self::IFRAME, ['playLabel' => 'Hear the set']);

        self::assertStringNotContainsString('aria-label', $html);
    }

    public function testFacadeTakesPrecedenceOverScrollDeferral(): void
    {
        $this->withOptions('1', '1');
        $enqueued = $this->captureEnqueuedHandle();

        ProviderEmbedMarkup::render(self::IFRAME);

        self::assertSame(
            'eventmesh-embed-facade',
            $enqueued[0],
            'With both on, the facade wins - loading nothing beats loading later.'
        );
    }

    public function testReturnsAPlainLiveIframeWhenBothStrategiesAreOff(): void
    {
        $this->withOptions('0', '0');
        $enqueued = $this->captureEnqueuedHandle();

        $html = ProviderEmbedMarkup::render(self::IFRAME);

        self::assertStringContainsString('src="https://open.spotify.com/embed/track/abc"', $html);
        self::assertStringNotContainsString('data-src', $html);
        self::assertStringNotContainsString('<noscript>', $html);
        self::assertStringNotContainsString('eventmesh-embed-facade', $html);
        self::assertSame('', $enqueued[0], 'No loader script belongs on the page when both strategies are off.');
    }

    public function testDefersToDataSrcWithANoscriptFallbackWhenOnlyDeferralIsOn(): void
    {
        $this->withOptions('0', '1');
        $enqueued = $this->captureEnqueuedHandle();

        $html = ProviderEmbedMarkup::render(self::IFRAME);

        // The visible iframe carries data-src (not a live src) so nothing loads
        // until the loader promotes it.
        self::assertStringContainsString('data-src="https://open.spotify.com/embed/track/abc"', $html);
        // The <noscript> copy keeps a real src so no-JS visitors still get it.
        self::assertStringContainsString('<noscript><iframe', $html);
        self::assertStringContainsString('</noscript>', $html);
        self::assertStringNotContainsString('eventmesh-embed-facade', $html);
        self::assertSame('eventmesh-embed-lazy', $enqueued[0]);
    }

    /**
     * An embed with no src can't be withheld, so hiding it behind a button
     * would trade a working embed for one that reveals nothing on click.
     */
    public function testLeavesASrclessEmbedAloneRatherThanHidingItBehindAButton(): void
    {
        $this->withOptions('1', '0');
        $this->captureEnqueuedHandle();

        $html = ProviderEmbedMarkup::render('<iframe title="Spotify player"></iframe>');

        self::assertStringNotContainsString('eventmesh-embed-facade', $html);
        self::assertStringContainsString('<iframe', $html);
    }

    public function testReturnsEmptyStringForEmptyInput(): void
    {
        $this->withOptions('1', '1');

        self::assertSame('', ProviderEmbedMarkup::render(''));
    }
}
