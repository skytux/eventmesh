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
     * Off by default so a production install never syncs sample data on its
     * own - an admin has to tick it on in the Sources table. The wp-config
     * constant still force-defaults it on for CI/dev, where there is no
     * stored per-source choice to consult.
     */
    public function enabledByDefault(): bool
    {
        return defined('EVENTMESH_ENABLE_TEST_CONNECTOR') && EVENTMESH_ENABLE_TEST_CONNECTOR;
    }

    /**
     * @return array<int, Event>
     */
    public function fetch(): array
    {
        $now = new DateTimeImmutable('now');

        return [
            // --- Upcoming (plain) ---
            new Event(
                sourceId: $this->id(),
                externalId: 'dummy-upcoming-1',
                title: 'Dummy Upcoming Show',
                startsAt: $now->modify('+7 days')->setTime(19, 30),
                endsAt: $now->modify('+7 days')->setTime(23, 0),
                url: 'https://example.test/dummy-upcoming-1',
                description: 'A sample upcoming event with a known year, a venue, and an evening time range.',
                imageUrl: '',
                venueName: 'Dummy Venue Hall',
                startsAtYearKnown: true,
                soldOut: false,
                providers: ['spotify' => 'https://open.spotify.com/track/dummy'],
                price: '€15'
            ),
            new Event(
                sourceId: $this->id(),
                externalId: 'dummy-upcoming-2',
                title: 'Dummy Weekend Matinee',
                startsAt: $now->modify('+3 days'),
                endsAt: null,
                url: 'https://example.test/dummy-upcoming-2',
                description: 'Another upcoming event, sooner than the first, to show ordering.',
                imageUrl: '',
                venueName: 'Riverside Club',
                startsAtYearKnown: true,
                soldOut: false,
                providers: [],
                price: 'Free'
            ),
            new Event(
                sourceId: $this->id(),
                externalId: 'dummy-upcoming-3',
                title: 'Dummy Season Finale',
                startsAt: $now->modify('+28 days')->setTime(12, 0),
                endsAt: $now->modify('+29 days')->setTime(22, 0),
                url: 'https://example.test/dummy-upcoming-3',
                description: 'A later, multi-day upcoming event, to show date and time ranges.',
                imageUrl: '',
                venueName: 'Grand Theatre',
                startsAtYearKnown: true,
                soldOut: false,
                providers: [],
                price: '€25'
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
                providers: [],
                price: ''
            ),

            // --- Sold out (upcoming) ---
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
                providers: [],
                price: '€20'
            ),
            new Event(
                sourceId: $this->id(),
                externalId: 'dummy-sold-out-2',
                title: 'Dummy Sold Out Club Night',
                startsAt: $now->modify('+5 days'),
                endsAt: null,
                url: 'https://example.test/dummy-sold-out-2',
                description: 'A second sold-out upcoming event.',
                imageUrl: '',
                venueName: 'Riverside Club',
                startsAtYearKnown: true,
                soldOut: true,
                providers: [],
                price: '€12'
            ),

            // --- Canceled ---
            new Event(
                sourceId: $this->id(),
                externalId: 'dummy-canceled-1',
                title: 'Dummy Canceled Show CANCELED',
                startsAt: $now->modify('+10 days'),
                endsAt: null,
                url: 'https://example.test/dummy-canceled-1',
                description: 'A sample upcoming event whose title contains the CANCELED keyword.',
                imageUrl: '',
                venueName: 'Dummy Venue Hall',
                startsAtYearKnown: true,
                soldOut: false,
                providers: [],
                price: '€18'
            ),
            new Event(
                sourceId: $this->id(),
                externalId: 'dummy-canceled-past-1',
                title: 'Dummy Old Gig CANCELED',
                startsAt: $now->modify('-3 days'),
                endsAt: null,
                url: 'https://example.test/dummy-canceled-past-1',
                description: 'A past event that was canceled, to show strike-through in the past section.',
                imageUrl: '',
                venueName: 'Grand Theatre',
                startsAtYearKnown: true,
                soldOut: false,
                providers: [],
                price: '€18'
            ),
            new Event(
                sourceId: $this->id(),
                externalId: 'dummy-canceled-sold-out-1',
                title: 'Dummy Festival Slot CANCELED',
                startsAt: $now->modify('+12 days'),
                endsAt: null,
                url: 'https://example.test/dummy-canceled-sold-out-1',
                description: 'An event that is both marked sold out and canceled, to test that combination.',
                imageUrl: '',
                venueName: 'Open Air Grounds',
                startsAtYearKnown: true,
                soldOut: true,
                providers: [],
                price: '€40'
            ),

            // --- Past ---
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
                providers: [],
                price: '€15'
            ),
            new Event(
                sourceId: $this->id(),
                externalId: 'dummy-past-2',
                title: 'Dummy Last Month Show',
                startsAt: $now->modify('-30 days'),
                endsAt: null,
                url: 'https://example.test/dummy-past-2',
                description: 'An older past event, furthest back, to show past ordering.',
                imageUrl: '',
                venueName: 'Riverside Club',
                startsAtYearKnown: true,
                soldOut: false,
                providers: [],
                price: '€10'
            ),

            // --- Past + sold out ---
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
                providers: [],
                price: '€15'
            ),
        ];
    }

    public function fetchErrors(): int
    {
        return 0;
    }
}
