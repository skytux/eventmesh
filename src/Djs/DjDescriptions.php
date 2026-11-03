<?php

declare(strict_types=1);

namespace EventMesh\Djs;

use EventMesh\Support\Logger;

/**
 * Keeps the DJ descriptions filled.
 *
 * Where a description comes from, first to last:
 *
 * 1. manual: typed by an organizer on the DJ form. Never replaced here.
 * 2. team: the DJ's section of the team page chosen on the DJs page (see
 *    TeamPageParser).
 * 3. soundcloud / mixcloud: the DJ's profile text (see ProfileDescriptions).
 *
 * A refresh gives every DJ without a manual description its team text when
 * the team page has one, otherwise its profile text, otherwise it keeps the
 * text it has. Reading the team page is one database read, so it is done
 * right away. A profile is a web request, at most one per DJ per refresh,
 * so those go on a queue (an option) that a single WP-Cron event works
 * through within a time budget, scheduling itself again while work remains,
 * the same way the translation queue does. The "Fetch descriptions now"
 * button and a DJ save also work on the queue at once, within a budget.
 */
final class DjDescriptions
{
    public const HOOK = 'eventmesh/dj_descriptions';
    public const TEAM_PAGE_OPTION = 'eventmesh_dj_team_page';
    public const QUEUE_OPTION = 'eventmesh_dj_description_queue';

    /** A stored description is cut to about this many characters. */
    public const MAX_LENGTH = 1200;

    /** Seconds a cron run may use, counted from the start of the request. */
    public const CRON_SECONDS = 30;

    /** Seconds an admin request (button, DJ save) may spend on profiles. */
    public const NOW_SECONDS = 20;

    public function __construct(
        private readonly DjRepository $repository,
        private readonly ProfileDescriptions $profiles,
        private readonly Logger $logger
    ) {
    }

    public function boot(): void
    {
        add_action(self::HOOK, [$this, 'runFromCron']);
        add_action('save_post', [$this, 'onSavePost'], 20, 1);
    }

    public static function teamPageId(): int
    {
        return absint(get_option(self::TEAM_PAGE_OPTION, 0));
    }

    /**
     * Refreshes every DJ when the team page itself is saved. Profiles are
     * left to the cron queue, so saving the page does not wait for them.
     */
    public function onSavePost(int $postId): void
    {
        $teamPageId = self::teamPageId();

        if ($teamPageId > 0 && $postId === $teamPageId) {
            $this->refresh();
        }
    }

    public function runFromCron(): void
    {
        $start = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);

