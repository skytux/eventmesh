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
}
