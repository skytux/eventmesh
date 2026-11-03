<?php

declare(strict_types=1);

namespace EventMesh\Tests\Naming;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use EventMesh\Naming\EventDisplayName;
use EventMesh\Tests\TestCase;

final class EventDisplayNameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('_n')->alias(
            static fn (string $single, string $plural, int $count) => 1 === $count ? $single : $plural
        );
        Functions\when('esc_html')->alias(static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES));
        Functions\when('esc_attr')->alias(static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES));
        Functions\when('determine_locale')->justReturn('en_US');
    }

    public function testDjLineForOneTwoAndThreeNames(): void
    {
        self::assertSame('Kai', EventDisplayName::djLine(['Kai']));
        self::assertSame('Kai & Lumi', EventDisplayName::djLine(['Kai', 'Lumi']));
        self::assertSame('Kai, Lumi & Ode', EventDisplayName::djLine(['Kai', 'Lumi', 'Ode']));
    }

    public function testDjLineHasNoDjPrefix(): void
    {
        self::assertSame('Ihmeidentekijä', EventDisplayName::djLine(['Ihmeidentekijä']));
        self::assertSame('Milla Linna & Ty Alexander', EventDisplayName::djLine(['Milla Linna', 'Ty Alexander']));
        self::assertStringStartsNotWith('DJ', EventDisplayName::djLine(['Kai', 'Lumi', 'Ode']));
    }

    public function testDjLineIsEmptyWithoutNamesAndSkipsBlanksAndRepeats(): void
    {
        self::assertSame('', EventDisplayName::djLine([]));
        self::assertSame('', EventDisplayName::djLine(['', '  ', 7]));
        self::assertSame('Kai', EventDisplayName::djLine(['Kai', ' Kai ', '']));
    }

    public function testDjLineHtmlIsABlockSpanAndEscaped(): void
    {
        self::assertSame('', EventDisplayName::djLineHtml(''));
        self::assertSame(
            '<span class="eventmesh-dj-line">A &amp; &lt;B&gt;</span>',
            EventDisplayName::djLineHtml('A & <B>')
        );
    }

    public function testForEventGivesTheCleanNameAndTheDjLine(): void
    {
        Filters\expectApplied('eventmesh/event_name')->once()->with('', 42, 'en')->andReturn('Ecstatic Dance');
        Filters\expectApplied('eventmesh/event_dj_names')->once()->with([], 42)->andReturn(['Kai', 'Lumi']);

        self::assertSame(
            ['name' => 'Ecstatic Dance', 'dj_line' => 'Kai & Lumi'],
            EventDisplayName::forEvent(42)
        );
    }

    public function testForEventAsksForTheFinnishNameOnAFinnishPage(): void
    {
        Functions\when('determine_locale')->justReturn('fi');
        Filters\expectApplied('eventmesh/event_name')->once()->with('', 42, 'fi')->andReturn('Ekstaattinen tanssi');
        Filters\expectApplied('eventmesh/event_dj_names')->once()->andReturn([]);

        self::assertSame(
            ['name' => 'Ekstaattinen tanssi', 'dj_line' => ''],
            EventDisplayName::forEvent(42)
        );
    }

    public function testForEventIsNullWhileThereIsNoCleanName(): void
    {
        // Brain Monkey's apply_filters gives back the value passed in: ''.
        Filters\expectApplied('eventmesh/event_dj_names')->never();

        self::assertNull(EventDisplayName::forEvent(42));
        self::assertNull(EventDisplayName::forEvent(0));
    }
}
