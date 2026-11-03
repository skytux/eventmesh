<?php

declare(strict_types=1);

namespace EventMesh\Tests\Djs;

use Brain\Monkey\Functions;
use EventMesh\Djs\DjRepository;
use EventMesh\Djs\EventDjsFilter;
use EventMesh\Tests\TestCase;

final class EventDjsFilterTest extends TestCase
{
    private mixed $savedWpdb = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedWpdb = $GLOBALS['wpdb'];

        if (! defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /**
             * @return array<int, array<string, string>>
             */
            public function get_results(string $query, string $output): array
            {
                return [
                    ['id' => '1', 'name' => 'Ana', 'aliases' => '', 'description' => 'Ana plays house.', 'description_source' => 'team'],
                    ['id' => '2', 'name' => 'Ihmeidentekijä', 'aliases' => 'Ihme', 'description' => '', 'description_source' => ''],
                    ['id' => '3', 'name' => 'Toby One', 'aliases' => '', 'description' => 'Drums.', 'description_source' => 'manual'],
                ];
            }
        };

        Functions\when('remove_accents')->alias(static fn (string $text): string => strtr($text, ['ä' => 'a']));
        Functions\when('get_option')->justReturn('2');
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->savedWpdb;
        parent::tearDown();
    }

    public function testBootRegistersTheFilterWithTwoArguments(): void
    {
        $filter = new EventDjsFilter(new DjRepository());

        $added = [];
        Functions\when('add_filter')->alias(
            static function (string $hook, mixed $callback, int $priority, int $args) use (&$added): bool {
                $added[] = [$hook, $callback, $priority, $args];

                return true;
            }
        );

        $filter->boot();

        self::assertSame([['eventmesh/event_djs', [$filter, 'filter'], 10, 2]], $added);
    }

    public function testGivesTheNamedDjsWithDescriptionsFirstNamedFirst(): void
    {
        Functions\when('get_post')->justReturn(
            new \WP_Post(7, 'eventmesh_event', 'Dance / IHMEIDENTEKIJA', '<p>With Ana. Not Banana.</p>')
        );

        self::assertSame(
            [
                ['name' => 'Ihmeidentekijä', 'description' => ''],
                ['name' => 'Ana', 'description' => 'Ana plays house.'],
            ],
            (new EventDjsFilter(new DjRepository()))->filter([], 7)
        );
    }

    public function testReturnsTheIncomingValueWhenNoDjMatches(): void
    {
        Functions\when('get_post')->justReturn(new \WP_Post(7, 'eventmesh_event', 'Dance / Banana', ''));

        self::assertSame(['kept'], (new EventDjsFilter(new DjRepository()))->filter(['kept'], 7));
        self::assertSame([], (new EventDjsFilter(new DjRepository()))->filter([], 7));
    }

    public function testReturnsTheIncomingValueForPostsThatAreNotEvents(): void
    {
        Functions\when('get_post')->justReturn(new \WP_Post(8, 'post', 'Ana', ''));

        self::assertSame([], (new EventDjsFilter(new DjRepository()))->filter([], 8));
        self::assertSame([], (new EventDjsFilter(new DjRepository()))->filter([], 0));
    }
}
