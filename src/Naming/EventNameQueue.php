<?php

declare(strict_types=1);

namespace EventMesh\Naming;

use EventMesh\Content\EventPostType;
use EventMesh\Support\Logger;
use EventMesh\Translation\LlmClient;
use EventMesh\Translation\LlmException;
use EventMesh\Translation\LlmSettings;
use EventMesh\Translation\OutOfTime;
use EventMesh\Translation\TimeBudget;
use EventMesh\Translation\TranslationQueue;
use EventMesh\Translation\TranslationRejected;
use EventMesh\Translation\TranslationStore;

/**
 * Writes each event's clean name and DJ names in the background, the same
 * way TranslationQueue writes translations: synced and saved events go on a
 * queue (an option), and a single WP-Cron event works through it within the
 * shared time budget. An event is named again only when its title or its
 * Finnish title changed since the name was written. An event still waiting
 * for its translation waits here too, so the Finnish name is made from the
 * Finnish title in the same request. Nothing runs while the LLM is off or
 * not set up.
 */
final class EventNameQueue
{
    public const HOOK = 'eventmesh/name_queue';
    public const OPTION_QUEUE = 'eventmesh_event_name_queue';

    /** After a connection or server error, wait this long before trying again. */
    public const RETRY_AFTER_ERROR = 900;

    /** While events only wait for their translation, look again after this long. */
    public const WAIT_FOR_TRANSLATION = 120;

    /** @var array{named: int, failed: int} */
    private array $stats = ['named' => 0, 'failed' => 0];

    /**
     * @param \Closure(): EventNamer|null $namerFactory Tests pass a namer with a stubbed client.
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ?\Closure $namerFactory = null
    ) {
    }

    public function boot(): void
    {
        add_action('eventmesh/event_synced', [$this, 'onEventChanged'], 10, 1);
        // After the translation box has saved a hand-edited Finnish title.
        add_action('save_post_' . EventPostType::NAME, [$this, 'onEventChanged'], 30, 1);
        add_action(self::HOOK, [$this, 'runFromCron']);
    }

    public function onEventChanged(int $postId): void
    {
        $this->enqueue([$postId]);
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
     * @return array{ready: bool, named: int, failed: int, left: int, error: string}
     */
    public function run(TimeBudget $budget): array
    {
        $this->stats = ['named' => 0, 'failed' => 0];

        if (! LlmSettings::isReady()) {
            return ['ready' => false, 'named' => 0, 'failed' => 0, 'left' => count($this->queue()), 'error' => ''];
        }

        $namer = null;
        $done = [];
        $error = '';
        $outOfTime = false;
        $tryAgainSoon = false;
        $translating = $this->translationQueue();

        foreach ($this->queue() as $postId) {
            if (in_array($postId, $translating, true)) {
                continue;
            }

            $namer ??= $this->namer();
            $result = $this->processPost($postId, $namer, $budget);

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
                $this->schedule(self::WAIT_FOR_TRANSLATION);
            }
        }

        if ('' !== $error) {
            $this->logger->warning(sprintf('Event names stopped: the LLM server request failed (%s).', $error));
        } elseif ($this->stats['named'] > 0 || $this->stats['failed'] > 0) {
            $this->logger->info(
                sprintf(
                    'Event names: %d written, %d failed the check, %d event(s) still queued%s.',
                    $this->stats['named'],
                    $this->stats['failed'],
                    count($left),
                    $outOfTime ? ' (time budget used up)' : ''
                )
            );
        }

        return [
            'ready' => true,
            'named' => $this->stats['named'],
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
    private function processPost(int $postId, EventNamer $namer, TimeBudget $budget): string
    {
        $post = get_post($postId);

        if (
            ! $post instanceof \WP_Post
            || EventPostType::NAME !== $post->post_type
            || 'publish' !== $post->post_status
            || '' === trim((string) $post->post_title)
        ) {
            return 'done';
        }

        $finnishTitle = EventNameStore::finnishTitle($post);
        $hash = EventNameStore::sourceHash((string) $post->post_title, $finnishTitle);

        if ($hash === EventNameStore::read($postId)['hash']) {
            return 'done';
        }

        $failure = TranslationStore::failure($postId, EventNameStore::META_FAIL);

        if (TranslationStore::attemptsFor($failure, $hash) >= TranslationStore::MAX_ATTEMPTS) {
            return 'done';
        }

        try {
            $result = $namer->name(self::plain((string) $post->post_title), self::plain($finnishTitle), $budget);

            // Checked again after sanitising: what is saved must be what passed.
            $name = sanitize_text_field($result['name']);
            $nameFi = sanitize_text_field($result['name_fi']);
            $djs = array_map(static fn (string $dj): string => sanitize_text_field($dj), $result['djs']);

            if ($name !== $result['name'] || $nameFi !== $result['name_fi'] || $djs !== $result['djs']) {
                throw new TranslationRejected('unsafe');
            }
        } catch (OutOfTime) {
            return 'out_of_time';
        } catch (LlmException $exception) {
            if ($exception->isTransport()) {
                return $exception->kind();
            }

            return $this->failed($postId, $hash, $exception->kind());
        } catch (TranslationRejected $exception) {
            return $this->failed($postId, $hash, $exception->kind());
        }

        EventNameStore::write($postId, $name, $nameFi, $djs, $hash);
        ++$this->stats['named'];

        return 'done';
    }

    /**
     * Records a failed attempt. 'retry' while attempts remain for these
     * titles, 'done' once they have been given up on.
     */
    private function failed(int $postId, string $hash, string $kind): string
    {
        $count = TranslationStore::recordFailure($postId, EventNameStore::META_FAIL, $hash, $kind);
        ++$this->stats['failed'];

        $this->logger->warning(
            sprintf(
                'Event name of event #%d failed: %s, attempt %d of %d.',
                $postId,
                $kind,
                $count,
                TranslationStore::MAX_ATTEMPTS
            )
        );

        return $count < TranslationStore::MAX_ATTEMPTS ? 'retry' : 'done';
    }

    /**
     * Titles are stored with HTML entities ("&amp;"); the model and the
     * check work on the plain text.
     */
    private static function plain(string $title): string
    {
        return trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @return array<int, int>
     */
    private function translationQueue(): array
    {
        $queue = get_option(TranslationQueue::OPTION_QUEUE, []);

        return is_array($queue) ? array_values(array_map('intval', $queue)) : [];
    }

    private function namer(): EventNamer
    {
        if (null !== $this->namerFactory) {
            return ($this->namerFactory)();
        }

        return new EventNamer(LlmClient::fromSettings());
    }
}
