<?php

declare(strict_types=1);

namespace EventMesh\Inference;

/**
 * Which event fields the language model filled in, and from which text.
 * Post meta on the event itself:
 *
 *   _eventmesh_inferred_fields   {field: text hash} for every field whose
 *                                current value the model wrote
 *   _eventmesh_infer_asked       {hash, fields}: the text and the fields of
 *                                the last answered request
 *   _eventmesh_infer_fail        failed attempts: {hash, count, kind, time}
 *
 * A field's value lives where the sync writes it (`_eventmesh_{field}`), so
 * every reader sees an inferred value like a scraped one. The sync replaces an
 * inferred value as soon as the source itself gives one (see forget()), and
 * keeps it while the source still gives none.
 */
final class InferredFields
{
    public const META_FIELDS = '_eventmesh_inferred_fields';
    public const META_ASKED = '_eventmesh_infer_asked';
    public const META_FAIL = '_eventmesh_infer_fail';

    /**
     * The event fields the model may fill in, in the order they are asked.
     * venue_address is never written by a parser, only by the model.
     */
    public const FIELDS = ['starts_at', 'ends_at', 'venue_name', 'venue_address', 'price'];

    /**
     * Every key above, for cleanup.
     */
    public const META_KEYS = [
        self::META_FIELDS,
        self::META_ASKED,
        self::META_FAIL,
    ];

    public static function metaKey(string $field): string
    {
        return '_eventmesh_' . $field;
    }

    /**
     * Fingerprint of the source text the model reads: the source title and
     * the source description, as the sync last stored them.
     */
    public static function textHash(string $title, string $content): string
    {
        return md5($title . "\n" . $content);
    }

    public static function sourceTitle(int $postId): string
    {
        return (string) get_post_meta($postId, '_eventmesh_source_title', true);
    }

    public static function sourceContent(int $postId): string
    {
        return (string) get_post_meta($postId, '_eventmesh_source_content', true);
    }

    public static function hashFor(int $postId): string
    {
        return self::textHash(self::sourceTitle($postId), self::sourceContent($postId));
    }

    /**
     * @return array<string, string> field => hash of the text it was inferred from
     */
    public static function inferred(int $postId): array
    {
        $value = get_post_meta($postId, self::META_FIELDS, true);

        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $field => $hash) {
            if (in_array($field, self::FIELDS, true)) {
                $result[(string) $field] = (string) $hash;
            }
        }

        return $result;
    }

    public static function isInferred(int $postId, string $field): bool
    {
        return array_key_exists($field, self::inferred($postId));
    }

    /**
     * Called by the sync after it wrote a value the source gave: the field
     * no longer holds an inferred value.
     */
    public static function forget(int $postId, string $field): void
    {
        $inferred = self::inferred($postId);

        if (! array_key_exists($field, $inferred)) {
            return;
        }

        unset($inferred[$field]);

        if ([] === $inferred) {
            delete_post_meta($postId, self::META_FIELDS);

            return;
        }

        update_post_meta($postId, self::META_FIELDS, $inferred);
    }

    /**
     * The fields to ask the model for: every field the source left empty,
     * and every inferred field whose text has changed since. A field already
     * asked about for this exact text is not asked again, whether or not the
     * model found it then.
     *
     * @return list<string>
     */
    public static function fieldsToAsk(int $postId, string $hash): array
    {
        $inferred = self::inferred($postId);
        $asked = self::asked($postId);
        $alreadyAsked = $asked['hash'] === $hash ? $asked['fields'] : [];
        $fields = [];

        foreach (self::FIELDS as $field) {
            $value = trim((string) get_post_meta($postId, self::metaKey($field), true));
            $stale = array_key_exists($field, $inferred) && $inferred[$field] !== $hash;

            if (('' === $value || $stale) && ! in_array($field, $alreadyAsked, true)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * True when the field may be written by the model: it is empty, or it
     * already holds an inferred value. A value the source gave never is.
     */
    public static function mayFill(int $postId, string $field): bool
    {
        if (self::isInferred($postId, $field)) {
            return true;
        }

        return '' === trim((string) get_post_meta($postId, self::metaKey($field), true));
    }

    /**
     * @return array{hash: string, fields: list<string>}
     */
    public static function asked(int $postId): array
    {
        $value = get_post_meta($postId, self::META_ASKED, true);

        if (! is_array($value)) {
            return ['hash' => '', 'fields' => []];
        }

        $fields = is_array($value['fields'] ?? null) ? array_values(array_map('strval', $value['fields'])) : [];

        return ['hash' => (string) ($value['hash'] ?? ''), 'fields' => $fields];
    }

    /**
     * Stores the model's checked values for the fields that may still be
     * filled, and records the request as answered.
     *
     * @param array<string, string> $values field => checked value
     * @param list<string>          $asked  the fields the request asked for
     *
     * @return list<string> the fields written
     */
    public static function write(int $postId, array $values, array $asked, string $hash): array
    {
        $inferred = self::inferred($postId);
        $written = [];

        foreach ($values as $field => $value) {
            if (! in_array($field, $asked, true) || ! self::mayFill($postId, $field)) {
                continue;
            }

            update_post_meta($postId, self::metaKey($field), wp_slash($value));
            $inferred[$field] = $hash;
            $written[] = $field;

            if ('starts_at' === $field) {
                // An inferred start is always a full date with its year.
                update_post_meta($postId, '_eventmesh_starts_at_year_known', '1');
            }
        }

        if ([] !== $inferred) {
            update_post_meta($postId, self::META_FIELDS, $inferred);
        }

        $previous = self::asked($postId);
        $fields = $previous['hash'] === $hash
            ? array_values(array_unique(array_merge($previous['fields'], $asked)))
            : $asked;

        update_post_meta($postId, self::META_ASKED, ['hash' => $hash, 'fields' => $fields]);
        delete_post_meta($postId, self::META_FAIL);

        return $written;
    }
}
