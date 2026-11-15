<?php

declare(strict_types=1);

namespace EventMesh\Description;

use EventMesh\Translation\LlmSettings;
use EventMesh\Translation\TranslationStore;

/**
 * An event's short description: two or three sentences that invite people
 * to come and dance, written once by the model in Finnish and translated
 * into the target languages. It is not the event's own text from the
 * source; it is what newsletters, emails and channel posts show for the
 * event, and people can edit it.
 *
 * Stored as post meta per language:
 *
 * - `_eventmesh_desc_{lang}`: the text;
 * - `_eventmesh_desc_{lang}_status`: auto (the model's) or edited (a
 *   person's; never replaced by the model again);
 * - `_eventmesh_desc_{lang}_from`: for a translation, the hash of the
 *   Finnish text it was made from, so a changed Finnish text is translated
 *   again;
 * - `_eventmesh_desc_{lang}_model`, `_eventmesh_desc_{lang}_time`;
 * - `_eventmesh_desc_{lang}_fail`: failed attempts (TranslationStore's
 *   failure shape), at most MAX_ATTEMPTS for the same source;
 * - `_eventmesh_desc_regen`: set when someone asked for a new Finnish text;
 *   the next run writes it and clears the flag.
 */
final class DescriptionStore
{
    /** The model writes in this language; the others are translated from it. */
    public const WRITES_IN = 'fi';

    public const STATUS_AUTO = 'auto';
    public const STATUS_EDITED = 'edited';

    public const META_REGENERATE = '_eventmesh_desc_regen';

    /** The failure hash of the Finnish text: it is written from the event, not from a text. */
    public const WRITE_HASH = 'write';

    /** The longest text kept, typed or written. */
    public const MAX_LENGTH = 1200;

    public static function metaKey(string $lang, string $field = ''): string
    {
        return '_eventmesh_desc_' . $lang . ('' === $field ? '' : '_' . $field);
    }

    /**
     * Finnish first, then the target languages.
     *
     * @return list<string>
     */
    public static function languages(): array
    {
        return array_values(array_unique([self::WRITES_IN, ...LlmSettings::targetLanguages()]));
    }

    public static function text(int $postId, string $lang): string
    {
        return trim((string) get_post_meta($postId, self::metaKey($lang), true));
    }

    public static function status(int $postId, string $lang): string
    {
        return self::STATUS_EDITED === (string) get_post_meta($postId, self::metaKey($lang, 'status'), true)
            ? self::STATUS_EDITED
            : self::STATUS_AUTO;
    }

    public static function isEdited(int $postId, string $lang): bool
    {
        return self::STATUS_EDITED === self::status($postId, $lang);
    }

    public static function hashOf(string $text): string
    {
        return md5(trim($text));
    }

    public static function regenerateAsked(int $postId): bool
    {
        return '' !== (string) get_post_meta($postId, self::META_REGENERATE, true);
    }

    /**
     * Saves a text the model wrote or translated.
     */
    public static function writeAuto(int $postId, string $lang, string $text, string $model, string $from = ''): void
    {
        update_post_meta($postId, self::metaKey($lang), wp_slash($text));
        update_post_meta($postId, self::metaKey($lang, 'status'), self::STATUS_AUTO);
        update_post_meta($postId, self::metaKey($lang, 'model'), $model);
        update_post_meta($postId, self::metaKey($lang, 'time'), time());

        if ('' !== $from) {
            update_post_meta($postId, self::metaKey($lang, 'from'), $from);
        } else {
            delete_post_meta($postId, self::metaKey($lang, 'from'));
        }

        delete_post_meta($postId, self::failureKey($lang));
    }

    /**
     * Saves a text a person wrote. It is never replaced by the model.
     */
    public static function writeEdited(int $postId, string $lang, string $text): void
    {
        update_post_meta($postId, self::metaKey($lang), wp_slash($text));
        update_post_meta($postId, self::metaKey($lang, 'status'), self::STATUS_EDITED);
        update_post_meta($postId, self::metaKey($lang, 'time'), time());
        delete_post_meta($postId, self::metaKey($lang, 'from'));
        delete_post_meta($postId, self::failureKey($lang));
    }

