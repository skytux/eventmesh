<?php

declare(strict_types=1);

namespace EventMesh\Djs;

/**
 * Reads DJ descriptions from the site's team page (see DjDescriptions).
 *
 * The page content (block editor or classic HTML) is cut at every heading,
 * h1 to h6. A heading h2, h3 or h4 whose whole text is a DJ's name or one of
 * the DJ's other spellings gives that DJ the paragraphs after it, up to the
 * next heading. Names are compared the way DjMatcher compares them: case and
 * accents do not matter, but the whole heading must be the name, so the
 * heading "Milla Linna & Ty Alexander" only matches a DJ whose name or other
 * spelling is that whole text. Headings that match no DJ are ignored.
 */
final class TeamPageParser
{
    /**
     * @param array<int, Dj> $djs
     *
     * @return array<int, string> DJ id => plain text, paragraphs separated by
     *                            "\n\n". DJs not on the page are left out.
     */
    public static function parse(array $djs, string $content): array
    {
        $byName = [];

        foreach ($djs as $dj) {
            foreach ($dj->names() as $name) {
                $key = DjMatcher::normalize($name);

                if ('' !== $key) {
                    $byName[$key][] = $dj->id;
                }
            }
        }

        if ([] === $byName || '' === trim($content)) {
            return [];
        }

        // Block comments (<!-- wp:paragraph -->) are not text.
        $content = (string) preg_replace('/<!--.*?-->/s', '', $content);

        $parts = preg_split(
            '/<h([1-6])\b[^>]*>(.*?)<\/h\1\s*>/is',
            $content,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if (false === $parts) {
            return [];
        }

        $found = [];
        $count = count($parts);

        // $parts: text before the first heading, then for every heading its
        // level, its inner HTML and the text after it.
        for ($i = 1; $i + 2 < $count; $i += 3) {
            $level = (int) $parts[$i];
            $heading = DjMatcher::normalize((string) $parts[$i + 1]);
            $section = (string) $parts[$i + 2];

            if ($level < 2 || $level > 4 || ! isset($byName[$heading])) {
                continue;
            }

            $text = self::paragraphs($section);

            if ('' === $text) {
                continue;
            }

            foreach ($byName[$heading] as $id) {
                $found[$id] ??= $text;
            }
        }

        return $found;
    }

    /**
     * The plain text of the paragraphs in a piece of HTML, joined with
     * "\n\n". HTML with no <p> tags is split on blank lines instead.
     */
    public static function paragraphs(string $html): string
    {
        if (preg_match_all('/<p\b[^>]*>(.*?)<\/p\s*>/is', $html, $matches) > 0) {
            $chunks = $matches[1];
        } else {
            $chunks = preg_split('/\R\s*\R/u', $html) ?: [];
        }

        $paragraphs = [];

        foreach ($chunks as $chunk) {
            $text = self::plainText((string) $chunk);

            if ('' !== $text) {
                $paragraphs[] = $text;
            }
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * Tags removed, entities decoded, every run of white space made into one
     * space. A line break (<br>) becomes a space.
     */
    private static function plainText(string $html): string
    {
        $html = (string) preg_replace('/<br\s*\/?>/i', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }
}
