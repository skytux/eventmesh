<?php

declare(strict_types=1);

namespace EventMesh\Tests\Naming;

use EventMesh\Naming\EventNameGuard;
use EventMesh\Tests\TestCase;
use EventMesh\Translation\TranslationRejected;

final class EventNameGuardTest extends TestCase
{
    public function testEcstaticDanceWithOneDj(): void
    {
        self::assertSame(
            ['name' => 'Ecstatic Dance', 'name_fi' => '', 'djs' => ['Esimerkki']],
            EventNameGuard::clean(
                'Ecstatic Dance',
                '',
                ['Esimerkki'],
                'ECSTATIC DANCE 14.11.2026 / Esimerkki',
                ''
            )
        );
    }

    public function testCacaoEcstaticDanceWithTwoDjs(): void
    {
        self::assertSame(
            ['name' => 'Cacao Ecstatic Dance', 'name_fi' => '', 'djs' => ['Aino Testinen', 'Bo Kokeilu']],
            EventNameGuard::clean(
                'Cacao Ecstatic Dance',
                '',
                ['Aino Testinen', 'Bo Kokeilu'],
                'CACAO ECSTATIC DANCE 12.12.2026 / Aino Testinen & Bo Kokeilu',
                ''
            )
        );
    }

    public function testTitleWithoutDj(): void
    {
        self::assertSame(
            ['name' => 'Ecstatic Dance', 'name_fi' => '', 'djs' => []],
            EventNameGuard::clean('Ecstatic Dance', '', [], 'ECSTATIC DANCE 14.11.2026', '')
        );
    }

    public function testAllCapsFromTheModelBecomesNormalCase(): void
    {
        $result = EventNameGuard::clean(
            'CACAO ECSTATIC DANCE',
            'EKSTAATTINEN TANSSI',
            ['AINO TESTINEN'],
            'CACAO ECSTATIC DANCE 12.12.2026 / Aino Testinen & Bo Kokeilu',
            'KAAKAO EKSTAATTINEN TANSSI 12.12.2026 / Aino Testinen & Bo Kokeilu'
        );

        self::assertSame('Cacao Ecstatic Dance', $result['name']);
        self::assertSame('Ekstaattinen Tanssi', $result['name_fi']);
    }

    public function testNormalCaseKeepsShortCapitalsAndSmallLetters(): void
    {
        self::assertSame('DJ Night', EventNameGuard::normalCase('DJ NIGHT'));
        self::assertSame('Ekstaattinen tanssi', EventNameGuard::normalCase('ekstaattinen tanssi'));
        self::assertSame('Äänimatka', EventNameGuard::normalCase('ÄÄNIMATKA'));
    }

    public function testFinnishNameFromTheFinnishTitle(): void
    {
        $result = EventNameGuard::clean(
            'Ecstatic Dance',
            'Ekstaattinen tanssi',
            ['Esimerkki'],
            'ECSTATIC DANCE 14.11.2026 / Esimerkki',
            'EKSTAATTINEN TANSSI 14.11.2026 / Esimerkki'
        );

        self::assertSame('Ekstaattinen tanssi', $result['name_fi']);
    }

    public function testInventedWordIsRejected(): void
    {
        $this->expectRejected('name_words');

        EventNameGuard::clean('Ecstatic Dance Party', '', [], 'ECSTATIC DANCE 14.11.2026 / Esimerkki', '');
    }

    public function testTranslatedWordIsRejected(): void
    {
        $this->expectRejected('name_words');

        EventNameGuard::clean('Ekstaattinen tanssi', '', [], 'ECSTATIC DANCE 14.11.2026', '');
    }

    public function testDateIsRejected(): void
    {
        $this->expectRejected('name_date');

        EventNameGuard::clean('Ecstatic Dance 14.11.', '', [], 'ECSTATIC DANCE 14.11.2026', '');
    }

    public function testYearFromTheDateIsRejected(): void
    {
        $this->expectRejected('name_date');

        EventNameGuard::clean('Ecstatic Dance 2026', '', [], 'ECSTATIC DANCE 14.11.2026', '');
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectRejected('name_empty');

        EventNameGuard::clean(' / ', '', [], 'ECSTATIC DANCE 14.11.2026', '');
    }

    public function testTooLongNameIsRejected(): void
    {
        $words = str_repeat('dance ', 30);
        $this->expectRejected('name_length');

        EventNameGuard::clean(trim($words), '', [], strtoupper($words), '');
    }

    public function testDjNameInsideTheNameIsRejected(): void
    {
        $this->expectRejected('name_dj');

        EventNameGuard::clean(
            'Ecstatic Dance Esimerkki',
            '',
            ['Esimerkki'],
            'ECSTATIC DANCE 14.11.2026 / Esimerkki',
            ''
        );
    }

    public function testDjNameNotInTheTitleIsRejected(): void
    {
        $this->expectRejected('dj_not_in_title');

        EventNameGuard::clean('Ecstatic Dance', '', ['DJ Shadow'], 'ECSTATIC DANCE 14.11.2026 / Esimerkki', '');
    }

    public function testDjNamesMustBeAList(): void
    {
        $this->expectRejected('dj_names');

        EventNameGuard::clean('Ecstatic Dance', '', 'Esimerkki', 'ECSTATIC DANCE / Esimerkki', '');
    }

    public function testMissingFinnishNameIsRejectedWhenThereIsAFinnishTitle(): void
    {
        $this->expectRejected('fi_name_empty');

        EventNameGuard::clean('Ecstatic Dance', '', [], 'ECSTATIC DANCE 14.11.2026', 'EKSTAATTINEN TANSSI 14.11.2026');
    }

    public function testFinnishNameWithAnInventedWordIsRejected(): void
    {
        $this->expectRejected('fi_name_words');

        EventNameGuard::clean(
            'Ecstatic Dance',
            'Ekstaattinen tanssi-ilta',
            [],
            'ECSTATIC DANCE 14.11.2026',
            'EKSTAATTINEN TANSSI 14.11.2026'
        );
    }

    private function expectRejected(string $reason): void
    {
        $this->expectException(TranslationRejected::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($reason, '/') . '$/');
    }
}
