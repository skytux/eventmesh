<?php

declare(strict_types=1);

namespace EventMesh\Naming;

use EventMesh\Translation\TranslationDisplay;

/**
 * What the public site shows as an event's title: the clean name (see
 * EventNameFilters) and, under it on its own line, the DJ line
 * ("DJ Kai", "DJs Kai & Lumi", "DJs Kai, Lumi & Ode").
 *
 * The name is in the page's language the same way TranslationDisplay picks
 * it: the Finnish name when the page is shown in Finnish, the original-language
 * name otherwise. When no clean name has been written for the event's current
 * title yet, there is nothing to show here and callers keep showing the title
 * exactly as before, without a DJ line (the title already names the DJ).
 *
 * Only for public display. The stored post_title, the document title, feeds
 * and admin screens keep the original title.
 */
final class EventDisplayName
{
    public const DJ_LINE_CLASS = 'eventmesh-dj-line';

    /**
     * The clean name and the DJ line for the event, or null when no clean
     * name exists yet.
     *
     * @param string|null $lang Two-letter page language; null reads the current page's.
     *
     * @return array{name: string, dj_line: string}|null
     */
    public static function forEvent(int $postId, ?string $lang = null): ?array
    {
        if ($postId <= 0) {
            return null;
        }

        $lang ??= TranslationDisplay::currentLanguage();
        $name = apply_filters(EventNameFilters::NAME_FILTER, '', $postId, $lang);

        if (! is_string($name) || '' === trim($name)) {
            return null;
        }

        $djs = apply_filters(EventNameFilters::DJ_NAMES_FILTER, [], $postId);

        return [
            'name' => trim($name),
            'dj_line' => self::djLine(is_array($djs) ? $djs : []),
        ];
    }

    /**
     * "DJ X", "DJs X & Y", "DJs A, B & C"; '' for no names.
     *
     * @param array<mixed> $djs
     */
    public static function djLine(array $djs): string
    {
        $names = [];

        foreach ($djs as $dj) {
            if (is_string($dj) && '' !== trim($dj)) {
                $names[] = trim($dj);
            }
        }

        $names = array_values(array_unique($names));
        $count = count($names);

        if (0 === $count) {
            return '';
        }

        $last = array_pop($names);
        $list = [] === $names ? $last : implode(', ', $names) . ' & ' . $last;

        /* translators: %s: one DJ name, or a list of DJ names such as "Kai, Lumi & Ode" */
        return sprintf(_n('DJ %s', 'DJs %s', $count, 'eventmesh'), $list);
    }

    /**
     * The DJ line as its own block-level element, escaped; '' for no line.
     */
    public static function djLineHtml(string $djLine): string
    {
        if ('' === $djLine) {
            return '';
        }

        return sprintf('<span class="%s">%s</span>', esc_attr(self::DJ_LINE_CLASS), esc_html($djLine));
    }
}
