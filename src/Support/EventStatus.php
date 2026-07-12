<?php

declare(strict_types=1);

namespace EventMesh\Support;

/**
 * A sold-out event isn't necessarily canceled - striking it through
 * suggested otherwise. Actual cancellation is signaled by the source
 * writing a "CANCELED"/"CANCELLED" keyword directly into the title, which
 * this detects (without stripping it - the word stays visible) so callers
 * can decide to strike title/date through for that case specifically.
 */
final class EventStatus
{
    private const CANCELED_KEYWORDS = ['CANCELED', 'CANCELLED'];

    public static function isCanceled(string $title): bool
    {
        foreach (self::CANCELED_KEYWORDS as $keyword) {
            if (false !== stripos($title, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
