<?php

declare(strict_types=1);

namespace EventMesh\Tests\Support;

use Brain\Monkey\Functions;
use EventMesh\Support\ProviderEmbedMarkup;
use EventMesh\Tests\TestCase;

final class ProviderEmbedMarkupTest extends TestCase
{
    private const IFRAME = '<iframe src="https://open.spotify.com/embed/track/abc"></iframe>';

    protected function setUp(): void
    {
        parent::setUp();

        // EmbedHtmlSanitizer::sanitize() runs wp_kses; pass it through so the
        // test exercises this class's own deferral logic, not wp_kses.
        Functions\when('wp_kses')->returnArg(1);
        Functions\when('__')->returnArg(1);
    }

    public function testReturnsAPlainLiveIframeWhenDeferralIsOff(): void
    {
        Functions\when('get_option')->justReturn('0');

        $enqueued = false;
        Functions\when('wp_enqueue_script')->alias(static function () use (&$enqueued): void {
            $enqueued = true;
        });

        $html = ProviderEmbedMarkup::render(self::IFRAME);

        self::assertStringContainsString('src="https://open.spotify.com/embed/track/abc"', $html);
        self::assertStringNotContainsString('data-src', $html);
        self::assertStringNotContainsString('<noscript>', $html);
        self::assertFalse($enqueued, 'The loader script must not be enqueued when deferral is off.');
    }

    public function testDefersToDataSrcWithANoscriptFallbackAndEnqueuesTheLoaderWhenOn(): void
    {
        Functions\when('get_option')->justReturn('1');

        $enqueuedHandle = '';
        Functions\when('wp_enqueue_script')->alias(static function (string $handle) use (&$enqueuedHandle): void {
            $enqueuedHandle = $handle;
        });

        $html = ProviderEmbedMarkup::render(self::IFRAME);

        // The visible iframe carries data-src (not a live src) so nothing loads
        // until the loader promotes it.
        self::assertStringContainsString('data-src="https://open.spotify.com/embed/track/abc"', $html);
        // The <noscript> copy keeps a real src so no-JS visitors still get it.
        self::assertStringContainsString('<noscript><iframe', $html);
        self::assertStringContainsString('</noscript>', $html);
        self::assertSame('eventmesh-embed-lazy', $enqueuedHandle);
    }

    public function testReturnsEmptyStringForEmptyInput(): void
    {
        Functions\when('get_option')->justReturn('1');

        self::assertSame('', ProviderEmbedMarkup::render(''));
    }
}
