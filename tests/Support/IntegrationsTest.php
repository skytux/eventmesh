<?php

declare(strict_types=1);

namespace EventMesh\Tests\Support;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use EventMesh\Support\Integrations;
use EventMesh\Tests\TestCase;

final class IntegrationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('sanitize_key')->alias(
            static fn (string $key): string => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key) ?? '')
        );
    }

    public function testReturnsNothingWhenNoConsumerAnswers(): void
    {
        Filters\expectApplied(Integrations::FILTER)->andReturn([]);

        self::assertSame([], Integrations::all());
    }

    public function testNormalizesAConnectedIntegration(): void
    {
        Filters\expectApplied(Integrations::FILTER)->andReturn([
            ['id' => 'eventcrew', 'label' => 'EventCrew', 'status' => 'Connected'],
        ]);

        self::assertSame(
            [['id' => 'eventcrew', 'label' => 'EventCrew', 'status' => 'Connected']],
            Integrations::all()
        );
    }

    /**
     * A consumer's callback is arbitrary code, so a malformed entry is dropped
     * rather than rendered: no label means nothing to show.
     */
    public function testDropsEntriesWithoutALabel(): void
    {
        Filters\expectApplied(Integrations::FILTER)->andReturn([
            ['id' => 'nolabel', 'status' => 'whatever'],
            'not-an-array',
            ['id' => 'eventcrew', 'label' => 'EventCrew'],
        ]);

        $all = Integrations::all();

        self::assertCount(1, $all);
        self::assertSame('eventcrew', $all[0]['id']);
        self::assertSame('', $all[0]['status']);
    }

    public function testKeepsOnlyTheFirstOfADuplicatedId(): void
    {
        Filters\expectApplied(Integrations::FILTER)->andReturn([
            ['id' => 'eventcrew', 'label' => 'EventCrew', 'status' => 'first'],
            ['id' => 'eventcrew', 'label' => 'EventCrew again', 'status' => 'second'],
        ]);

        $all = Integrations::all();

        self::assertCount(1, $all);
        self::assertSame('first', $all[0]['status']);
    }

    public function testDerivesAnIdFromTheLabelWhenNoneIsGiven(): void
    {
        Filters\expectApplied(Integrations::FILTER)->andReturn([
            ['label' => 'Event Crew'],
        ]);

        self::assertSame('eventcrew', Integrations::all()[0]['id']);
    }

    public function testAFilterReturningNonArrayIsHandled(): void
    {
        Filters\expectApplied(Integrations::FILTER)->andReturn(null);

        self::assertSame([], Integrations::all());
    }
}