        $this->runQueue($start + self::CRON_SECONDS);
    }

    /**
     * Refreshes the given DJs (all when null).
     *
     * @param array<int, int>|null $ids
     * @param float|null           $deadline When given, profiles are fetched
     *                                       now until this microtime(true);
     *                                       the rest, or all when null, are
     *                                       left to the cron queue.
     *
     * @return array{updated: int, left: int} Descriptions changed, and DJs
     *                                        still waiting for a profile fetch.
     */
    public function refresh(?array $ids = null, ?float $deadline = null): array
    {
        $djs = $this->repository->all();
        $team = TeamPageParser::parse($djs, $this->teamPageContent());
        $updated = 0;
        $pending = [];

        foreach ($djs as $dj) {
            if (null !== $ids && ! in_array($dj->id, $ids, true)) {
                continue;
            }

            if ($dj->hasManualDescription()) {
                continue;
            }

            $teamText = $team[$dj->id] ?? '';

            if ('' !== $teamText) {
                $updated += $this->write($dj, self::decide($dj, $teamText, '', ''));
                continue;
            }

            if (null !== ProfileDescriptions::target($dj)) {
                $pending[] = $dj->id;
            }
        }

        $this->enqueue($pending, null === $deadline);

        if (null === $deadline) {
            return ['updated' => $updated, 'left' => count($this->queue())];
        }

        $run = $this->runQueue($deadline, $ids);

        return ['updated' => $updated + $run['updated'], 'left' => $run['left']];
    }

    /**
     * Fetches profiles for the queued DJs until the deadline.
     *
     * @param array<int, int>|null $only Work only on these queued DJs (all when null).
     *
     * @return array{updated: int, left: int} Descriptions changed, and DJs
     *                                        left on the whole queue.
     */
    public function runQueue(float $deadline, ?array $only = null): array
    {
        $queue = $this->queue();

        if (null !== $only) {
            $queue = array_values(array_intersect($queue, $only));
        }

        if ([] === $queue) {
            return ['updated' => 0, 'left' => count($this->queue())];
        }

        // The team page may have changed since the DJ was queued; team text
        // comes before profile text.
        $team = TeamPageParser::parse($this->repository->all(), $this->teamPageContent());
        $updated = 0;
        $done = [];

        foreach ($queue as $id) {
            if (microtime(true) + ProfileDescriptions::FETCH_TIMEOUT > $deadline) {
                break;
            }

            $done[] = $id;
            $dj = $this->repository->find($id);

            if (null === $dj || $dj->hasManualDescription()) {
                continue;
            }

            $teamText = $team[$id] ?? '';
            $target = ProfileDescriptions::target($dj);

            if ('' === $teamText && null === $target) {
                continue;
            }

            $profileText = '' === $teamText && null !== $target ? $this->profiles->fetch($target) : '';

            $updated += $this->write($dj, self::decide($dj, $teamText, $profileText, $target[0] ?? ''));
        }

        $left = array_values(array_diff($this->queue(), $done));
        update_option(self::QUEUE_OPTION, $left, false);

        if ([] !== $left) {
            $this->schedule();
        }

        if ($updated > 0) {
            $this->logger->info(
                sprintf('DJ descriptions: %d updated, %d DJ(s) still queued.', $updated, count($left))
            );
        }

        return ['updated' => $updated, 'left' => count($left)];
    }

    /**
     * The priority rules, without any WordPress calls.
     *
     * @param string $teamText      The DJ's text on the team page, '' for none.
     * @param string $profileText   The DJ's profile text, '' for none or not fetched.
     * @param string $profileSource Dj::SOURCE_SOUNDCLOUD or Dj::SOURCE_MIXCLOUD.
     *
     * @return array{0: string, 1: string}|null [description, source] to store,
     *                                          or null to keep what the DJ has.
     */
    public static function decide(Dj $dj, string $teamText, string $profileText, string $profileSource): ?array
    {
        if ($dj->hasManualDescription()) {
            return null;
        }

        $teamText = self::shorten($teamText);
        $profileText = self::shorten($profileText);

        if ('' !== $teamText) {
            $new = [$teamText, Dj::SOURCE_TEAM];
        } elseif ('' !== $profileText && in_array($profileSource, [Dj::SOURCE_SOUNDCLOUD, Dj::SOURCE_MIXCLOUD], true)) {
            $new = [$profileText, $profileSource];
        } else {
            return null;
        }

        return [$dj->description, $dj->descriptionSource] === $new ? null : $new;
    }

    /**
     * Cuts the text to about $max characters: at the last sentence end or
     * paragraph break in the second half of the allowed length, otherwise at
     * the last space with "…" added.
     */
    public static function shorten(string $text, int $max = self::MAX_LENGTH): string
    {
        $text = trim($text);

        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max, 'UTF-8');
        $half = (int) (strlen($cut) / 2);
        $end = 0;

        if (preg_match_all('/[.!?…](?=\s)|\n\n/u', $cut . ' ', $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$match, $offset]) {
                $position = "\n\n" === $match ? $offset : $offset + strlen($match);

                if ($position >= $half) {
                    $end = max($end, $position);
                }
            }
        }

        if ($end > 0) {
            return rtrim(substr($cut, 0, $end));
        }

        $space = strrpos($cut, ' ');

        if (false !== $space && $space >= $half) {
            return rtrim(substr($cut, 0, $space), " \t\n,;:-") . '…';
        }

        return rtrim(mb_substr($cut, 0, $max - 1, 'UTF-8')) . '…';
    }

    /**
     * @return array<int, int>
     */
    public function queue(): array
    {
        $queue = get_option(self::QUEUE_OPTION, []);

        return is_array($queue) ? array_values(array_map('intval', $queue)) : [];
    }

    /**
     * @param array<int, int> $ids
     */
    private function enqueue(array $ids, bool $schedule): void
    {
        if ([] === $ids) {
            return;
        }

        $queue = $this->queue();

        foreach ($ids as $id) {
            if ($id > 0 && ! in_array($id, $queue, true)) {
                $queue[] = $id;
            }
        }

        update_option(self::QUEUE_OPTION, $queue, false);

        if ($schedule) {
            $this->schedule();
        }
    }

    private function schedule(): void
    {
        if (false === wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time(), self::HOOK);
        }
    }

    /**
     * @param array{0: string, 1: string}|null $change From decide().
     *
     * @return int 1 when the description was changed, otherwise 0.
     */
    private function write(Dj $dj, ?array $change): int
    {
        if (null === $change) {
            return 0;
        }

        return $this->repository->saveDescription($dj->id, $change[0], $change[1]) ? 1 : 0;
    }

    private function teamPageContent(): string
    {
        $pageId = self::teamPageId();

        if ($pageId <= 0) {
            return '';
        }

        $page = get_post($pageId);

        return $page instanceof \WP_Post ? (string) $page->post_content : '';
    }
}
