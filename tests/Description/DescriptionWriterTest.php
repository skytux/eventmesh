<?php

declare(strict_types=1);

namespace EventMesh\Tests\Description;

use Brain\Monkey\Functions;
use EventMesh\Description\DescriptionWriter;
use EventMesh\Tests\TestCase;

final class DescriptionWriterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_strip_all_tags')->alias(static fn ($value) => trim(strip_tags((string) $value)));
    }

    public function testRedactLeavesOutCodesPasswordsPhonesLinksAndEmails(): void
    {
        $html = "<p>Tervetuloa!</p><p>Ovikoodi on 7302.</p><p>Door code 4411</p>"
            . "<p>Portkoden 9876</p><p>Wifi password: dance</p>"
            . "<p>Soita 040 123 4567</p><p>Liput https://holvi.com/shop/x ja info@example.com</p>"
            . "<p>Ovet aukeavat 18.30.</p>";

        $redacted = DescriptionWriter::redact($html);

        self::assertSame("Tervetuloa!\nLiput ja\nOvet aukeavat 18.30.", $redacted);
    }

    /**
     * @dataProvider rejected
     */
    public function testCheckRejects(string $text, string $reason): void
    {
        self::assertSame($reason, DescriptionWriter::check($text, "Tanssit 14.11.2026 klo 19.00\nOvet 18.30"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejected(): array
    {
        return [
            'a new number' => ['Ovi aukeaa numerolla 7302.', 'numbers'],
            'the word for code' => ['Ovikoodi kerrotaan ovella.', 'private'],
            'code in English' => ['The door code is at the door.', 'private'],
            'a link' => ['Lue lisää www.example.com', 'links'],
            'an email address' => ['Kysy info@example.com', 'links'],
            'empty' => ['  ', 'empty'],
            'too long' => [str_repeat('Tanssi. ', 100), 'too_long'],
        ];
    }

    public function testCheckKeepsNumbersTheInputHas(): void
    {
        self::assertNull(DescriptionWriter::check('Tanssimme 14.11.2026 kello 19.', 'Date: Saturday 14.11.2026 19:00'));
    }

    public function testParseReadsTheTextFromAFencedReply(): void
    {
        self::assertSame('Tule!', DescriptionWriter::parse("```json\n{\"text\": \"Tule!\"}\n```"));
        self::assertNull(DescriptionWriter::parse('Tule!'));
        self::assertNull(DescriptionWriter::parse('{"text": ""}'));
    }

    public function testThePromptAsksForFinnishAndNoDoorCodes(): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, $value) => 'eventmesh/description_words' === $hook ? 'tanssia, sydän' : $value
        );

        $system = DescriptionWriter::system(['Aiempi teksti.']);

        self::assertStringContainsString('in Finnish', $system);
        self::assertStringContainsString('Never write door codes', $system);
        self::assertStringContainsString('tanssia, sydän', $system);
        self::assertStringContainsString('- Aiempi teksti.', $system);
        self::assertStringEndsWith('{"text": "the Finnish text"}', $system);
    }
}
