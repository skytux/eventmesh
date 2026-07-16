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
