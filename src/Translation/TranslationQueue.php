<?php

declare(strict_types=1);

namespace EventMesh\Translation;

use EventMesh\Content\EventPostType;
use EventMesh\Support\Logger;
use WP_Query;

/**
 * Keeps event translations up to date in the background.
 *
 * Every synced event is put on a queue (an option). A single WP-Cron event
 * works through the queue within a time budget and schedules itself again
 * while work remains. For each event it first has the model name the
 * original's language (only when the original text changed since the last
 * detection), then translates into every target language whose translation
 * is missing or was made from an older original. A translation a person
 * edited is never overwritten.
 */
final class TranslationQueue
{
    public const HOOK = 'eventmesh/translate_queue';
    public const OPTION_QUEUE = 'eventmesh_translation_queue';
    public const OPTION_STATUS = 'eventmesh_translation_status';

    /** After a connection or server error, wait this long before trying again. */
    public const RETRY_AFTER_ERROR = 900;

    /**
     * @var array{translated: int, failed: int}
     */
    private array $stats = ['translated' => 0, 'failed' => 0];

    /**
     * @param \Closure(): Translator|null $translatorFactory Tests pass a translator with a stubbed client.
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ?\Closure $translatorFactory = null
    ) {
    }

    public function boot(): void
    {
        add_action('eventmesh/event_synced', [$this, 'onEventSynced'], 10, 1);
        add_action(self::HOOK, [$this, 'runFromCron']);
    }

    public function onEventSynced(int $postId): void
    {
        $this->enqueue([$postId]);
    }

    /**
     * @param array<int, int> $postIds
     */
    public function enqueue(array $postIds): void
    {
        $queue = $this->queue();

        foreach ($postIds as $postId) {
            $postId = (int) $postId;

            if ($postId > 0 && ! in_array($postId, $queue, true)) {
                $queue[] = $postId;
            }
        }

        update_option(self::OPTION_QUEUE, $queue, false);

        if (LlmSettings::isReady()) {
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
     * Puts every published event on the queue and works on it right away.
     *
     * @return array{ready: bool, translated: int, failed: int, left: int, error: string}
     */
    public function translateNow(): array
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

        $this->enqueue(array_map('intval', $query->posts));

        return $this->run(TimeBudget::fromRequestStart());
    }

    /**
     * Sends one tiny request with the saved settings.
     *
     * @return string 'ok', 'not_configured', or the error kind.
     */
    public function testConnection(): string
    {
        if ('' === LlmSettings::url() || '' === LlmSettings::model() || ! LlmSettings::hasKey()) {
            return 'not_configured';
        }

        try {
            LlmClient::fromSettings()->chat(
                [['role' => 'user', 'content' => 'Reply with the word OK.']],
                15,
                5
            );
        } catch (LlmException $exception) {
            return $exception->kind();
        }

        return 'ok';
    }

    /**
     * @return array{ready: bool, translated: int, failed: int, left: int, error: string}
     */
    public function run(TimeBudget $budget): array
    {
        $this->stats = ['translated' => 0, 'failed' => 0];

        if (! LlmSettings::isReady()) {
            return ['ready' => false, 'translated' => 0, 'failed' => 0, 'left' => count($this->queue()), 'error' => ''];
        }

        $translator = $this->translator();
        $targets = LlmSettings::targetLanguages();
        $done = [];
        $error = '';
        $outOfTime = false;

        foreach ($this->queue() as $postId) {
            $result = $this->processPost($postId, $translator, $targets, $budget);

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
            $this->logger->warning(sprintf('Translation stopped: the LLM server request failed (%s).', $error));
        } elseif ($this->stats['translated'] > 0 || $this->stats['failed'] > 0) {
            $this->logger->info(
                sprintf(
                    'Translation run: %d translated, %d failed the check, %d event(s) still queued%s.',
                    $this->stats['translated'],
                    $this->stats['failed'],
                    count($left),
                    $outOfTime ? ' (time budget used up)' : ''
                )
            );
        }

        $summary = [
            'ready' => true,
            'translated' => $this->stats['translated'],
            'failed' => $this->stats['failed'],
            'left' => count($left),
            'error' => $error,
        ];

        update_option(self::OPTION_STATUS, $summary + ['time' => time()], false);

        return $summary;
    }

    /**
     * @param array<int, string> $targets
     *
     * @return string 'done' (off the queue), 'retry' (stay queued, try on a
     *                later run), 'out_of_time', or the kind of a connection
     *                or server error.
     */
    private function processPost(int $postId, Translator $translator, array $targets, TimeBudget $budget): string
    {
        $post = get_post($postId);

        if (
            ! $post instanceof \WP_Post
            || EventPostType::NAME !== $post->post_type
            || 'publish' !== $post->post_status
        ) {
            return 'done';
        }

        $hash = TranslationStore::originalHash($post);
        $originalLang = TranslationStore::originalLanguage($postId);

        $detectedFor = (string) get_post_meta($postId, TranslationStore::META_ORIGINAL_LANG_HASH, true);

        if ('' === $originalLang || $hash !== $detectedFor) {
            $langFailKey = TranslationStore::META_ORIGINAL_LANG_FAIL;
            $failure = TranslationStore::failure($postId, $langFailKey);

            if (TranslationStore::attemptsFor($failure, $hash) >= TranslationStore::MAX_ATTEMPTS) {
                return 'done';
            }

            try {
                $originalLang = $translator->detectLanguage($post->post_title, $post->post_content, $budget);
            } catch (OutOfTime) {
                return 'out_of_time';
            } catch (LlmException $exception) {
                if ($exception->isTransport()) {
                    return $exception->kind();
                }

                return $this->failed($postId, $langFailKey, $hash, $exception->kind(), 'language');
            } catch (TranslationRejected $exception) {
                return $this->failed($postId, $langFailKey, $hash, $exception->kind(), 'language');
            }

            update_post_meta($postId, TranslationStore::META_ORIGINAL_LANG, $originalLang);
            update_post_meta($postId, TranslationStore::META_ORIGINAL_LANG_HASH, $hash);
            delete_post_meta($postId, TranslationStore::META_ORIGINAL_LANG_FAIL);
        }

        $result = 'done';

        foreach (TranslationStore::targetsFor($targets, $originalLang) as $target) {
            $decision = TranslationStore::decide(
                TranslationStore::read($postId, $target),
                TranslationStore::failure($postId, TranslationStore::failureKey($target)),
                $hash
            );

            if ('translate' !== $decision) {
                continue;
            }

            $failKey = TranslationStore::failureKey($target);

            try {
                $this->translatePost($post, $hash, $originalLang, $target, $translator, $budget);
            } catch (OutOfTime) {
                return 'out_of_time';
            } catch (LlmException $exception) {
                if ($exception->isTransport()) {
                    return $exception->kind();
                }

                if ('retry' === $this->failed($postId, $failKey, $hash, $exception->kind(), $target)) {
                    $result = 'retry';
                }
            } catch (TranslationRejected $exception) {
                if ('retry' === $this->failed($postId, $failKey, $hash, $exception->kind(), $target)) {
                    $result = 'retry';
                }
            }
        }

        return $result;
    }

    /**
     * @throws LlmException
     * @throws TranslationRejected
     * @throws OutOfTime
     */
    private function translatePost(
        \WP_Post $post,
        string $hash,
        string $from,
        string $to,
        Translator $translator,
        TimeBudget $budget
    ): void {
        $originalTitle = (string) $post->post_title;
        $originalContent = (string) $post->post_content;

        $title = '' !== trim($originalTitle)
            ? sanitize_text_field($translator->translate($originalTitle, $from, $to, $budget))
            : '';
        $content = '' !== trim($originalContent)
            ? wp_kses_post($translator->translate($originalContent, $from, $to, $budget))
            : '';

        // Checked again after sanitising: what is saved must pass, not only
        // what the model sent.
        $reason = ('' !== trim($originalTitle) ? TranslationGuard::check($originalTitle, $title) : null)
            ?? ('' !== trim($originalContent) ? TranslationGuard::check($originalContent, $content) : null);

        if (null !== $reason) {
            throw new TranslationRejected($reason);
        }

        TranslationStore::write(
            $post->ID,
            $to,
            [
                'title' => $title,
                'content' => $content,
                'excerpt' => '' !== $content ? wp_trim_words($content, 55, '') : '',
                'hash' => $hash,
                'status' => TranslationStore::STATUS_AUTO,
                'model' => $translator->model(),
                'time' => time(),
            ]
        );

        delete_post_meta($post->ID, TranslationStore::failureKey($to));
        ++$this->stats['translated'];
    }

    /**
     * Records a failed attempt. 'retry' while attempts remain for this
     * original, 'done' once it has been given up on.
     */
    private function failed(int $postId, string $metaKey, string $hash, string $kind, string $what): string
    {
        $count = TranslationStore::recordFailure($postId, $metaKey, $hash, $kind);
        ++$this->stats['failed'];

        $this->logger->warning(
            sprintf(
                'Translation of event #%d (%s) failed: %s, attempt %d of %d.',
                $postId,
                $what,
                $kind,
                $count,
                TranslationStore::MAX_ATTEMPTS
            )
        );

        return $count < TranslationStore::MAX_ATTEMPTS ? 'retry' : 'done';
    }

    private function translator(): Translator
    {
        if (null !== $this->translatorFactory) {
            return ($this->translatorFactory)();
        }

        return new Translator(LlmClient::fromSettings());
    }
}
