<?php

declare(strict_types=1);

namespace EventMesh\Naming;

use EventMesh\Translation\TranslationRejected;

/**
 * The check the model's answer must pass before a clean name is saved. The
 * model may only take words away from the title, never add or change them:
 *
 * - every word of the name is a word of the title (case ignored);
 * - the name holds no date, and no number that is part of the title's date;
 * - the name is not empty and not longer than MAX_NAME characters;
 * - every DJ name is written in the title (case ignored);
 * - the name contains none of the DJ names.
 *
 * The casing is done here, not trusted to the model: a word of three or more
 * letters written all in capitals becomes a capital and small letters
 * ("ECSTATIC" -> "Ecstatic"), and the name starts with a capital. Short
 * words in capitals ("DJ") and words the model already wrote in small
 * letters are kept as they are.
 */
final class EventNameGuard
{
    public const MAX_NAME = 100;
    public const MAX_DJ_NAME = 80;
    public const MAX_DJS = 12;

    /** A date or part of one: 14.11.2026, 14.11., 2026-11-14, 14/11. */
    private const DATE_PATTERN = '/\d{1,4}\s*[.\/-]\s*\d{1,2}(?:\s*[.\/-]\s*\d{0,4})?/u';

    /**
     * @param mixed $name   The model's "name".
     * @param mixed $nameFi The model's "name_fi".
     * @param mixed $djs    The model's "djs".
     *
     * @return array{name: string, name_fi: string, djs: list<string>}
     *
     * @throws TranslationRejected With the reason: dj_names, dj_length,
     *                             dj_not_in_title, name_empty, name_length,
     *                             name_date, name_words, name_dj (the
     *                             Finnish name's reasons start with "fi_").
     */
    public static function clean(mixed $name, mixed $nameFi, mixed $djs, string $title, string $finnishTitle): array
    {
        $djNames = self::djNames($djs, $title);

        $clean = self::name($name, $title, $djNames, '');
        $cleanFi = '' !== trim($finnishTitle) ? self::name($nameFi, $finnishTitle, $djNames, 'fi_') : '';

        return ['name' => $clean, 'name_fi' => $cleanFi, 'djs' => $djNames];
    }

    /**
     * Capitals to normal case, word by word (see the class comment).
     */
    public static function normalCase(string $name): string
    {
        $name = (string) preg_replace_callback(
            "/[\\p{L}'’]+/u",
            static function (array $match): string {
                $word = $match[0];
                $letters = (int) preg_match_all('/\p{L}/u', $word);

                $allCapitals = mb_strtoupper($word, 'UTF-8') === $word && mb_strtolower($word, 'UTF-8') !== $word;

                if ($letters < 3 || ! $allCapitals) {
                    return $word;
                }

                return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8')
                    . mb_strtolower(mb_substr($word, 1, null, 'UTF-8'), 'UTF-8');
            },
            $name
        );

        return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($name, 1, null, 'UTF-8');
    }

    /**
     * @return list<string>
     */
    private static function djNames(mixed $djs, string $title): array
    {
        if (null === $djs) {
            return [];
        }

        if (! is_array($djs) || count($djs) > self::MAX_DJS) {
            throw new TranslationRejected('dj_names');
        }

        $names = [];
        $seen = [];

        foreach ($djs as $dj) {
            if (! is_string($dj)) {
                throw new TranslationRejected('dj_names');
            }

            $dj = self::tidy($dj);

            if ('' === $dj) {
                continue;
            }

            if (mb_strlen($dj, 'UTF-8') > self::MAX_DJ_NAME) {
                throw new TranslationRejected('dj_length');
            }

            if (false === mb_stripos($title, $dj, 0, 'UTF-8')) {
                throw new TranslationRejected('dj_not_in_title');
            }

            $key = mb_strtolower($dj, 'UTF-8');

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $names[] = $dj;
            }
        }

        return $names;
    }

    /**
     * @param list<string> $djNames
     */
    private static function name(mixed $name, string $source, array $djNames, string $prefix): string
    {
        $name = is_string($name) ? self::tidy($name) : '';

        if ('' === $name || 0 === preg_match('/\p{L}/u', $name)) {
            throw new TranslationRejected($prefix . 'name_empty');
        }

        if (mb_strlen($name, 'UTF-8') > self::MAX_NAME) {
            throw new TranslationRejected($prefix . 'name_length');
        }

        $dateNumbers = array_intersect(self::numbers($name), self::dateNumbers($source));

        if (1 === preg_match(self::DATE_PATTERN, $name) || [] !== $dateNumbers) {
            throw new TranslationRejected($prefix . 'name_date');
        }

        $sourceWords = array_flip(self::words($source));

        foreach (self::words($name) as $word) {
            if (! isset($sourceWords[$word])) {
                throw new TranslationRejected($prefix . 'name_words');
            }
        }

        foreach ($djNames as $dj) {
            if (false !== mb_stripos($name, $dj, 0, 'UTF-8')) {
                throw new TranslationRejected($prefix . 'name_dj');
            }
        }

        return self::normalCase($name);
    }

    /**
     * One line, single spaces, no separators left at either end
     * ("Ecstatic Dance /" -> "Ecstatic Dance").
     */
    private static function tidy(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return (string) preg_replace('/^[\s\p{Pd}\/|,:;·•*]+|[\s\p{Pd}\/|,:;·•*]+$/u', '', $text);
    }

    /**
     * The words of a text, in small letters: runs of letters and digits.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{M}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), $matches);

        return $matches[0];
    }

    /**
     * @return list<string>
     */
    private static function numbers(string $text): array
    {
        preg_match_all('/\d+/', $text, $matches);

        return $matches[0];
    }

    /**
     * The numbers that make up the dates written in a title.
     *
     * @return list<string>
     */
    private static function dateNumbers(string $text): array
    {
        preg_match_all(self::DATE_PATTERN, $text, $matches);

        return self::numbers(implode(' ', $matches[0]));
    }
}
