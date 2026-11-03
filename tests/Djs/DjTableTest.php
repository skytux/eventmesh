<?php

declare(strict_types=1);

namespace EventMesh\Tests\Djs;

use EventMesh\Djs\DjTable;
use EventMesh\Tests\TestCase;

final class DjTableTest extends TestCase
{
    private mixed $savedWpdb = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedWpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            public function get_charset_collate(): string
            {
                return 'DEFAULT CHARSET=utf8mb4';
            }
        };
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->savedWpdb;
        parent::tearDown();
    }

    public function testSchemaHasTheDescriptionColumnsInDbDeltaLayout(): void
    {
        $schema = (string) (new \ReflectionMethod(DjTable::class, 'schema'))->invoke(new DjTable());

        self::assertStringStartsWith('CREATE TABLE wp_eventmesh_djs (', $schema);
        self::assertStringContainsString("\n  description text NOT NULL,\n", $schema);
        self::assertStringContainsString("\n  description_source varchar(20) NOT NULL DEFAULT '',\n", $schema);
        // dbDelta needs two spaces after PRIMARY KEY and no back ticks.
        self::assertStringContainsString('PRIMARY KEY  (id)', $schema);
        self::assertStringNotContainsString('`', $schema);
    }

    public function testTheVersionWasRaisedSoDbDeltaAddsTheColumns(): void
    {
        self::assertSame('2', DjTable::DB_VERSION);
        self::assertSame('eventmesh_dj_db_version', DjTable::VERSION_OPTION);
    }
}
