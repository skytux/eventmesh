<?php

declare(strict_types=1);

namespace EventMesh\Description;

use EventMesh\Djs\EventDjsFilter;
use EventMesh\Support\EventMeta;
use EventMesh\Support\LocalTime;
use EventMesh\Translation\LlmClient;
use EventMesh\Translation\LlmException;
use EventMesh\Translation\OutOfTime;
use EventMesh\Translation\TimeBudget;
use EventMesh\Translation\TranslationRejected;
use EventMesh\Translation\TranslationStore;
use EventMesh\Translation\Translator;

/**
 * Has the model write an event's short description in Finnish, and
 * translates it.
 *
 * The event's own text often holds what must never reach a newsletter: the
 * venue's door code, a phone number, an email address. Those lines are cut
 * out before anything is sent (redact()), and every text the model gives
 * back is checked again before it is kept (check()): no links, no words for
 * a code or a password, and no number of three or more digits that the
 * redacted input does not have. A translation must pass the same check.
 */
final class DescriptionWriter
{
    /** The model answers with a short JSON object. */
    private const MAX_TOKENS = 700;

    /** The longest text the model may give back. */
    public const MAX_TEXT = 600;

    /** How much of each description and DJ text is sent. */
    private const MAX_DESCRIPTION = 4000;
    private const MAX_DJ_DESCRIPTION = 1200;
    private const MAX_WORDS = 2000;

    /** The site's words and style, from the plugin that sends the texts (EventCrew). */
    public const WORDS_FILTER = 'eventmesh/description_words';

    /** A line with one of these is left out of what the model is sent, and a text with one is not kept. */
    public const PRIVATE_PATTERN = '~code|koodi|kod(en|er|erna)?\b|salasana|password|passcode|lösenord|\bpin\b~iu';

    /** A phone number: a run of digits with spaces or dashes, eight or more characters long. */
    private const PHONE_PATTERN = '~\+?\d[\d \-]{6,}\d~u';

    private const LINK_PATTERN = '~https?://|www\.|\S+@\S+\.\S+~iu';

    public const PROMPT = 'You write the short description of one event of a dance event. '
        . 'It is shown in the email newsletter, in emails and in channel posts about the event. '
        . 'Write 2 to 3 sentences in Finnish that invite people to come and dance at this event. '
        . 'Be warm and concrete. '
        . 'Use the descriptive words of the DJ descriptions and the facts about the event. '
        . 'Use only facts that are in the input. '
        . 'Do not invent times, prices, places, names or anything else. '
        . 'A DJ name is a person, never a place. Name a place only when the input says it is the place. '
        . 'Never write door codes, passwords, phone numbers or email addresses. '
        . 'Do not write hashtags or links. '
        . 'Do not use emoji, unless the site\'s words and style below use them. '
        . 'Keep the text under 450 characters.';

    public function __construct(
        private readonly LlmClient $client,
        private readonly Translator $translator
    ) {
    }

    public function model(): string
    {
        return $this->client->model();
    }

    /**
     * The Finnish text for the event.
     *
     * @param list<string> $earlier Texts written for other events, not to be repeated.
     *
     * @throws LlmException
     * @throws TranslationRejected
     * @throws OutOfTime
     */
    public function write(\WP_Post $post, array $earlier, TimeBudget $budget): string
    {
        $timeout = $budget->nextTimeout();

        if (null === $timeout) {
            throw new OutOfTime();
        }

        $input = self::input($post);
        $reply = $this->client->chat(
            [
                ['role' => 'system', 'content' => self::system($earlier)],
                ['role' => 'user', 'content' => $input],
            ],
            $timeout,
            self::MAX_TOKENS
        );

        $text = self::parse($reply);

        if (null === $text) {
            throw new TranslationRejected('bad_reply');
        }

        $reason = self::check($text, $input);

        if (null !== $reason) {
            throw new TranslationRejected($reason);
        }

        return $text;
    }

    /**
     * The Finnish text in another language.
     *
     * @throws LlmException
     * @throws TranslationRejected
     * @throws OutOfTime
     */
    public function translate(string $finnish, string $lang, TimeBudget $budget): string
    {
        $text = DescriptionStore::clean(
            $this->translator->translate($finnish, DescriptionStore::WRITES_IN, $lang, $budget)
        );
        $reason = self::check($text, $finnish);

        if (null !== $reason) {
            throw new TranslationRejected($reason);
        }

        return $text;
    }

    /**
     * @param list<string> $earlier
     */
    public static function system(array $earlier): string
    {
        $parts = [self::PROMPT];
        $words = apply_filters(self::WORDS_FILTER, '');
        $words = is_string($words) ? self::cut(trim($words), self::MAX_WORDS) : '';

        if ('' !== $words) {
            $parts[] = "The site's words and style. Prefer these words and phrases where they fit:\n" . $words;
        }

        $earlier = array_values(array_filter(array_map('trim', $earlier)));

        if ([] !== $earlier) {
            $parts[] = 'Texts written for other events. Do not reuse their openings, phrases or sentence structure:'
                . "\n- " . implode("\n- ", $earlier);
        }

        $parts[] = 'Answer with JSON only, with no other text: {"text": "the Finnish text"}';

        return implode("\n\n", $parts);
    }

