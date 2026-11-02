<?php

declare(strict_types=1);

namespace EventMesh\Translation;

/**
 * Where translations live: post meta on the event itself, one set of fields
 * per target language. The event's own title and content stay the original.
 *
 *   _eventmesh_original_lang          detected language of the original ('fi')
 *   _eventmesh_original_lang_hash     original hash the detection was made from
 *   _eventmesh_original_lang_fail     failed detections: {hash, count, kind, time}
 *   _eventmesh_tr_{lang}_title        translated title
 *   _eventmesh_tr_{lang}_content      translated content
 *   _eventmesh_tr_{lang}_excerpt      excerpt made from the translated content
 *   _eventmesh_tr_{lang}_hash         original hash the translation was made from
 *   _eventmesh_tr_{lang}_status       auto | edited
 *   _eventmesh_tr_{lang}_model        model that made it
 *   _eventmesh_tr_{lang}_time         when it was saved (Unix time)
 *   _eventmesh_tr_{lang}_fail         failed attempts: {hash, count, kind, time}
 *
 * @phpstan-type Translation array{
 *     title: string, content: string, excerpt: string, hash: string,
 *     status: string, model: string, time: int
 * }
 */
final class TranslationStore
{
    public const META_ORIGINAL_LANG = '_eventmesh_original_lang';
    public const META_ORIGINAL_LANG_HASH = '_eventmesh_original_lang_hash';
    public const META_ORIGINAL_LANG_FAIL = '_eventmesh_original_lang_fail';
    public const META_PREFIX = '_eventmesh_tr_';

    public const STATUS_AUTO = 'auto';
    public const STATUS_EDITED = 'edited';

    /**
     * Failed attempts for the same original text before giving up on it. A
     * change to the original starts the count again.
     */
    public const MAX_ATTEMPTS = 3;

    private const FIELDS = ['title', 'content', 'excerpt', 'hash', 'status', 'model', 'time'];

    public static function metaKey(string $lang, string $field): string
    {
        return self::META_PREFIX . $lang . '_' . $field;
    }

    /**
     * Fingerprint of the original text a translation is made from. Any
     * change to the event's title or content changes it.
     */
    public static function hashOf(string $title, string $content): string
    {
        return md5($title . "\0" . $content);
    }

    public static function originalHash(\WP_Post $post): string
    {
        return self::hashOf((string) $post->post_title, (string) $post->post_content);
    }

    public static function originalLanguage(int $postId): string
    {
        return (string) get_post_meta($postId, self::META_ORIGINAL_LANG, true);
    }

    /**
     * @return Translation
     */
    public static function read(int $postId, string $lang): array
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            $values[$field] = (string) get_post_meta($postId, self::metaKey($lang, $field), true);
        }

        $values['time'] = (int) $values['time'];

        return $values;
    }

    /**
     * @param Translation $translation
     */
    public static function write(int $postId, string $lang, array $translation): void
    {
        foreach (self::FIELDS as $field) {
            update_post_meta($postId, self::metaKey($lang, $field), wp_slash((string) ($translation[$field] ?? '')));
        }
    }

    /**
     * What to do about one target language of one event.
     *
     * @param array{hash: string, status: string, title: string, content: string} $translation
     * @param array{hash?: string, count?: int}|null $failure
     *
     * @return string 'edited' (a person's version, never overwritten),
     *                'current' (made from the current original),
     *                'gave_up' (failed MAX_ATTEMPTS times on this original),
     *                or 'translate'.
     */
    public static function decide(array $translation, ?array $failure, string $originalHash): string
    {
        if (self::STATUS_EDITED === $translation['status']) {
            return 'edited';
        }

        if (
            $originalHash === $translation['hash']
            && ('' !== $translation['title'] || '' !== $translation['content'])
        ) {
            return 'current';
        }

        if (self::attemptsFor($failure, $originalHash) >= self::MAX_ATTEMPTS) {
            return 'gave_up';
        }

        return 'translate';
    }

    /**
     * @param array{hash?: string, count?: int}|null $failure
     */
    public static function attemptsFor(?array $failure, string $originalHash): int
    {
        if (null === $failure || ($failure['hash'] ?? '') !== $originalHash) {
            return 0;
        }

        return (int) ($failure['count'] ?? 0);
    }

    /**
     * @return array{hash: string, count: int, kind: string, time: int}|null
     */
    public static function failure(int $postId, string $metaKey): ?array
    {
        $value = get_post_meta($postId, $metaKey, true);

        if (! is_array($value)) {
            return null;
        }

        return [
            'hash' => (string) ($value['hash'] ?? ''),
            'count' => (int) ($value['count'] ?? 0),
            'kind' => (string) ($value['kind'] ?? ''),
            'time' => (int) ($value['time'] ?? 0),
        ];
    }

    /**
     * Adds one failed attempt for this original; a different original starts
     * the count again at one.
     */
    public static function recordFailure(int $postId, string $metaKey, string $originalHash, string $kind): int
    {
        $count = self::attemptsFor(self::failure($postId, $metaKey), $originalHash) + 1;

        update_post_meta(
            $postId,
            $metaKey,
            [
                'hash' => $originalHash,
                'count' => $count,
                'kind' => $kind,
                'time' => time(),
            ]
        );

        return $count;
    }

    public static function failureKey(string $lang): string
    {
        return self::metaKey($lang, 'fail');
    }

    /**
     * "Back to automatic": forgets the edited state and the hash, so the next
     * run translates the current original again.
     */
    public static function resetToAutomatic(int $postId, string $lang): void
    {
        delete_post_meta($postId, self::metaKey($lang, 'status'));
        delete_post_meta($postId, self::metaKey($lang, 'hash'));
        delete_post_meta($postId, self::failureKey($lang));
    }

    /**
     * Languages this event is translated into: the target languages minus
     * its own original language.
     *
     * @param array<int, string> $targets
     *
     * @return array<int, string>
     */
    public static function targetsFor(array $targets, string $originalLang): array
    {
        return array_values(array_filter($targets, static fn (string $lang): bool => $lang !== $originalLang));
    }
}
