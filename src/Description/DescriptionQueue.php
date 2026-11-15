<?php

declare(strict_types=1);

namespace EventMesh\Description;

use EventMesh\Content\EventPostType;
use EventMesh\Naming\EventNameQueue;
use EventMesh\Support\EventMeta;
use EventMesh\Support\LocalTime;
use EventMesh\Support\Logger;
use EventMesh\Translation\LlmClient;
use EventMesh\Translation\LlmException;
use EventMesh\Translation\LlmSettings;
use EventMesh\Translation\OutOfTime;
use EventMesh\Translation\TimeBudget;
use EventMesh\Translation\TranslationQueue;
use EventMesh\Translation\TranslationRejected;
use EventMesh\Translation\TranslationStore;
use EventMesh\Translation\Translator;
use WP_Query;

/**
 * Writes the coming events' short descriptions in the background, once.
 *
 * Every sync puts each coming event that still lacks a description (or a
 * translation of it) on the queue. A WP-Cron run writes the Finnish text,
 * then translates it into the target languages. An event waits while it is
 * still on the translation or the event name queue, so its Finnish text and
 * DJ names are there first. A text is never written again by itself: only
 * a missing one, or one someone asked to be written again
 * (DescriptionStore::askRegenerate()). Hand-edited texts are never touched.
 */
final class DescriptionQueue
{
    public const HOOK = 'eventmesh/describe_queue';
    public const OPTION_QUEUE = 'eventmesh_description_queue';

    /** The last Finnish texts written, so the next one does not repeat them. */
    public const OPTION_MEMORY = 'eventmesh_description_memory';
    private const MEMORY_SIZE = 5;

    public const RETRY_AFTER_ERROR = 900;

    /** While an event waits for its translation or its name, look again after this long. */
    public const WAIT = 120;

    /**
     * @var array{written: int, translated: int, failed: int}
     */
    private array $stats = ['written' => 0, 'translated' => 0, 'failed' => 0];

    /**
     * @param \Closure(): DescriptionWriter|null $writerFactory Tests pass a writer with a stubbed client.
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ?\Closure $writerFactory = null
    ) {
    }

    public function boot(): void
    {
        add_action('eventmesh/event_synced', [$this, 'onEventChanged'], 20, 1);
        add_action(self::HOOK, [$this, 'runFromCron']);
    }

    public function onEventChanged(int $postId): void
    {
        if (self::isComing($postId) && DescriptionStore::needsWork($postId)) {
            $this->enqueue([$postId]);
        }
    }

    /**
     * @param array<int, int> $postIds
     */
    public function enqueue(array $postIds): void
    {
        $queue = $this->queue();
        $changed = false;

        foreach ($postIds as $postId) {
            $postId = (int) $postId;

            if ($postId > 0 && ! in_array($postId, $queue, true)) {
                $queue[] = $postId;
                $changed = true;
            }
        }

        if ($changed) {
            update_option(self::OPTION_QUEUE, $queue, false);
        }

        if (LlmSettings::isReady() && [] !== $queue) {
            $this->schedule(0);
        }
    }

    /**
     * @return array<int, int>
     */
    public function queue(): array
    {
        $queue = get_option(self::OPTION_QUEUE, []);

        return is_array($queue) ? array_values(array_map('intval', $queue)) : [];
    }

