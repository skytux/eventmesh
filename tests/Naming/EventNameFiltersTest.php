<?php

declare(strict_types=1);

namespace EventMesh\Tests\Naming;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use EventMesh\Naming\EventNameFilters;
use EventMesh\Naming\EventNameStore;
use EventMesh\Tests\TestCase;

final class EventNameFiltersTest extends TestCase
{
    private const TITLE = 'ECSTATIC DANCE 14.11.2026 / Esimerkki';

    /** @var array<string, mixed> */
    private array $meta = [];

    private \WP_Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->post = new \WP_Post(7, 'eventmesh_event', self::TITLE);
        $this->meta = [
            '_eventmesh_name' => 'Ecstatic Dance',
            '_eventmesh_name_fi' => 'Ekstaattinen tanssi',
            '_eventmesh_dj_names' => ['Esimerkki'],
            '_eventmesh_name_hash' => EventNameStore::sourceHash(self::TITLE, 'EKSTAATTINEN TANSSI'),
        ];

        Functions\when('get_post')->alias(
            fn (int $id) => match ($id) {
                7 => $this->post,
                8 => new \WP_Post(8, 'page', self::TITLE),
                default => null,
            }
        );
        Functions\when('get_post_meta')->alias(fn (int $id, string $key) => 7 === $id ? ($this->meta[$key] ?? '') : '');
    }

    public function testBootRegistersBothFiltersWithTheirArgumentCounts(): void
    {
        $filters = new EventNameFilters();

        $added = [];
        Functions\when('add_filter')->alias(
            static function (string $hook, mixed $callback, int $priority, int $args) use (&$added): bool {
                $added[] = [$hook, $callback, $priority, $args];

                return true;
            }
        );

        $filters->boot();

        self::assertSame(
            [
                ['eventmesh/event_name', [$filters, 'filterName'], 10, 3],
                ['eventmesh/event_dj_names', [$filters, 'filterDjNames'], 10, 2],
            ],
            $added
        );
    }

    public function testNameInEnglishAndFinnish(): void
    {
        $filters = new EventNameFilters();

        self::assertSame('Ecstatic Dance', $filters->filterName('', 7, 'en'));
        self::assertSame('Ekstaattinen tanssi', $filters->filterName('', 7, 'fi'));
        self::assertSame('Ekstaattinen tanssi', $filters->filterName('', '7', 'FI '));
    }

    public function testFinnishFallsBackToTheOriginalLanguageName(): void
    {
        $this->meta['_eventmesh_name_fi'] = '';

        self::assertSame('Ecstatic Dance', (new EventNameFilters())->filterName('', 7, 'fi'));
    }

    public function testEmptyWhenNothingWrittenYet(): void
    {
        $this->meta = [];

        self::assertSame('', (new EventNameFilters())->filterName('', 7, 'en'));
    }

    public function testEmptyWhenTheTitleChangedSinceTheNameWasWritten(): void
    {
        $this->post->post_title = 'CACAO ECSTATIC DANCE 12.12.2026 / Aino Testinen & Bo Kokeilu';
        $filters = new EventNameFilters();

        self::assertSame('', $filters->filterName('', 7, 'en'));
        Filters\expectApplied('eventmesh/event_djs')->once()->andReturn([]);
        self::assertSame([], $filters->filterDjNames([], 7));
    }

    public function testValueUnchangedForNonEvents(): void
    {
        $filters = new EventNameFilters();

        self::assertSame('', $filters->filterName('', 8, 'en'));
        self::assertSame('', $filters->filterName('', 0, 'en'));
        self::assertSame([], $filters->filterDjNames([], 8));
        self::assertSame([], $filters->filterDjNames([], 'x'));
    }

    public function testDjNamesPreferTheDjTableMatch(): void
    {
        Filters\expectApplied('eventmesh/event_djs')
            ->once()
            ->with([], 7)
            ->andReturn(
                [
                    ['name' => 'Esimerkki', 'description' => 'Plays deep.'],
                    ['name' => 'Ana', 'description' => ''],
                ]
            );

        self::assertSame(['Esimerkki', 'Ana'], (new EventNameFilters())->filterDjNames([], 7));
    }

    public function testDjNamesFallBackToTheNamesTheModelFound(): void
    {
        Filters\expectApplied('eventmesh/event_djs')->once()->andReturn([]);

        self::assertSame(['Esimerkki'], (new EventNameFilters())->filterDjNames([], 7));
    }

    public function testNoDjNamesAtAll(): void
    {
        $this->meta['_eventmesh_dj_names'] = [];
        Filters\expectApplied('eventmesh/event_djs')->once()->andReturn([]);

        self::assertSame([], (new EventNameFilters())->filterDjNames([], 7));
    }
}
