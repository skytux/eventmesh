<?php

declare(strict_types=1);

namespace EventMesh\Translation;

/**
 * The two jobs the model does: name the language an event is written in, and
 * translate a piece of text. Only the event's title and text are sent.
 */
final class Translator
{
    /** How long a translated piece is remembered, so a run cut short by its time budget does not redo it. */
    private const CACHE_SECONDS = 86400;

    public const CACHE_PREFIX = 'eventmesh_trc_';

    /** Detection only needs a sample of the text. */
    private const DETECT_SAMPLE = 1500;

    private const LANGUAGE_NAMES = [
        'ar' => 'Arabic',
        'cs' => 'Czech',
        'da' => 'Danish',
        'de' => 'German',
        'el' => 'Greek',
        'en' => 'English',
        'es' => 'Spanish',
        'et' => 'Estonian',
        'fa' => 'Persian',
        'fi' => 'Finnish',
        'fr' => 'French',
        'hu' => 'Hungarian',
        'it' => 'Italian',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'lt' => 'Lithuanian',
        'lv' => 'Latvian',
        'nb' => 'Norwegian',
        'nl' => 'Dutch',
        'no' => 'Norwegian',
        'pl' => 'Polish',
        'pt' => 'Portuguese',
        'ro' => 'Romanian',
        'ru' => 'Russian',
        'se' => 'Northern Sami',
        'sk' => 'Slovak',
        'sv' => 'Swedish',
        'tr' => 'Turkish',
        'uk' => 'Ukrainian',
        'zh' => 'Chinese',
    ];

    public function __construct(
        private readonly LlmClient $client
    ) {
    }

    public function model(): string
    {
        return $this->client->model();
    }

    /**
     * @throws LlmException       The request failed.
     * @throws TranslationRejected The answer was not a two-letter code.
     * @throws OutOfTime           No time left to start the request.
     */
    public function detectLanguage(string $title, string $content, TimeBudget $budget): string
    {
        $timeout = $budget->nextTimeout();

        if (null === $timeout) {
            throw new OutOfTime();
        }

        $text = trim($title . "\n\n" . trim(wp_strip_all_tags($content)));

        if (strlen($text) > self::DETECT_SAMPLE) {
            $text = mb_strcut($text, 0, self::DETECT_SAMPLE, 'UTF-8');
        }

        $reply = $this->client->chat(
            [
                [
                    'role' => 'system',
                    'content' => 'Identify the main language of the text the user sends. '
                        . 'Reply with strict JSON only, exactly in the form {"lang":"fi"}, '
                        . 'using the two-letter ISO 639-1 code. No other text.',
                ],
                ['role' => 'user', 'content' => $text],
            ],
            $timeout,
            20
        );

        $lang = self::parseLanguage($reply);

        if (null === $lang) {
            throw new TranslationRejected('bad_language');
        }

        return $lang;
    }

    /**
     * Reads {"lang":"xx"} from the model's reply, tolerating a code fence or
     * stray text around the JSON. Null unless it is a two-letter code.
     */
    public static function parseLanguage(string $reply): ?string
    {
        if (1 !== preg_match('/\{[^{}]*\}/', $reply, $match)) {
            return null;
        }

        $decoded = json_decode($match[0], true);

        if (! is_array($decoded) || ! isset($decoded['lang']) || ! is_string($decoded['lang'])) {
            return null;
        }

        $lang = strtolower(trim($decoded['lang']));

        return 1 === preg_match('/^[a-z]{2}$/', $lang) ? $lang : null;
    }

    /**
     * Translates text piece by piece; every piece must pass the safety check.
     *
     * @throws LlmException
     * @throws TranslationRejected
     * @throws OutOfTime
     */
    public function translate(string $text, string $from, string $to, TimeBudget $budget): string
    {
        $result = '';

        foreach (ContentChunker::split($text) as $chunk) {
            $result .= $this->translateChunk($chunk, $from, $to, $budget);
        }

        return $result;
    }

    private function translateChunk(string $chunk, string $from, string $to, TimeBudget $budget): string
    {
        $core = trim($chunk);

        if ('' === $core) {
            return $chunk;
        }

        $cacheKey = self::CACHE_PREFIX . md5($from . '|' . $to . '|' . $this->client->model() . '|' . $core);
        $cached = get_transient($cacheKey);

        if (is_string($cached) && '' !== $cached) {
            return $this->rewrap($chunk, $core, $cached);
        }

        $timeout = $budget->nextTimeout();

        if (null === $timeout) {
            throw new OutOfTime();
        }

        $reply = $this->client->chat(
            [
                ['role' => 'system', 'content' => self::prompt($from, $to)],
                ['role' => 'user', 'content' => $core],
            ],
            $timeout
        );

        $translated = trim(self::stripCodeFence($reply, $core));
        $reason = TranslationGuard::check($core, $translated);

        if (null !== $reason) {
            throw new TranslationRejected($reason);
        }

        set_transient($cacheKey, $translated, self::CACHE_SECONDS);

        return $this->rewrap($chunk, $core, $translated);
    }

    public static function prompt(string $from, string $to): string
    {
        $fromName = self::languageName($from);
        $toName = self::languageName($to);

        return "Translate the user's text from {$fromName} to {$toName}. "
            . "Write natural, plain {$toName}. "
            . 'Keep all HTML tags, their attributes and block comments (<!-- wp:... -->) exactly as they are. '
            . 'Keep names of people, DJs and venues, numbers, prices, times, dates, codes, URLs '
            . 'and email addresses exactly as written. '
            . 'Keep the words CANCELED and CANCELLED unchanged. '
            . 'Output only the translation, with no notes or explanations.';
    }

    public static function languageName(string $code): string
    {
        return self::LANGUAGE_NAMES[$code] ?? sprintf('the language with ISO 639-1 code "%s"', $code);
    }

    /**
     * Models sometimes wrap their answer in a Markdown code fence. Removed
     * unless the original itself was fenced.
     */
    private static function stripCodeFence(string $reply, string $original): string
    {
        $reply = trim($reply);

        if (str_starts_with($original, '```')) {
            return $reply;
        }

        if (1 === preg_match('/^```[a-z]*\n(.*)\n```$/s', $reply, $match)) {
            return $match[1];
        }

        return $reply;
    }

    /**
     * Puts back the whitespace the piece had around it, so the joined pieces
     * keep the original's spacing.
     */
    private function rewrap(string $chunk, string $core, string $translated): string
    {
        $start = strpos($chunk, $core);
        $leading = false === $start ? '' : substr($chunk, 0, $start);
        $trailing = false === $start ? '' : substr($chunk, $start + strlen($core));

        return $leading . $translated . $trailing;
    }
}
