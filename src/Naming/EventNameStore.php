<?php

declare(strict_types=1);

namespace EventMesh\Naming;

use EventMesh\Translation\TranslationStore;

/**
 * Where an event's clean name lives: post meta on the event itself.
 *
 *   _eventmesh_name        clean name from the original title ("Ecstatic Dance")
 *   _eventmesh_name_fi     clean name from the Finnish title, '' when there is none
 *   _eventmesh_dj_names    list of DJ names found in the original title
 *   _eventmesh_name_hash   the titles they were made from: "md5(title):md5(Finnish title)"
 *   _eventmesh_name_fail   failed attempts: {hash, count, kind, time}
 *
 * The hash holds the original title's fingerprint first, so a reader can tell
 * whether the stored name still belongs to the event's current title.
 *
 * @phpstan-type EventName array{name: string, name_fi: string, djs: list<string>, hash: string}
 */
final class EventNameStore
{
    public const META_NAME = '_eventmesh_name';
    public const META_NAME_FI = '_eventmesh_name_fi';
    public const META_DJ_NAMES = '_eventmesh_dj_names';
    public const META_HASH = '_eventmesh_name_hash';
    public const META_FAIL = '_eventmesh_name_fail';

    /**
     * Every key above, for cleanup.
     */
    public const META_KEYS = [
        self::META_NAME,
        self::META_NAME_FI,
        self::META_DJ_NAMES,
        self::META_HASH,
        self::META_FAIL,
    ];

    /**
     * Fingerprint of the titles a name is made from. Any change to the
     * original title or to the Finnish title changes it.
     */
    public static function sourceHash(string $title, string $finnishTitle): string
    {
        return md5($title) . ':' . md5($finnishTitle);
    }

    /**
     * The event's Finnish title to name from: its Finnish translation when
     * that was made (or saved by a person) against the current original, ''
     * when there is none or the original itself is Finnish.
     */
    public static function finnishTitle(\WP_Post $post): string
    {
        if ('fi' === TranslationStore::originalLanguage($post->ID)) {
            return '';
        }

        $translation = TranslationStore::read($post->ID, 'fi');

        if ('' === $translation['title'] || $translation['hash'] !== TranslationStore::originalHash($post)) {
            return '';
        }

        return $translation['title'];
    }

    /**
     * @return EventName
     */
    public static function read(int $postId): array
    {
        $djs = get_post_meta($postId, self::META_DJ_NAMES, true);

        return [
            'name' => (string) get_post_meta($postId, self::META_NAME, true),
            'name_fi' => (string) get_post_meta($postId, self::META_NAME_FI, true),
            'djs' => is_array($djs) ? array_values(array_map('strval', $djs)) : [],
            'hash' => (string) get_post_meta($postId, self::META_HASH, true),
        ];
    }

    /**
     * The stored name when it was made from the event's current title, else
     * null (nothing written yet, or the title has changed since).
     *
     * @return EventName|null
     */
    public static function current(\WP_Post $post): ?array
    {
        $stored = self::read($post->ID);

        if ('' === $stored['name'] || ! str_starts_with($stored['hash'], md5((string) $post->post_title) . ':')) {
            return null;
        }

        return $stored;
    }

    /**
     * @param list<string> $djs
     */
    public static function write(int $postId, string $name, string $nameFi, array $djs, string $hash): void
    {
        update_post_meta($postId, self::META_NAME, wp_slash($name));
        update_post_meta($postId, self::META_NAME_FI, wp_slash($nameFi));
        update_post_meta($postId, self::META_DJ_NAMES, wp_slash(array_values($djs)));
        update_post_meta($postId, self::META_HASH, $hash);
        delete_post_meta($postId, self::META_FAIL);
    }
}
