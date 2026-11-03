<?php

declare(strict_types=1);

namespace EventMesh\Djs;

/**
 * Finds which DJs an event's title and text name.
 *
 * A DJ matches when its name, or one of its other spellings, appears in the
 * text as whole words. Upper and lower case count as the same letter, and so
 * do accented and plain letters ("Ihmeidentekijä" matches "IHMEIDENTEKIJA").
 * "Whole words" means the letter or digit before and after the name is not
 * another letter or digit, so "Ana" does not match inside "Banana".
 */
final class DjMatcher
{
    /**
     * Lower case, accents removed, HTML tags and entities removed, every run
     * of white space made into one space.
     */
    public static function normalize(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = remove_accents($text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);

        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    /**
     * The DJs named in the text, in the order their first name appears in it.
     *
     * @param array<int, Dj> $djs
     *
     * @return array<int, Dj>
     */
    public static function matches(array $djs, string $text): array
    {
        $haystack = self::normalize($text);

        if ('' === $haystack) {
            return [];
        }

        $found = [];

        foreach ($djs as $index => $dj) {
            $position = self::firstPosition($dj, $haystack);

            if (null !== $position) {
                $found[] = ['position' => $position, 'index' => $index, 'dj' => $dj];
            }
        }

        usort(
            $found,
            static fn (array $a, array $b): int => [$a['position'], $a['index']] <=> [$b['position'], $b['index']]
        );

        return array_map(static fn (array $item): Dj => $item['dj'], $found);
    }

    /**
     * Where the DJ's name or any alias first appears as whole words in the
     * already normalized text, or null when none does.
     */
    private static function firstPosition(Dj $dj, string $haystack): ?int
    {
        $first = null;

        foreach ($dj->names() as $name) {
            $needle = self::normalize($name);

            if ('' === $needle) {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u';

            if (1 === preg_match($pattern, $haystack, $matches, PREG_OFFSET_CAPTURE)) {
                $position = (int) $matches[0][1];
                $first = null === $first ? $position : min($first, $position);
            }
        }

        return $first;
    }
}
