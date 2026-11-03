<?php

declare(strict_types=1);

namespace EventMesh\Naming;

use EventMesh\Translation\LlmClient;
use EventMesh\Translation\LlmException;
use EventMesh\Translation\OutOfTime;
use EventMesh\Translation\TimeBudget;
use EventMesh\Translation\TranslationRejected;

/**
 * Asks the model, in one request per event, for the event's name without the
 * date and the DJ names, and for the DJ names the title holds. Only the
 * titles are sent. The answer must pass EventNameGuard.
 */
final class EventNamer
{
    /** The JSON answer is short. */
    private const MAX_TOKENS = 300;

    public const PROMPT = 'You clean up event titles. The user sends the title of an event, and sometimes '
        . 'its Finnish title as well. Remove the date, the time, and the names of the DJs, musicians and hosts, '
        . 'and keep only the name of the event. Use only words that are in the title, in the same order; '
        . 'do not add, change or translate words. Write the name in normal title case, not in capitals. '
        . 'List the DJ, musician and host names found in the original title, one person or act per item, '
        . 'spelled exactly as in the title. '
        . 'Reply with strict JSON only, exactly in the form '
        . '{"name":"...","name_fi":"...","djs":["..."]}. '
        . 'name is the name from the title, name_fi the name from the Finnish title ("" when no Finnish title '
        . 'was sent), and djs is [] when the title names nobody. No other text.';

    public function __construct(
        private readonly LlmClient $client
    ) {
    }

    public function model(): string
    {
        return $this->client->model();
    }

    /**
     * @return array{name: string, name_fi: string, djs: list<string>}
     *
     * @throws LlmException       The request failed.
     * @throws TranslationRejected The answer was not the JSON asked for, or failed the check.
     * @throws OutOfTime           No time left to start the request.
     */
    public function name(string $title, string $finnishTitle, TimeBudget $budget): array
    {
        $timeout = $budget->nextTimeout();

        if (null === $timeout) {
            throw new OutOfTime();
        }

        $reply = $this->client->chat(
            [
                ['role' => 'system', 'content' => self::PROMPT],
                ['role' => 'user', 'content' => self::userMessage($title, $finnishTitle)],
            ],
            $timeout,
            self::MAX_TOKENS
        );

        $decoded = self::parseReply($reply);

        if (null === $decoded) {
            throw new TranslationRejected('bad_reply');
        }

        return EventNameGuard::clean(
            $decoded['name'] ?? null,
            $decoded['name_fi'] ?? null,
            $decoded['djs'] ?? null,
            $title,
            $finnishTitle
        );
    }

    public static function userMessage(string $title, string $finnishTitle): string
    {
        $message = 'Title: ' . $title;

        if ('' !== trim($finnishTitle)) {
            $message .= "\nFinnish title: " . $finnishTitle;
        }

        return $message;
    }

    /**
     * Reads the JSON object from the model's reply, tolerating a code fence
     * or stray text around it.
     *
     * @return array<string, mixed>|null
     */
    public static function parseReply(string $reply): ?array
    {
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');

        if (false === $start || false === $end || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($reply, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
