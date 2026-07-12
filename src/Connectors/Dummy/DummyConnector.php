<?php

declare(strict_types=1);

namespace EventMesh\Connectors\Dummy;

use DateTimeImmutable;
use EventMesh\Contracts\ConnectorInterface;
use EventMesh\Models\Event;

/**
 * Returns a small, fixed set of sample events with no network calls at all
 * - exists purely to exercise the sync pipeline (and prove it's genuinely
 * connector-agnostic, not just Holvi with extra steps) without needing a
 * real Holvi shop to test against. Inert unless explicitly enabled - see
 * register.php.
 */
final class DummyConnector implements ConnectorInterface
{
    public function id(): string
    {
        return 'dummy';
    }

    public function label(): string
    {
        return __('Dummy (testing)', 'eventmesh');
    }

    /**
     * @return array<int, Event>
     */
    public function fetch(): array
    {
        $now = new DateTimeImmutable('now');

        return [
            new Event(
                sourceId: $this->id(),
                externalId: 'dummy-upcoming-1',
                title: 'Dummy Upcoming Show',
                startsAt: $now->modify('+7 days'),
                endsAt: null,
                url: 'https://example.test/dummy-upcoming-1',
                description: 'A sample upcoming event with a known year and a venue.',
                imageUrl: '',
                venueName: 'Dummy Venue Hall',
                startsAtYearKnown: true,
                soldOut: false,
                providers: ['spotify' => 'https://open.spotify.com/track/dummy']
            ),
            new Event(
                sourceId: $this->id(),
                externalId: 'dummy-sold-out-1',
                title: 'Dummy Sold Out Show',
                startsAt: $now->modify('+14 days'),
                endsAt: null,
                url: 'https://example.test/dummy-sold-out-1',
                description: 'A sample sold-out upcoming event.',
                imageUrl: '',
                venueName: 'Dummy Venue Hall',
                startsAtYearKnown: true,
                soldOut: true,
                providers: []
            ),
            new Event(
                sourceId: $this->id(),
                externalId: 'dummy-no-year-1',
                title: 'Dummy No-Year Show',
                startsAt: $now->modify('+21 days'),
                endsAt: null,
                url: 'https://example.test/dummy-no-year-1',
                description: 'A sample event whose source never stated a year, only a day and month.',
                imageUrl: '',
                venueName: '',
                startsAtYearKnown: false,
                soldOut: false,
                providers: []
            ),
            new Event(
                sourceId: $this->id(),
                externalId: 'dummy-past-1',
                title: 'Dummy Past Show',
                startsAt: $now->modify('-7 days'),
                endsAt: null,
                url: 'https://example.test/dummy-past-1',
                description: 'A sample event that has already happened.',
                imageUrl: '',
                venueName: 'Dummy Venue Hall',
                startsAtYearKnown: true,
                soldOut: false,
                providers: []
            ),
            new Event(
                sourceId: $this->id(),
                externalId: 'dummy-past-sold-out-1',
                title: 'Dummy Past Sold Out Show',
                startsAt: $now->modify('-14 days'),
                endsAt: null,
                url: 'https://example.test/dummy-past-sold-out-1',
                description: 'A sample event that is both past and sold out, to test that combination.',
                imageUrl: '',
                venueName: 'Dummy Venue Hall',
                startsAtYearKnown: true,
                soldOut: true,
                providers: []
            ),
            new Event(
                sourceId: $this->id(),
                externalId: 'dummy-canceled-1',
                title: 'Dummy Canceled Show CANCELED',
                startsAt: $now->modify('+10 days'),
                endsAt: null,
                url: 'https://example.test/dummy-canceled-1',
                description: 'A sample event whose title contains the CANCELED keyword.',
                imageUrl: '',
                venueName: 'Dummy Venue Hall',
                startsAtYearKnown: true,
                soldOut: false,
                providers: []
            ),
        ];
    }

    public function fetchErrors(): int
    {
        return 0;
    }
}
