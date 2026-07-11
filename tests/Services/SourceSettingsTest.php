<?php

declare(strict_types=1);

namespace EventMesh\Tests\Services;

use Brain\Monkey\Functions;
use EventMesh\Services\SourceSettings;
use EventMesh\Tests\TestCase;

final class SourceSettingsTest extends TestCase
{
    public function testUnknownSourceDefaultsToEnabled(): void
    {
        Functions\when('get_option')->justReturn([]);

        self::assertTrue((new SourceSettings())->isEnabled('holvi'));
    }

    public function testDisabledSourceIsReportedDisabled(): void
    {
        Functions\when('get_option')->justReturn(['holvi' => false]);

        self::assertFalse((new SourceSettings())->isEnabled('holvi'));
    }

    public function testNonArrayOptionIsTreatedAsEmpty(): void
    {
        Functions\when('get_option')->justReturn('not-an-array');

        self::assertSame([], (new SourceSettings())->all());
    }

    public function testSetEnabledPersistsMergedSettings(): void
    {
        Functions\when('get_option')->justReturn(['holvi' => true]);

        $captured = null;
        Functions\when('update_option')->alias(
            static function ($name, $value) use (&$captured) {
                $captured = $value;

                return true;
            }
        );

        (new SourceSettings())->setEnabled('ical', false);

        self::assertSame(['holvi' => true, 'ical' => false], $captured);
    }
}
