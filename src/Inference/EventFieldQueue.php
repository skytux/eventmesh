<?php

declare(strict_types=1);

namespace EventMesh\Inference;

use EventMesh\Content\EventPostType;
use EventMesh\Support\Logger;
use EventMesh\Translation\LlmClient;
use EventMesh\Translation\LlmException;
use EventMesh\Translation\LlmSettings;
use EventMesh\Translation\OutOfTime;
use EventMesh\Translation\TimeBudget;
use EventMesh\Translation\TranslationRejected;
use EventMesh\Translation\TranslationStore;

/**
 * Fills in, in the background, the event fields the parser could not find
 * (InferredFields::FIELDS), the same way EventNameQueue writes event names:
 * a synced event with such a field goes on a queue (an option), and a single
 * WP-Cron event works through it within the shared time budget. Only empty
 * fields are filled, never a value the source gave. An event is asked about
 * again only when its source text changed. Nothing runs, and nothing is
 * queued, while the LLM is off or not set up.
 */
final class EventFieldQueue
{
    public const HOOK = 'eventmesh/infer_queue';
    public const OPTION_QUEUE = 'eventmesh_infer_queue';

    /** After a connection or server error, wait this long before trying again. */
    public const RETRY_AFTER_ERROR = 900;

    /** @var array{filled: int, failed: int} */
    private array $stats = ['filled' => 0, 'failed' => 0];

    /**
     * @param \Closure(): EventFieldInferrer|null $inferrerFactory Tests pass an inferrer with a stubbed client.
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ?\Closure $inferrerFactory = null
    ) {
    }

    public function boot(): void
    {
        add_action('eventmesh/event_synced', [$this, 'onEventSynced'], 10, 1);
        add_action(self::HOOK, [$this, 'runFromCron']);
    }

    public function onEventSynced(int $postId): void
    {
        if (! LlmSettings::isReady()) {
            return;
        }

        if ([] === InferredFields::fieldsToAsk($postId, InferredFields::hashFor($postId))) {
            return;
        }

        $this->enqueue([$postId]);
    }

    /**
     * @param array<int, int> $postIds
     */
    public function enqueue(array $postIds): void
    {
        if (! LlmSettings::isReady()) {
            return;
        }

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

        if ([] !== $queue) {
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
     * @return array{ready: bool, filled: int, failed: int, left: int, error: string}
     */
    public function run(TimeBudget $budget): array
    {
        $this->stats = ['filled' => 0, 'failed' => 0];

        if (! LlmSettings::isReady()) {
            return ['ready' => false, 'filled' => 0, 'failed' => 0, 'left' => count($this->queue()), 'error' => ''];
        }

        $inferrer = null;
        $done = [];
        $error = '';
        $outOfTime = false;

        foreach ($this->queue() as $postId) {
            $inferrer ??= $this->inferrer();
            $result = $this->processPost($postId, $inferrer, $budget);

            if ('done' === $result) {
                $done[] = $postId;
                continue;
            }

            if ('retry' === $result) {
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
            $this->schedule('' !== $error ? self::RETRY_AFTER_ERROR : 0);
        }

        if ('' !== $error) {
            $this->logger->warning(
                sprintf('Missing event fields stopped: the LLM server request failed (%s).', $error)
            );
        } elseif ($this->stats['filled'] > 0 || $this->stats['failed'] > 0) {
            $this->logger->info(
                sprintf(
                    'Missing event fields: %d field(s) filled, %d request(s) failed, %d event(s) still queued%s.',
                    $this->stats['filled'],
                    $this->stats['failed'],
                    count($left),
                    $outOfTime ? ' (time budget used up)' : ''
                )
            );
        }

        return [
            'ready' => true,
            'filled' => $this->stats['filled'],
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
    private function processPost(int $postId, EventFieldInferrer $inferrer, TimeBudget $budget): string
    {
        $post = get_post($postId);

        if (
            ! $post instanceof \WP_Post
            || EventPostType::NAME !== $post->post_type
            || 'publish' !== $post->post_status
        ) {
            return 'done';
        }

        $title = self::plain(InferredFields::sourceTitle($postId));
        $text = self::plainText(InferredFields::sourceContent($postId));

        if ('' === $title && '' === $text) {
            return 'done';
        }

        $hash = InferredFields::hashFor($postId);
        $fields = InferredFields::fieldsToAsk($postId, $hash);

        if ([] === $fields) {
            return 'done';
        }

        $failure = TranslationStore::failure($postId, InferredFields::META_FAIL);

        if (TranslationStore::attemptsFor($failure, $hash) >= TranslationStore::MAX_ATTEMPTS) {
            return 'done';
        }

        try {
            $values = $inferrer->infer($title, $text, $fields, $this->known($postId, $fields), $budget);
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

        $written = InferredFields::write($postId, $values, $fields, $hash);
        $this->stats['filled'] += count($written);

        return 'done';
    }

    /**
     * The current values of the fields not being asked for, so the model
     * can, for example, put an end time on the known start date.
     *
     * @param list<string> $asking
     *
     * @return array<string, string>
     */
    private function known(int $postId, array $asking): array
    {
        $known = [];

        foreach (InferredFields::FIELDS as $field) {
            if (in_array($field, $asking, true)) {
                continue;
            }

            $value = trim((string) get_post_meta($postId, InferredFields::metaKey($field), true));

            if ('' !== $value) {
                $known[$field] = $value;
            }
        }

        return $known;
    }

    /**
     * Records a failed attempt. 'retry' while attempts remain for this text,
     * 'done' once it has been given up on.
     */
    private function failed(int $postId, string $hash, string $kind): string
    {
        $count = TranslationStore::recordFailure($postId, InferredFields::META_FAIL, $hash, $kind);
        ++$this->stats['failed'];

        $this->logger->warning(
            sprintf(
                'Missing fields of event #%d failed: %s, attempt %d of %d.',
                $postId,
                $kind,
                $count,
                TranslationStore::MAX_ATTEMPTS
            )
        );

        return $count < TranslationStore::MAX_ATTEMPTS ? 'retry' : 'done';
    }

    private static function plain(string $text): string
    {
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * The description as plain text, one line per paragraph or line break,
     * capped at EventFieldInferrer::MAX_TEXT characters.
     */
    private static function plainText(string $html): string
    {
        $html = (string) preg_replace('#<br\s*/?>|</(?:p|div|li|h[1-6])>#i', "\n", $html);
        $text = self::plain(wp_strip_all_tags($html));
        $text = (string) preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s*\n\s*/u', "\n", $text));

        return mb_substr($text, 0, EventFieldInferrer::MAX_TEXT);
    }

    private function inferrer(): EventFieldInferrer
    {
        if (null !== $this->inferrerFactory) {
            return ($this->inferrerFactory)();
        }

        return new EventFieldInferrer(LlmClient::fromSettings());
    }
}
