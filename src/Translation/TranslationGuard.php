<?php

declare(strict_types=1);

namespace EventMesh\Translation;

use EventMesh\Support\EventStatus;

/**
 * The safety check a machine translation must pass before it is saved. A
 * translation that changed a price, a time, a door code, a link or the
 * markup is worse than no translation, so anything that does not survive
 * unchanged fails the check:
 *
 * - every digit sequence appears the same number of times (prices, times,
 *   dates, codes), compared after decoding HTML entities;
 * - the same URLs and email addresses;
 * - the same sequence of HTML tag names and of block comment delimiters
 *   (<!-- wp:... -->, compared whole, attributes included);
 * - the CANCELED/CANCELLED marker the front end strikes events through by;
 * - and the translation is not empty.
 */
final class TranslationGuard
{
    /**
     * @return string|null Null when the translation passes, else the reason:
     *                     empty, numbers, urls, emails, tags, blocks, canceled.
     */
    public static function check(string $original, string $translation): ?string
    {
        $lostAllText = '' !== self::visibleText($original) && '' === self::visibleText($translation);

        if ('' === trim($translation) || $lostAllText) {
            return 'empty';
        }

        if (self::digitCounts($original) !== self::digitCounts($translation)) {
            return 'numbers';
        }

        if (self::sorted(self::urls($original)) !== self::sorted(self::urls($translation))) {
            return 'urls';
        }

        if (self::sorted(self::emails($original)) !== self::sorted(self::emails($translation))) {
            return 'emails';
        }

        if (self::tagNames($original) !== self::tagNames($translation)) {
            return 'tags';
        }

        if (self::blockDelimiters($original) !== self::blockDelimiters($translation)) {
            return 'blocks';
        }

        if (EventStatus::isCanceled($original) !== EventStatus::isCanceled($translation)) {
            return 'canceled';
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    private static function digitCounts(string $text): array
    {
        preg_match_all('/\d+/', self::decode($text), $matches);

        $counts = array_count_values($matches[0]);
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * @return array<int, string>
     */
    private static function urls(string $text): array
    {
        preg_match_all('#https?://[^\s"\'<>]+#i', self::decode($text), $matches);

        // Sentence punctuation right after a link is not part of it, and a
        // translation may move it.
        return array_map(static fn (string $url): string => rtrim($url, '.,;:!?)'), $matches[0]);
    }

    /**
     * @return array<int, string>
     */
    private static function emails(string $text): array
    {
        preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', self::decode($text), $matches);

        return $matches[0];
    }

    /**
     * @return array<int, string>
     */
    private static function tagNames(string $text): array
    {
        preg_match_all('#<(/?)([a-z][a-z0-9-]*)#i', $text, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $m): string => $m[1] . strtolower($m[2]), $matches);
    }

    /**
     * @return array<int, string>
     */
    private static function blockDelimiters(string $text): array
    {
        preg_match_all('#<!--\s*/?wp:.*?-->#s', $text, $matches);

        return array_map(
            static fn (string $comment): string => (string) preg_replace('/\s+/', ' ', $comment),
            $matches[0]
        );
    }

    private static function visibleText(string $text): string
    {
        $withoutComments = (string) preg_replace('#<!--.*?-->#s', '', $text);

        return trim(self::decode(strip_tags($withoutComments)));
    }

    private static function decode(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param array<int, string> $values
     *
     * @return array<int, string>
     */
    private static function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }
}
