<?php

declare(strict_types=1);

namespace EventMesh\Djs;

use EventMesh\Content\EventPostType;
use EventMesh\Support\EventMeta;
use EventMesh\Support\KnownProviders;
use WP_Query;

/**
 * Fills the empty DJ table once, from the links that existing events already
 * have.
 *
 * The DJ name is taken from the event title: the part after the first " / ",
 * as in "ECSTATIC DANCE 14.11.2026 / Ihmeidentekijä". HTML entities are
 * decoded first ("&amp;" becomes "&"), and the part is split on "&" when
 * several DJs share the event.
 *
 * - An event with one DJ gives that DJ its Spotify, SoundCloud and Mixcloud
 *   links (typed by hand or from the source, whichever the event shows).
 *   When several events give the same DJ a link on the same service, the
 *   most recently published event's link is used.
 * - An event with several DJs adds each DJ name with no links, because the
 *   event's links cannot be told apart by DJ.
 * - A name that is already in the table (as a name or other spelling) is not
 *   added again.
 *
 * Runs once: the option SEEDED_OPTION is set after the first run, even when
 * it found nothing, so DJs deleted later are not brought back.
 */
final class DjSeeder
{
    public const SEEDED_OPTION = 'eventmesh_djs_seeded';

    public function __construct(
        private readonly DjRepository $repository
    ) {
    }

    /**
     * @return int How many DJs were added. 0 when the seeding already ran.
     */
    public function seedOnce(): int
    {
        if ('1' === (string) get_option(self::SEEDED_OPTION, '') || ! DjTable::isInstalled()) {
            return 0;
        }

        update_option(self::SEEDED_OPTION, '1', false);

        $query = new WP_Query(
            [
                'post_type' => EventPostType::NAME,
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'orderby' => 'date',
                'order' => 'DESC',
            ]
        );

        $events = [];

        foreach ($query->posts as $postId) {
            $postId = (int) $postId;
            $post = get_post($postId);

            if (! $post instanceof \WP_Post) {
                continue;
            }

            $events[] = ['title' => $post->post_title, 'links' => self::eventLinks($postId)];
        }

        $added = 0;

        foreach (self::buildRows($events, $this->repository->all()) as $dj) {
            if ($this->repository->save($dj) > 0) {
                ++$added;
            }
        }

        return $added;
    }

    /**
     * The DJ names in an event title: the part after the first " / ", split
     * on "&". Empty when the title has no " / ".
     *
     * @return array<int, string>
     */
    public static function namesFromTitle(string $title): array
    {
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $position = strpos($title, ' / ');

        if (false === $position) {
            return [];
        }

        $names = [];

        foreach (explode('&', substr($title, $position + 3)) as $name) {
            $name = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $name));

            if ('' !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Turns events into new DJ rows, following the rules in the class
     * comment. Events must be given newest first.
     *
     * @param array<int, array{title: string, links: array<string, string>}> $events
     * @param array<int, Dj>                                                 $existing DJs already in the table.
     *
     * @return array<int, Dj> New rows, id 0, in the order first seen.
     */
    public static function buildRows(array $events, array $existing = []): array
    {
        $known = [];

        foreach ($existing as $dj) {
            foreach ($dj->names() as $name) {
                $known[DjMatcher::normalize($name)] = true;
            }
        }

        /** @var array<string, array{name: string, links: array<string, string>}> $rows */
        $rows = [];

        foreach ($events as $event) {
            $names = self::namesFromTitle($event['title']);
            $links = count($names) === 1 ? $event['links'] : [];

            foreach ($names as $name) {
                $key = DjMatcher::normalize($name);

                if ('' === $key || isset($known[$key])) {
                    continue;
                }

                $rows[$key] ??= ['name' => $name, 'links' => []];

                foreach (Dj::LINK_KINDS as $kind) {
                    $url = trim((string) ($links[$kind] ?? ''));

                    if ('' !== $url && ! isset($rows[$key]['links'][$kind])) {
                        $rows[$key]['links'][$kind] = $url;
                    }
                }
            }
        }

        return array_values(
            array_map(
                static fn (array $row): Dj => new Dj(0, $row['name'], [], $row['links']),
                $rows
            )
        );
    }

    /**
     * The event's Spotify, SoundCloud and Mixcloud links as the event shows
     * them (a hand-typed link before the source's), plus the player's own
     * link in case it is on a service the provider fields do not hold.
     *
     * @return array<string, string>
     */
    private static function eventLinks(int $postId): array
    {
        $links = [];

        foreach (EventMeta::resolvedProviders($postId) as $kind => $url) {
            if (in_array($kind, Dj::LINK_KINDS, true)) {
                $links[$kind] = $url;
            }
        }

        $playerUrl = trim((string) get_post_meta($postId, '_eventmesh_embed_source_url', true));
        $playerKind = '' !== $playerUrl ? KnownProviders::keyForUrl($playerUrl) : null;

        if (null !== $playerKind && in_array($playerKind, Dj::LINK_KINDS, true) && ! isset($links[$playerKind])) {
            $links[$playerKind] = $playerUrl;
        }

        return $links;
    }
}