    public function schedule(int $delay): void
    {
        if (false === wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + $delay, self::HOOK);
        }
    }

    public function runFromCron(): void
    {
        $this->run(TimeBudget::fromRequestStart());
    }

    /**
     * The published events that have not started yet, soonest first.
     *
     * @return list<int>
     */
    public static function comingEvents(): array
    {
        $query = new WP_Query(
            [
                'post_type' => EventPostType::NAME,
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
            ]
        );

        $events = [];

        foreach ($query->posts as $postId) {
            $postId = (int) (is_object($postId) ? $postId->ID : $postId);

            if (self::isComing($postId)) {
                $events[$postId] = EventMeta::resolve($postId, 'starts_at');
            }
        }

        asort($events);

        return array_keys($events);
    }

    public static function isComing(int $postId): bool
    {
        $post = get_post($postId);

        if (
            ! $post instanceof \WP_Post
            || EventPostType::NAME !== $post->post_type
            || 'publish' !== $post->post_status
        ) {
            return false;
        }

        $starts = LocalTime::parse(EventMeta::resolve($postId, 'starts_at'));
        $now = LocalTime::parse('now');

        return null !== $starts && null !== $now && $starts >= $now;
    }

    /**
     * The coming events with work left, for the command line.
     *
     * @return list<int>
     */
    public static function missing(): array
    {
        return array_values(array_filter(
            self::comingEvents(),
            static fn (int $postId): bool => DescriptionStore::needsWork($postId)
        ));
    }

    /**
     * @return array{ready: bool, written: int, translated: int, failed: int, left: int, error: string}
     */
    public function run(TimeBudget $budget): array
    {
        $this->stats = ['written' => 0, 'translated' => 0, 'failed' => 0];

        if (! LlmSettings::isReady()) {
            return ['ready' => false, 'left' => count($this->queue()), 'error' => ''] + $this->stats;
        }

        $writer = null;
        $done = [];
        $error = '';
        $outOfTime = false;
        $tryAgainSoon = false;
        $waiting = array_merge(
            self::queued(TranslationQueue::OPTION_QUEUE),
            self::queued(EventNameQueue::OPTION_QUEUE)
        );

        foreach ($this->queue() as $postId) {
            if (in_array($postId, $waiting, true)) {
                continue;
            }

            $writer ??= $this->writer();
            $result = $this->processPost($postId, $writer, $budget);

            if ('done' === $result) {
                $done[] = $postId;
                continue;
            }

            if ('retry' === $result) {
                $tryAgainSoon = true;
                continue;
            }

            if ('out_of_time' === $result) {
                $outOfTime = true;
                break;
            }

            $error = $result;
            break;
        }

        $left = array_values(array_diff($this->queue(), $done));
        update_option(self::OPTION_QUEUE, $left, false);

        if ([] !== $left) {
            if ('' !== $error) {
                $this->schedule(self::RETRY_AFTER_ERROR);
            } elseif ($outOfTime || $tryAgainSoon) {
                $this->schedule(0);
            } else {
                $this->schedule(self::WAIT);
            }
        }

        if ('' !== $error) {
            $this->logger->warning(sprintf('Event descriptions stopped: the LLM server request failed (%s).', $error));
        } elseif (array_sum($this->stats) > 0) {
            $this->logger->info(
                sprintf(
                    'Event descriptions: %d written, %d translated, %d failed the check, %d event(s) still queued%s.',
                    $this->stats['written'],
                    $this->stats['translated'],
                    $this->stats['failed'],
                    count($left),
                    $outOfTime ? ' (time budget used up)' : ''
                )
            );
        }

        return [
            'ready' => true,
            'written' => $this->stats['written'],
            'translated' => $this->stats['translated'],
            'failed' => $this->stats['failed'],
            'left' => count($left),
            'error' => $error,
        ];
    }

    /**
     * @return string 'done' (off the queue), 'retry' (stay queued, try on a
     *                later run), 'out_of_time', or the kind of a connection
     *                or server error.
     */
    private function processPost(int $postId, DescriptionWriter $writer, TimeBudget $budget): string
    {
        $post = get_post($postId);

        if (
            ! $post instanceof \WP_Post
            || EventPostType::NAME !== $post->post_type
            || 'publish' !== $post->post_status
        ) {
            return 'done';
        }

        $result = 'done';

        foreach (DescriptionStore::languages() as $lang) {
            $decision = DescriptionStore::decide($postId, $lang);

            if ('write' !== $decision && 'translate' !== $decision) {
                continue;
            }

            $finnish = DescriptionStore::text($postId, DescriptionStore::WRITES_IN);
            $source = 'write' === $decision ? DescriptionStore::WRITE_HASH : DescriptionStore::hashOf($finnish);

            try {
                if ('write' === $decision) {
                    $text = DescriptionStore::clean($writer->write($post, self::memory(), $budget));
                    DescriptionStore::writeAuto($postId, $lang, $text, $writer->model());
                    delete_post_meta($postId, DescriptionStore::META_REGENERATE);
                    self::remember($text);
                    ++$this->stats['written'];
                } else {
                    $text = $writer->translate($finnish, $lang, $budget);
                    DescriptionStore::writeAuto($postId, $lang, $text, $writer->model(), $source);
                    ++$this->stats['translated'];
                }
            } catch (OutOfTime) {
                return 'out_of_time';
            } catch (LlmException $exception) {
                if ($exception->isTransport()) {
                    return $exception->kind();
                }

                $result = $this->failed($postId, $lang, $source, $exception->kind()) ?? $result;
            } catch (TranslationRejected $exception) {
                $result = $this->failed($postId, $lang, $source, $exception->kind()) ?? $result;
            }

            if ('write' === $decision && 'done' !== $result) {
                // No Finnish text yet: nothing to translate from.
                return $result;
            }
        }

        return $result;
    }

    /**
     * Records a failed attempt: 'retry' while attempts remain, else null.
     */
    private function failed(int $postId, string $lang, string $source, string $kind): ?string
    {
        $count = TranslationStore::recordFailure($postId, DescriptionStore::failureKey($lang), $source, $kind);
        ++$this->stats['failed'];

        $this->logger->warning(
            sprintf(
                'Description of event #%d (%s) failed: %s, attempt %d of %d.',
                $postId,
                $lang,
                $kind,
                $count,
                TranslationStore::MAX_ATTEMPTS
            )
        );

        return $count < TranslationStore::MAX_ATTEMPTS ? 'retry' : null;
    }

    /**
     * @return list<string>
     */
    public static function memory(): array
    {
        $memory = get_option(self::OPTION_MEMORY, []);

        return is_array($memory) ? array_values(array_filter($memory, 'is_string')) : [];
    }

    private static function remember(string $text): void
    {
        $memory = self::memory();
        $memory[] = $text;

        update_option(self::OPTION_MEMORY, array_slice($memory, -self::MEMORY_SIZE), false);
    }

    /**
     * @return array<int, int>
     */
    private static function queued(string $option): array
    {
        $queue = get_option($option, []);

        return is_array($queue) ? array_values(array_map('intval', $queue)) : [];
    }

    private function writer(): DescriptionWriter
    {
        if (null !== $this->writerFactory) {
            return ($this->writerFactory)();
        }

        $client = LlmClient::fromSettings();

        return new DescriptionWriter($client, new Translator($client));
    }
}
