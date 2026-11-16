<?php

declare(strict_types=1);

namespace EventMesh\Description;

use EventMesh\Content\EventPostType;

/**
 * The descriptions for other plugins (Talkoola), through filters only, so
 * they need no EventMesh class:
 *
 * - `eventmesh/event_description` ('', $postId, $lang): the text in that
 *   language, '' when there is none (yet).
 * - `eventmesh/event_descriptions` (null, $postId): ['languages' => [...],
 *   'texts' => [lang => ['text', 'status' (auto|edited), 'failed']],
 *   'writing' => bool], null for a post that is not an event.
 * - `eventmesh/save_event_description` (false, $postId, [lang => text]):
 *   saves what a person typed (DescriptionStore::saveTyped()); true when
 *   saved.
 * - `eventmesh/regenerate_event_description` (false, $postId): asks the
 *   model for a new Finnish text; true when asked.
 */
final class DescriptionFilters
{
    public const TEXT = 'eventmesh/event_description';
    public const STATE = 'eventmesh/event_descriptions';
    public const SAVE = 'eventmesh/save_event_description';
    public const REGENERATE = 'eventmesh/regenerate_event_description';

    public function __construct(
        private readonly DescriptionQueue $queue
    ) {
    }

    public function boot(): void
    {
        add_filter(self::TEXT, [$this, 'text'], 10, 3);
        add_filter(self::STATE, [$this, 'state'], 10, 2);
        add_filter(self::SAVE, [$this, 'save'], 10, 3);
        add_filter(self::REGENERATE, [$this, 'regenerate'], 10, 2);
    }

    public function text(mixed $value, mixed $postId = 0, mixed $lang = ''): mixed
    {
        $postId = (int) $postId;

        if (! self::isEvent($postId) || ! is_string($lang) || '' === $lang) {
            return $value;
        }

        $text = DescriptionStore::text($postId, strtolower($lang));

        return '' !== $text ? $text : $value;
    }

    public function state(mixed $value, mixed $postId = 0): mixed
    {
        $postId = (int) $postId;

        if (! self::isEvent($postId)) {
            return $value;
        }

        return [
            'languages' => DescriptionStore::languages(),
            'texts' => DescriptionStore::all($postId),
            'writing' => in_array($postId, $this->queue->queue(), true),
        ];
    }

    public function save(mixed $value, mixed $postId = 0, mixed $texts = []): mixed
    {
        $postId = (int) $postId;

        if (! self::isEvent($postId) || ! is_array($texts)) {
            return $value;
        }

        $typed = [];

        foreach ($texts as $lang => $text) {
            if (is_string($lang) && is_string($text)) {
                $typed[strtolower($lang)] = $text;
            }
        }

        DescriptionStore::saveTyped($postId, $typed);
        $this->queueIfNeeded($postId);

        return true;
    }

    public function regenerate(mixed $value, mixed $postId = 0): mixed
    {
        $postId = (int) $postId;

        if (! self::isEvent($postId)) {
            return $value;
        }

        DescriptionStore::askRegenerate($postId);
        $this->queueIfNeeded($postId);

        return true;
    }

    public function queueIfNeeded(int $postId): void
    {
        if (DescriptionStore::needsWork($postId)) {
            $this->queue->enqueue([$postId]);
        }
    }

    private static function isEvent(int $postId): bool
    {
        $post = $postId > 0 ? get_post($postId) : null;

        return $post instanceof \WP_Post && EventPostType::NAME === $post->post_type;
    }
}