    /**
     * What the model is told about the event, with the private lines cut out.
     */
    public static function input(\WP_Post $post): string
    {
        $postId = (int) $post->ID;
        $title = self::plain((string) $post->post_title);
        $finnish = TranslationStore::read($postId, DescriptionStore::WRITES_IN);
        $finnishTitle = self::plain((string) ($finnish['title'] ?? ''));

        $lines = ['Event: ' . $title];

        if ('' !== $finnishTitle && $finnishTitle !== $title) {
            $lines[] = 'Event in Finnish: ' . $finnishTitle;
        }

        $when = self::when($postId);

        if ('' !== $when) {
            $lines[] = 'Date: ' . $when;
        }

        $venue = self::redact(EventMeta::resolve($postId, 'venue_name'));

        if ('' !== $venue) {
            $lines[] = 'Place: ' . $venue;
        }

        $descriptions = [
            'Description' => (string) $post->post_content,
            'Description (Finnish)' => (string) ($finnish['content'] ?? ''),
        ];

        foreach ($descriptions as $label => $html) {
            $text = self::cut(self::redact($html), self::MAX_DESCRIPTION);

            if ('' !== $text) {
                $lines[] = '';
                $lines[] = $label . ":\n" . $text;
            }
        }

        $djs = self::djs($postId);

        if ([] !== $djs) {
            $lines[] = '';
            $lines[] = 'DJs (people who play the music, not places):';

            foreach ($djs as $dj) {
                $lines[] = '- ' . $dj['name'] . ('' === $dj['description'] ? '' : ': ' . $dj['description']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The text without its markup, links, email addresses, and without
     * every line that names a code or a password or holds a phone number.
     */
    public static function redact(string $html): string
    {
        $text = (string) preg_replace('~<\s*(br|/p|/div|/li|/h[1-6])[^>]*>~i', "\n", $html);
        $text = html_entity_decode(wp_strip_all_tags($text, false), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $kept = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $line));

            if (
                '' === $line
                || 1 === preg_match(self::PRIVATE_PATTERN, $line)
                || 1 === preg_match(self::PHONE_PATTERN, $line)
            ) {
                continue;
            }

            $line = trim((string) preg_replace(['~\S*(https?://|www\.|@)\S*~iu', '/ {2,}/'], ['', ' '], $line));

            if ('' !== $line) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    /**
     * Why a text cannot be kept, or null when it can: empty, too_long,
     * links, private (a word for a code or a password), or numbers (a
     * number of three or more digits $source does not have).
     */
    public static function check(string $text, string $source): ?string
    {
        $text = trim($text);
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

        if ('' === $text) {
            return 'empty';
        }

        if ($length > self::MAX_TEXT) {
            return 'too_long';
        }

        if (1 === preg_match(self::LINK_PATTERN, $text)) {
            return 'links';
        }

        if (1 === preg_match(self::PRIVATE_PATTERN, $text)) {
            return 'private';
        }

        preg_match_all('/\d{3,}/', $text, $numbers);
        preg_match_all('/\d{3,}/', $source, $known);

        foreach ($numbers[0] as $number) {
            if (! in_array($number, $known[0], true)) {
                return 'numbers';
            }
        }

        return null;
    }

    /**
     * Reads {"text": "…"} from the model's reply, tolerating a code fence or
     * stray text around it.
     */
    public static function parse(string $reply): ?string
    {
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');

        if (false === $start || false === $end || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($reply, $start, $end - $start + 1), true);

        if (! is_array($decoded) || ! is_string($decoded['text'] ?? null)) {
            return null;
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", $decoded['text']));

        return '' === $text ? null : $text;
    }

    private static function when(int $postId): string
    {
        $starts = LocalTime::parse(EventMeta::resolve($postId, 'starts_at'));

        if (null === $starts) {
            return '';
        }

        $when = $starts->format('l j.n.Y H:i');
        $ends = LocalTime::parse(EventMeta::resolve($postId, 'ends_at'));

        if (null !== $ends && $ends > $starts) {
            $when .= '–' . $ends->format('H:i');
        }

        return $when;
    }

    /**
     * @return list<array{name: string, description: string}>
     */
    private static function djs(int $postId): array
    {
        $listed = apply_filters(EventDjsFilter::FILTER, [], $postId);
        $djs = [];

        foreach (is_array($listed) ? $listed : [] as $dj) {
            if (! is_array($dj) || ! is_string($dj['name'] ?? null) || '' === trim($dj['name'])) {
                continue;
            }

            $description = is_string($dj['description'] ?? null) ? self::redact($dj['description']) : '';
            $djs[] = [
                'name' => trim($dj['name']),
                'description' => self::cut(
                    (string) preg_replace('/\s+/u', ' ', $description),
                    self::MAX_DJ_DESCRIPTION
                ),
            ];
        }

        return $djs;
    }

    private static function plain(string $text): string
    {
        return trim(html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function cut(string $text, int $max): string
    {
        if (function_exists('mb_strlen') ? mb_strlen($text) <= $max : strlen($text) <= $max) {
            return $text;
        }

        return rtrim(function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max)) . '…';
    }
}