    /**
     * Forgets a language's text, so the next run writes or translates it.
     */
    public static function clear(int $postId, string $lang): void
    {
        foreach (['', 'status', 'from', 'model', 'time', 'fail'] as $field) {
            delete_post_meta($postId, self::metaKey($lang, $field));
        }
    }

    public static function failureKey(string $lang): string
    {
        return self::metaKey($lang, 'fail');
    }

    /**
     * What a run has to do for one language: 'write' (the Finnish text is
     * missing or a new one was asked for), 'translate' (missing, or made
     * from an older Finnish text), 'wait' (no Finnish text to translate
     * from yet), 'gave_up' (failed MAX_ATTEMPTS times on the same source),
     * or 'current' (nothing to do; also every edited text).
     */
    public static function decide(int $postId, string $lang): string
    {
        if (self::WRITES_IN === $lang) {
            if ('' !== self::text($postId, $lang) && ! self::regenerateAsked($postId)) {
                return 'current';
            }

            $failure = TranslationStore::failure($postId, self::failureKey($lang));

            return TranslationStore::attemptsFor($failure, self::WRITE_HASH) >= TranslationStore::MAX_ATTEMPTS
                ? 'gave_up'
                : 'write';
        }

        if (self::isEdited($postId, $lang)) {
            return 'current';
        }

        $finnish = self::text($postId, self::WRITES_IN);

        if ('' === $finnish || self::regenerateAsked($postId)) {
            return 'wait';
        }

        $from = self::hashOf($finnish);

        if (
            '' !== self::text($postId, $lang)
            && $from === (string) get_post_meta($postId, self::metaKey($lang, 'from'), true)
        ) {
            return 'current';
        }

        $failure = TranslationStore::failure($postId, self::failureKey($lang));

        return TranslationStore::attemptsFor($failure, $from) >= TranslationStore::MAX_ATTEMPTS ? 'gave_up' : 'translate';
    }

    /**
     * True when some language still has work a run can do.
     */
    public static function needsWork(int $postId): bool
    {
        foreach (self::languages() as $lang) {
            if (in_array(self::decide($postId, $lang), ['write', 'translate'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole state, for the edit screens.
     *
     * @return array<string, array{text: string, status: string, failed: string}>
     */
    public static function all(int $postId): array
    {
        $state = [];

        foreach (self::languages() as $lang) {
            $failure = TranslationStore::failure($postId, self::failureKey($lang));
            $decision = self::decide($postId, $lang);
            $state[$lang] = [
                'text' => self::text($postId, $lang),
                'status' => self::status($postId, $lang),
                'failed' => 'gave_up' === $decision ? (string) ($failure['kind'] ?? '') : '',
            ];
        }

        return $state;
    }

    /**
     * Saves what a person typed, by language. Only the languages whose text
     * changed are touched:
     *
     * - a changed Finnish text becomes the edited Finnish text, and the
     *   translations nobody edited are made again from it;
     * - a changed translation becomes an edited translation, kept as it is;
     * - an emptied translation goes back to the automatic one;
     * - an emptied Finnish text is written again by the model (translations
     *   nobody edited follow it).
     *
     * @param array<string, string> $texts Language code => text.
     *
     * @return list<string> The languages that changed.
     */
    public static function saveTyped(int $postId, array $texts): array
    {
        $changed = [];

        foreach (self::languages() as $lang) {
            if (! array_key_exists($lang, $texts)) {
                continue;
            }

            $text = self::clean((string) $texts[$lang]);

            if ($text === self::text($postId, $lang)) {
                continue;
            }

            if ('' === $text) {
                self::clear($postId, $lang);
            } else {
                self::writeEdited($postId, $lang, $text);
            }

            $changed[] = $lang;
        }

        return $changed;
    }

    /**
     * Asks for a new Finnish text from the model: the next run writes it
     * and translates it into every language nobody edited.
     */
    public static function askRegenerate(int $postId): void
    {
        update_post_meta($postId, self::META_REGENERATE, '1');
        delete_post_meta($postId, self::metaKey(self::WRITES_IN, 'status'));
        delete_post_meta($postId, self::failureKey(self::WRITES_IN));
    }

    public static function clean(string $text): string
    {
        $text = trim(sanitize_textarea_field($text));

        return function_exists('mb_substr') ? mb_substr($text, 0, self::MAX_LENGTH) : substr($text, 0, self::MAX_LENGTH);
    }
}
