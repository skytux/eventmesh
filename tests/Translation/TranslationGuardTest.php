<?php

declare(strict_types=1);

namespace EventMesh\Tests\Translation;

use EventMesh\Tests\TestCase;
use EventMesh\Translation\TranslationGuard;

final class TranslationGuardTest extends TestCase
{
    private const ORIGINAL = "<!-- wp:paragraph -->\n<p>Ovet aukeavat klo 21.00, liput 15 €. "
        . 'Lisätietoja <a href="https://tickets.example/event?id=7">täältä</a> tai info@events.example.</p>'
        . "\n<!-- /wp:paragraph -->";

    private const GOOD = "<!-- wp:paragraph -->\n<p>Doors open at 21.00, tickets 15 €. "
        . 'More information <a href="https://tickets.example/event?id=7">here</a> or info@events.example.</p>'
        . "\n<!-- /wp:paragraph -->";

    public function testAcceptsAFaithfulTranslation(): void
    {
        self::assertNull(TranslationGuard::check(self::ORIGINAL, self::GOOD));
    }

    public function testAcceptsPlainTitles(): void
    {
        self::assertNull(TranslationGuard::check('Tanssi-ilta 12.4.', 'Dance night 12.4.'));
    }

    public function testRejectsEmpty(): void
    {
        self::assertSame('empty', TranslationGuard::check(self::ORIGINAL, '  '));
        self::assertSame(
            'empty',
            TranslationGuard::check(self::ORIGINAL, "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->")
        );
    }

    public function testRejectsChangedNumbers(): void
    {
        self::assertSame('numbers', TranslationGuard::check(self::ORIGINAL, str_replace('15 €', '16 €', self::GOOD)));
        self::assertSame('numbers', TranslationGuard::check(self::ORIGINAL, str_replace('21.00', '9 pm', self::GOOD)));
    }

    public function testRejectsChangedUrl(): void
    {
        self::assertSame(
            'urls',
            TranslationGuard::check(self::ORIGINAL, str_replace('tickets.example/event', 'tickets.example/evnt', self::GOOD))
        );
    }

    public function testRejectsChangedEmail(): void
    {
        self::assertSame(
            'emails',
            TranslationGuard::check(self::ORIGINAL, str_replace('info@events.example', 'contact@events.example', self::GOOD))
        );
    }

    public function testRejectsChangedTags(): void
    {
        self::assertSame(
            'tags',
            TranslationGuard::check(self::ORIGINAL, str_replace(['<a ', '</a>'], ['<b data-x="', '</b>'], self::GOOD))
        );
        self::assertSame('tags', TranslationGuard::check('<p>Hei</p>', '<p>Hi<br></p>'));
    }

    public function testRejectsChangedBlockComments(): void
    {
        self::assertSame(
            'blocks',
            TranslationGuard::check(self::ORIGINAL, str_replace('<!-- /wp:paragraph -->', '', self::GOOD))
        );
    }

    public function testRejectsDroppedCanceledMarker(): void
    {
        self::assertSame('canceled', TranslationGuard::check('CANCELED Tanssi-ilta', 'Dance night'));
        self::assertNull(TranslationGuard::check('CANCELED Tanssi-ilta', 'CANCELED Dance night'));
    }
}
