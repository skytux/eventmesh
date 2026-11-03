<?php

declare(strict_types=1);

namespace EventMesh\Inference;

use DateTimeImmutable;
use EventMesh\Support\LocalTime;
use EventMesh\Translation\LlmClient;
use EventMesh\Translation\LlmException;
use EventMesh\Translation\OutOfTime;
use EventMesh\Translation\TimeBudget;
use EventMesh\Translation\TranslationRejected;

/**
 * Asks the model, in one request per event, for the event fields the parser
 * could not find in the event's text. Only the source title and description
 * are sent, with the fields already known. Every value in the answer is
 * checked; a value that fails the check is dropped, never stored.
 */
final class EventFieldInferrer
{
    /** The JSON answer is short. */
    private const MAX_TOKENS = 300;

    /** Longest description sent, in characters. */
    public const MAX_TEXT = 6000;

    /** Longest value kept per text field, in characters. */
    public const MAX_LENGTH = [
        'venue_name' => 120,
        'venue_address' => 200,
        'price' => 40,
    ];

    /** Longest event an inferred end may give, in days. */
    private const MAX_DAYS = 14;

    public const PROMPT = 'You read the description of an event and find details that are missing. '
        . 'The user sends the event title, its description, today\'s date, the details already known, '
        . 'and the details to find. Use only what the title and description say; when they do not say it, '
        . 'answer null. Do not guess. '
        . 'starts_at and ends_at are the local date and time in the form YYYY-MM-DDTHH:MM (24-hour clock). '
        . 'When the text says when the doors open, starts_at is that time, never the time the doors close. '
        . 'When the text gives a time but no date, take the date from the title or the known details. '
        . 'venue_name is the name of the place; venue_address is its street address, with the city when given. '
        . 'price is the ticket price as written in the text, for example "15 €". '
        . 'Reply with strict JSON only: one object with exactly the keys asked for, each a string or null, '
        . 'for example {"starts_at":"2026-11-14T18:30","venue_name":null}. No other text.';

    /** Answers that mean "not known" rather than a value. */
    private const NOT_A_VALUE = '/^(?:null|none|n\/?a|unknown|not (?:given|stated|specified)'
        . '|tuntematon|ei tiedossa|-+|\?+)$/iu';

    public function __construct(
        private readonly LlmClient $client
    ) {
    }

    /**
     * @param list<string>          $fields The fields to find, from InferredFields::FIELDS.
     * @param array<string, string> $known  Fields already known, field => value.
     *
     * @return array<string, string> field => checked value, only for fields that passed
     *
     * @throws LlmException        The request failed.
     * @throws TranslationRejected The answer was not the JSON asked for.
     * @throws OutOfTime           No time left to start the request.
     */
    public function infer(string $title, string $text, array $fields, array $known, TimeBudget $budget): array
    {
        $timeout = $budget->nextTimeout();

        if (null === $timeout) {
            throw new OutOfTime();
        }

        $reply = $this->client->chat(
            [
                ['role' => 'system', 'content' => self::PROMPT],
                ['role' => 'user', 'content' => self::userMessage($title, $text, $fields, $known)],
            ],
            $timeout,
            self::MAX_TOKENS
        );

        $decoded = self::parseReply($reply);

        if (null === $decoded) {
            throw new TranslationRejected('bad_reply');
        }

        return self::validate($decoded, $fields, $known);
    }

    /**
     * @param list<string>          $fields
     * @param array<string, string> $known
     */
    public static function userMessage(string $title, string $text, array $fields, array $known): string
    {
        $lines = [
            'Today: ' . LocalTime::now()->format('Y-m-d'),
            'Title: ' . $title,
        ];

        foreach ($known as $field => $value) {
            $lines[] = 'Known ' . $field . ': ' . $value;
        }

        $lines[] = 'Find: ' . implode(', ', $fields);
        $lines[] = 'Description:';
        $lines[] = $text;

        return implode("\n", $lines);
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

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    /**
     * Keeps only the asked fields whose values pass the check: dates in the
     * form YYYY-MM-DDTHH:MM near today, an end after the start and at most
     * MAX_DAYS later, text fields trimmed, one line, length-capped, and a
     * price that names an amount (or says it is free).
     *
     * @param array<string, mixed>  $decoded
     * @param list<string>          $fields
     * @param array<string, string> $known
     *
     * @return array<string, string>
     */
    public static function validate(array $decoded, array $fields, array $known): array
    {
        $result = [];

        foreach ($fields as $field) {
            $value = $decoded[$field] ?? null;

            if (! is_string($value)) {
                continue;
            }

            $checked = match ($field) {
                'starts_at', 'ends_at' => self::dateTime($value),
                'price' => self::price($value),
                'venue_name', 'venue_address' => self::text($value, self::MAX_LENGTH[$field]),
                default => null,
            };

            if (null !== $checked) {
                $result[$field] = $checked;
            }
        }

        if (isset($result['ends_at'])) {
            $start = LocalTime::parse($result['starts_at'] ?? ($known['starts_at'] ?? ''));
            $end = LocalTime::parse($result['ends_at']);

            if (
                null === $start
                || null === $end
                || $end <= $start
                || $end > $start->modify('+' . self::MAX_DAYS . ' days')
            ) {
                unset($result['ends_at']);
            }
        }

        return $result;
    }

    /**
     * "2026-11-14T18:30" (a space or ":00" seconds are accepted) to the
     * stored form, or null. The year must be within a year before and two
     * years after today.
     */
    private static function dateTime(string $value): ?string
    {
        $pattern = '/^(?<y>\d{4})-(?<m>\d{2})-(?<d>\d{2})[T ](?<h>\d{2}):(?<i>\d{2})(?::00)?$/';

        if (1 !== preg_match($pattern, trim($value), $m)) {
            return null;
        }

        $year = (int) $m['y'];
        $thisYear = (int) LocalTime::now()->format('Y');

        if (
            ! checkdate((int) $m['m'], (int) $m['d'], $year)
            || (int) $m['h'] > 23
            || (int) $m['i'] > 59
            || $year < $thisYear - 1
            || $year > $thisYear + 2
        ) {
            return null;
        }

        $moment = LocalTime::fromDate($year, (int) $m['m'], (int) $m['d']);

        if (! $moment instanceof DateTimeImmutable) {
            return null;
        }

        return LocalTime::store($moment->setTime((int) $m['h'], (int) $m['i']));
    }

    private static function text(string $value, int $maxLength): ?string
    {
        $value = sanitize_text_field($value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ('' === $value || 1 === preg_match(self::NOT_A_VALUE, $value) || str_contains($value, '://')) {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            $value = rtrim(mb_substr($value, 0, $maxLength));
        }

        return $value;
    }

    private static function price(string $value): ?string
    {
        $value = self::text($value, self::MAX_LENGTH['price']);

        if (null === $value || 1 !== preg_match('/\d|free|ilmai/iu', $value)) {
            return null;
        }

        return $value;
    }
}
