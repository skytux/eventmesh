<?php

declare(strict_types=1);

namespace EventMesh\Tests\Support;

use Brain\Monkey\Functions;
use EventMesh\Support\EmbedHtmlSanitizer;
use EventMesh\Tests\TestCase;

/**
 * wp_kses() itself is real WordPress core (not something Brain Monkey
 * re-implements) - this only verifies EmbedHtmlSanitizer calls it with an
 * allowlist restricted to <iframe> and its expected attributes, trusting
 * wp_kses()'s own behavior for the actual stripping.
 */
final class EmbedHtmlSanitizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('__')->returnArg(1);
    }

    public function testSanitizeCallsWpKsesWithAnIframeOnlyAllowlist(): void
    {
        $html = '<iframe src="https://open.spotify.com/embed/track/abc"></iframe><script>alert(1)</script>';
        $capturedAllowedTags = null;

        Functions\when('wp_kses')->alias(
            static function (string $passedHtml, array $allowedTags) use (&$capturedAllowedTags): string {
                $capturedAllowedTags = $allowedTags;

                return $passedHtml;
            }
        );

        EmbedHtmlSanitizer::sanitize($html);

        self::assertSame(['iframe'], array_keys($capturedAllowedTags));
        self::assertArrayHasKey('src', $capturedAllowedTags['iframe']);
        self::assertArrayHasKey('allowfullscreen', $capturedAllowedTags['iframe']);
    }

    public function testSanitizeMarksTheEmbedIframeLazyLoading(): void
    {
        Functions\when('wp_kses')->returnArg(1);

        $result = EmbedHtmlSanitizer::sanitize('<iframe src="https://open.spotify.com/embed/track/abc"></iframe>');

        self::assertStringContainsString('<iframe loading="lazy"', $result);
    }

    public function testSanitizeGivesAnUntitledIframeAProviderNamedTitle(): void
    {
        Functions\when('wp_kses')->returnArg(1);

        $result = EmbedHtmlSanitizer::sanitize('<iframe src="https://open.spotify.com/embed/track/abc"></iframe>');

        self::assertStringContainsString('title="Spotify player"', $result);
    }

    public function testSanitizeKeepsAnExistingIframeTitle(): void
    {
        Functions\when('wp_kses')->returnArg(1);

        $result = EmbedHtmlSanitizer::sanitize(
            '<iframe title="My player" src="https://w.soundcloud.com/player/?url=x"></iframe>'
        );

        self::assertStringContainsString('title="My player"', $result);
        self::assertStringNotContainsString('SoundCloud player', $result);
    }

    public function testSanitizeTurnsTheSoundCloudTeaserOff(): void
    {
        Functions\when('wp_kses')->returnArg(1);

        $src = 'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Fusers%2F1&amp;visual=true';

        $result = EmbedHtmlSanitizer::sanitize('<iframe src="' . $src . '"></iframe>');

        self::assertStringContainsString('src="' . $src . '&show_teaser=false"', $result);
    }

    public function testSanitizeReplacesAnExistingSoundCloudTeaserValue(): void
    {
        Functions\when('wp_kses')->returnArg(1);

        $result = EmbedHtmlSanitizer::sanitize(
            '<iframe src="https://w.soundcloud.com/player/?url=x&amp;show_teaser=true&amp;visual=true"></iframe>'
        );

        self::assertStringContainsString(
            'src="https://w.soundcloud.com/player/?url=x&amp;show_teaser=false&amp;visual=true"',
            $result
        );
        self::assertSame(1, substr_count($result, 'show_teaser='));
    }

    public function testSanitizeAddsTheTeaserParameterAsTheFirstOneWhenTheSrcHasNoQuery(): void
    {
        Functions\when('wp_kses')->returnArg(1);

        $result = EmbedHtmlSanitizer::sanitize('<iframe src="https://w.soundcloud.com/player/"></iframe>');

        self::assertStringContainsString('src="https://w.soundcloud.com/player/?show_teaser=false"', $result);
    }

    public function testSanitizeLeavesOtherProvidersSrcAlone(): void
    {
        Functions\when('wp_kses')->returnArg(1);

        $spotify = EmbedHtmlSanitizer::sanitize('<iframe src="https://open.spotify.com/embed/track/abc?x=1"></iframe>');
        $mixcloud = EmbedHtmlSanitizer::sanitize(
            '<iframe src="https://player-widget.mixcloud.com/widget/iframe/?feed=%2Fa%2F"></iframe>'
        );

        self::assertStringContainsString('src="https://open.spotify.com/embed/track/abc?x=1"', $spotify);
        self::assertStringNotContainsString('show_teaser', $spotify . $mixcloud);
    }

    public function testSanitizeDoesNotAddASecondLoadingAttribute(): void
    {
        Functions\when('wp_kses')->returnArg(1);

        $result = EmbedHtmlSanitizer::sanitize(
            '<iframe loading="eager" src="https://open.spotify.com/embed/track/abc"></iframe>'
        );

        self::assertStringNotContainsString('loading="lazy"', $result);
        self::assertSame(1, substr_count($result, 'loading='), 'An iframe that already sets loading must be left as-is.');
    }
}
