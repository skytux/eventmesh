<?php

declare(strict_types=1);

namespace EventMesh\Tests\Connectors\Dummy;

use Brain\Monkey\Functions;
use EventMesh\Connectors\Dummy\DummyConnector;
use EventMesh\Tests\TestCase;

final class DummyConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('__')->returnArg(1);
    }

    public function testFetchReturnsAFixedSampleDatasetWithNoNetworkCalls(): void
    {
        Functions\when('wp_remote_get')->alias(
            static function (): void {
                self::fail('DummyConnector must never make a real HTTP request.');
            }
        );

        $connector = new DummyConnector();
        $events = $connector->fetch();

        self::assertNotEmpty($events);
        self::assertSame(0, $connector->fetchErrors());

        foreach ($events as $event) {
            self::assertSame('dummy', $event->sourceId());
        }
    }

    public function testFetchIncludesASoldOutEvent(): void
    {
        $events = (new DummyConnector())->fetch();

        $soldOut = array_filter($events, static fn ($event) => $event->soldOut());

        self::assertNotEmpty($soldOut);
    }

    public function testFetchIncludesAPastEvent(): void
    {
        $events = (new DummyConnector())->fetch();

        $past = array_filter(
            $events,
            static fn ($event) => null !== $event->startsAt() && $event->startsAt() < new \DateTimeImmutable('now')
        );

        self::assertNotEmpty($past, 'A past event is needed to exercise upcoming/past sorting.');
    }

    public function testFetchIncludesAnEventThatIsBothPastAndSoldOut(): void
    {
        $events = (new DummyConnector())->fetch();

        $pastAndSoldOut = array_filter(
            $events,
            static fn ($event) => $event->soldOut()
                && null !== $event->startsAt()
                && $event->startsAt() < new \DateTimeImmutable('now')
        );

        self::assertNotEmpty(
            $pastAndSoldOut,
            'A past-and-sold-out combination is needed to test that the past divider still shows correctly.'
        );
    }

    public function testFetchIncludesAnEventWithCanceledInTheTitle(): void
    {
        $events = (new DummyConnector())->fetch();

        $canceled = array_filter($events, static fn ($event) => str_contains($event->title(), 'CANCELED'));

        self::assertNotEmpty($canceled, 'A CANCELED-titled event is needed to test the strike-through behavior.');
    }

    public function testFetchIncludesSeveralUpcomingEvents(): void
    {
        $now = new \DateTimeImmutable('now');
        $events = (new DummyConnector())->fetch();

        $upcoming = array_filter(
            $events,
            static fn ($event) => null !== $event->startsAt()
                && $event->startsAt() > $now
                && ! $event->soldOut()
                && ! str_contains($event->title(), 'CANCELED')
        );

        self::assertGreaterThanOrEqual(2, count($upcoming), 'The demo dataset should show more than one plain upcoming event.');
    }

    public function testFetchIncludesACanceledPastEvent(): void
    {
        $now = new \DateTimeImmutable('now');
        $events = (new DummyConnector())->fetch();

        $canceledPast = array_filter(
            $events,
            static fn ($event) => str_contains($event->title(), 'CANCELED')
                && null !== $event->startsAt()
                && $event->startsAt() < $now
        );

        self::assertNotEmpty($canceledPast, 'A canceled past event is needed to show strike-through in the past section.');
    }

    public function testFetchIncludesEventsWithPrices(): void
    {
        $events = (new DummyConnector())->fetch();

        $priced = array_filter($events, static fn ($event) => '' !== $event->price());

        self::assertNotEmpty($priced, 'The demo dataset should show prices on the ticket buttons.');
    }

    public function testFetchIncludesAnEventThatIsBothCanceledAndSoldOut(): void
    {
        $events = (new DummyConnector())->fetch();

        $canceledSoldOut = array_filter(
            $events,
            static fn ($event) => $event->soldOut() && str_contains($event->title(), 'CANCELED')
        );

        self::assertNotEmpty(
            $canceledSoldOut,
            'A canceled-and-sold-out combination is needed to test precedence between the two states.'
        );
    }
}
