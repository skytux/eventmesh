<?php

declare(strict_types=1);

namespace EventMesh\Tests\Support;

use EventMesh\Support\EventStatus;
use PHPUnit\Framework\TestCase;

final class EventStatusTest extends TestCase
{
    /**
     * @dataProvider canceledTitles
     */
    public function testIsCanceledDetectsTheKeywordCaseInsensitively(string $title): void
    {
        self::assertTrue(EventStatus::isCanceled($title));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function canceledTitles(): array
    {
        return [
            'uppercase American spelling' => ['Some Band 12.8.2026 CANCELED'],
            'uppercase British spelling' => ['Some Band 12.8.2026 CANCELLED'],
            'lowercase' => ['some band - canceled'],
            'mixed case, keyword in the middle' => ['Some Band (Cancelled) 12.8.2026'],
        ];
    }

    public function testIsCanceledDoesNotStripTheKeywordItJustDetectsIt(): void
    {
        // isCanceled() is a pure detector - stripping/formatting is the
        // caller's job, so it must never alter the input.
        self::assertTrue(EventStatus::isCanceled('Some Band CANCELED'));
    }

    public function testIsCanceledReturnsFalseForAnOrdinaryTitle(): void
    {
        self::assertFalse(EventStatus::isCanceled('Some Band 12.8.2026'));
    }

    public function testIsCanceledReturnsFalseForAnEmptyTitle(): void
    {
        self::assertFalse(EventStatus::isCanceled(''));
    }
}
