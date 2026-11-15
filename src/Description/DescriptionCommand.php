<?php

declare(strict_types=1);

namespace EventMesh\Description;

use EventMesh\Translation\LlmSettings;
use EventMesh\Translation\TimeBudget;

/**
 * `wp eventmesh describe`: writes the descriptions the coming events still
 * lack, now, instead of waiting for the background runs. A text that is
 * there is never written again, unless --regenerate names the event.
 */
final class DescriptionCommand
{
    /** Runs that make no progress before the command gives up. */
    private const MAX_IDLE_RUNS = 3;

    public function __construct(
        private readonly DescriptionQueue $queue
    ) {
    }

    /**
     * Writes the descriptions the coming events still lack.
     *
     * ## OPTIONS
     *
     * [--event=<id>]
     * : Only this event.
     *
     * [--regenerate]
     * : With --event: write its Finnish text again, and the translations nobody edited.
     *
     * [--dry-run]
     * : List the events that would be written, and how many requests it takes.
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        $event = (int) ($assoc['event'] ?? 0);
        $dryRun = isset($assoc['dry-run']);

        if (isset($assoc['regenerate'])) {
            if ($event <= 0) {
                \WP_CLI::error('--regenerate needs --event=<id>.');
            }

            if (! $dryRun) {
                DescriptionStore::askRegenerate($event);
            }
        }

        $events = $event > 0
            ? (DescriptionStore::needsWork($event) || isset($assoc['regenerate']) ? [$event] : [])
            : DescriptionQueue::missing();

        if ([] === $events) {
            \WP_CLI::success('Every coming event has its description.');

            return;
        }

        $requests = 0;

        foreach ($events as $postId) {
            $work = [];

            foreach (DescriptionStore::languages() as $lang) {
                $decision = DescriptionStore::decide($postId, $lang);

                if (in_array($decision, ['write', 'translate', 'wait'], true)) {
                    $work[] = $lang;
                }
            }

            $requests += count($work);
            \WP_CLI::log(sprintf('#%d %s: %s', $postId, get_the_title($postId), implode(', ', $work)));
        }

        \WP_CLI::log(sprintf('%d event(s), about %d LLM request(s).', count($events), $requests));

        if ($dryRun) {
            return;
        }

        if (! LlmSettings::isReady()) {
            \WP_CLI::error('The LLM is off or not set up (EventMesh → Settings).');
        }

        $this->queue->enqueue($events);
        $idle = 0;
        $total = ['written' => 0, 'translated' => 0, 'failed' => 0];

        do {
            $result = $this->queue->run(new TimeBudget(microtime(true) + 300));

            foreach ($total as $key => $count) {
                $total[$key] = $count + $result[$key];
            }

            $idle = 0 === $result['written'] + $result['translated'] + $result['failed'] ? $idle + 1 : 0;

            if ('' !== $result['error']) {
                \WP_CLI::warning(
                    sprintf('The LLM request failed (%s). The rest is written in the background.', $result['error'])
                );
                break;
            }
        } while ($result['left'] > 0 && $idle < self::MAX_IDLE_RUNS);

        \WP_CLI::success(sprintf(
            '%d written, %d translated, %d failed the check, %d event(s) still queued.',
            $total['written'],
            $total['translated'],
            $total['failed'],
            $result['left']
        ));
    }
}
