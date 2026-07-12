<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Connectors\Holvi\HolviHtmlParser;
use EventMesh\Content\EventQuery;
use EventMesh\Support\EmbedHtmlSanitizer;
use EventMesh\Support\EventStatus;
use EventMesh\Support\KnownProviders;

final class EventListBlock
{
    /**
     * Tracks whether eventmesh/past-events-marker has already rendered its
     * heading for the Query Loop currently being rendered - reset by
     * resetPastEventsMarker() right as each Query Loop's own query is built
     * (before any of its rows render), so multiple Query Loops on the same
     * page (or repeated page renders) don't interfere with each other.
     */
    private bool $pastEventsMarkerShown = false;

    public function __construct(
        private readonly EventQuery $eventQuery,
        private readonly HolviHtmlParser $holviHtmlParser
    ) {
    }

    public function register(): void
    {
        if (! function_exists('register_block_type')) {
            return;
        }

        $this->registerPattern();

        register_block_type(
            EVENTMESH_PLUGIN_DIR . 'src/blocks/event-list',
            [
                'render_callback' => [$this, 'render'],
            ]
        );

        register_block_type(
            EVENTMESH_PLUGIN_DIR . 'src/blocks/event-field',
            [
                'render_callback' => [$this, 'renderEventField'],
            ]
        );

        register_block_type(
            EVENTMESH_PLUGIN_DIR . 'src/blocks/ticket-button',
            [
                'render_callback' => [$this, 'renderTicketButton'],
            ]
        );

        register_block_type(
            EVENTMESH_PLUGIN_DIR . 'src/blocks/provider-embed',
            [
                'render_callback' => [$this, 'renderProviderEmbed'],
            ]
        );

        register_block_type(
            EVENTMESH_PLUGIN_DIR . 'src/blocks/other-provider-links',
            [
                'render_callback' => [$this, 'renderOtherProviderLinks'],
            ]
        );

        register_block_type(
            EVENTMESH_PLUGIN_DIR . 'src/blocks/past-events-marker',
            [
                'render_callback' => [$this, 'renderPastEventsMarker'],
            ]
        );

        // The Query Loop block's own query attributes can't express
        // "upcoming events first, past events sorted to the bottom" -
        // override it server-side regardless of what the editor UI shows.
        add_filter('query_loop_block_query_vars', [$this->eventQuery, 'markQueryLoopUpcomingFirst']);
        add_filter('query_loop_block_query_vars', [$this, 'resetPastEventsMarker']);
        add_filter('render_block', [$this, 'markEventRowStatus'], 10, 3);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function resetPastEventsMarker(array $query): array
    {
        $this->pastEventsMarkerShown = false;

        return $query;
    }

    /**
     * Renders a heading exactly once per Query Loop, the first time a post
     * whose start date has passed comes through - since rows already arrive
     * upcoming-first-then-past (applyUpcomingFirstClauses()), that's exactly
     * the upcoming/past transition point, with no look-ahead needed.
     *
     * @param array<string, mixed> $attributes
     */
    public function renderPastEventsMarker(array $attributes, string $content, $block): string
    {
        $postId = $this->contextPostId($block);

        if (0 === $postId || $this->pastEventsMarkerShown || ! $this->isPastEvent($postId)) {
            return '';
        }

        $this->pastEventsMarkerShown = true;

        $tag = isset($attributes['tag']) && is_string($attributes['tag']) && '' !== $attributes['tag']
            ? $attributes['tag']
            : 'h3';
        $text = isset($attributes['text']) && is_string($attributes['text']) && '' !== trim($attributes['text'])
            ? $attributes['text']
            : __('Past Events', 'eventmesh');

        return sprintf('<%1$s %2$s>%3$s</%1$s>', esc_html($tag), get_block_wrapper_attributes(), esc_html($text));
    }

    /**
     * Adds visual "past"/"sold out" marker classes to an event row's
     * wrapping columns block, so a stale Holvi listing that hasn't been
     * removed from the source, or a sold-out event, sinks visually instead
     * of looking identical to a regular upcoming one. Both classes are
     * independent (a sold-out event can be upcoming or past) and can both
     * apply to the same row. Scoped narrowly to core/columns blocks
     * carrying the row's own className, rather than post_type generally, to
     * avoid any effect on unrelated columns elsewhere on a page.
     *
     * Uses the render_block output filter rather than render_block_data:
     * the latter only fires for the top-level blocks WordPress parses
     * directly out of post_content, never for blocks nested inside another
     * block's inner blocks (as this columns row is, several levels under
     * core/query > core/post-template) - render_block fires for every
     * block's own WP_Block::render() call regardless of nesting depth.
     *
     * @param array<string, mixed> $parsedBlock
     */
    public function markEventRowStatus(string $blockContent, array $parsedBlock, $blockInstance): string
    {
        if ('core/columns' !== ($parsedBlock['blockName'] ?? '')) {
            return $blockContent;
        }

        $className = (string) ($parsedBlock['attrs']['className'] ?? '');

        if (! str_contains($className, 'eventmesh-event-row')) {
            return $blockContent;
        }

        $postId = $this->contextPostId($blockInstance);

        if (0 === $postId) {
            return $blockContent;
        }

        $extraClasses = trim(
            ($this->isPastEvent($postId) ? 'eventmesh-event-past ' : '') .
            ($this->isSoldOut($postId) ? 'eventmesh-sold-out-row ' : '')
        );

        if ('' === $extraClasses) {
            return $blockContent;
        }

        // The rendered content's very first class="..." attribute belongs
        // to this block's own root wrapper element (its children, already
        // rendered into $blockContent by this point, come after it) - safe
        // to target with a single replacement.
        return (string) preg_replace(
            '/class="/',
            'class="' . $extraClasses . ' ',
            $blockContent,
            1
        );
    }

    private function isPastEvent(int $postId): bool
    {
        $startsAt = (string) get_post_meta($postId, '_eventmesh_starts_at', true);

        if ('' === $startsAt) {
            return false;
        }

        return $startsAt < (new \DateTimeImmutable('now'))->format(\DATE_ATOM);
    }

    private function isSoldOut(int $postId): bool
    {
        return '1' === (string) get_post_meta($postId, '_eventmesh_sold_out', true);
    }

    /**
     * Renders one computed value ("field" attribute: starts_at/title/venue)
     * for the current post in a Query Loop. Plain PHP, not Block Bindings -
     * bindings on core/paragraph and core/post-title proved unreliable in
     * testing (the bound value never applied, even for the simplest
     * possible case of a raw meta value bound as-is), so this block renders
     * itself directly instead of relying on that mechanism.
     *
     * @param array<string, mixed> $attributes
     */
    public function renderEventField(array $attributes, string $content, $block): string
    {
        $postId = $this->contextPostId($block);

        if (0 === $postId) {
            return '';
        }

        $field = isset($attributes['field']) && is_string($attributes['field']) ? $attributes['field'] : 'starts_at';

        return match ($field) {
            'title' => $this->renderTitleField($postId, $attributes),
            'venue' => $this->renderVenueField($postId, $attributes),
            default => $this->renderStartsAtField($postId, $attributes),
        };
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function renderTitleField(int $postId, array $attributes): string
    {
        $title = get_the_title($postId);

        if ('' === $title) {
            return '';
        }

        $title = $this->holviHtmlParser->stripDateForDisplay($title);
        $strikethrough = EventStatus::isCanceled($title) ? ' style="text-decoration:line-through"' : '';

        return $this->renderTextField($postId, $attributes, $title, 'h4', $strikethrough);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function renderVenueField(int $postId, array $attributes): string
    {
        $venue = (string) get_post_meta($postId, '_eventmesh_venue_name', true);

        if ('' === $venue) {
            return '';
        }

        return $this->renderTextField($postId, $attributes, $venue, 'p');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function renderStartsAtField(int $postId, array $attributes): string
    {
        $formatted = $this->formattedStartDate($postId);

        if (null === $formatted) {
            return '';
        }

        $strikethrough = EventStatus::isCanceled(get_the_title($postId)) ? ' style="text-decoration:line-through"' : '';

        return $this->renderTextField($postId, $attributes, $formatted, 'p', $strikethrough);
    }

    /**
     * Shared by all three event-field variants: wraps $text in the
     * attributes-chosen tag (falling back to $defaultTag), optionally
     * linking it to the event's own permalink - "linked" defaults to true
     * (matching block.json) so existing title fields keep working exactly
     * as before, but is overridden to false on the single-event template's
     * own fields, since a field linking back to the very page it's already
     * on is meaningless there.
     *
     * @param array<string, mixed> $attributes
     */
    private function renderTextField(
        int $postId,
        array $attributes,
        string $text,
        string $defaultTag,
        string $extraAttrs = ''
    ): string {
        $tag = isset($attributes['tag']) && is_string($attributes['tag']) && '' !== $attributes['tag']
            ? $attributes['tag']
            : $defaultTag;
        $linked = ! isset($attributes['linked']) || (bool) $attributes['linked'];

        $inner = match (true) {
            $linked => sprintf(
                '<a href="%s"%s>%s</a>',
                esc_url((string) get_permalink($postId)),
                $extraAttrs,
                esc_html($text)
            ),
            '' !== $extraAttrs => sprintf('<span%s>%s</span>', $extraAttrs, esc_html($text)),
            default => esc_html($text),
        };

        return sprintf('<%1$s %2$s>%3$s</%1$s>', esc_html($tag), get_block_wrapper_attributes(), $inner);
    }

    /**
     * Builds "Sat 11 June 2026" (or without the year, when the source
     * didn't specify one), extended with an end date when it falls on a
     * different day ("Sat 27 June 2026 - Sun 28 June 2026") and a time range
     * when a time-of-day is actually known ("18:00" / "18:00 - 21:00").
     */
    private function formattedStartDate(int $postId): ?string
    {
        $startsAtRaw = (string) get_post_meta($postId, '_eventmesh_starts_at', true);

        if ('' === $startsAtRaw) {
            return null;
        }

        try {
            $start = new \DateTimeImmutable($startsAtRaw);
        } catch (\Exception) {
            return null;
        }

        $end = null;
        $endsAtRaw = (string) get_post_meta($postId, '_eventmesh_ends_at', true);

        if ('' !== $endsAtRaw) {
            try {
                $end = new \DateTimeImmutable($endsAtRaw);
            } catch (\Exception) {
                $end = null;
            }
        }

        $yearKnown = '1' === (string) get_post_meta($postId, '_eventmesh_starts_at_year_known', true);
        $dateFormat = $yearKnown ? 'D j F Y' : 'D j F';

        $result = date_i18n($dateFormat, $start->getTimestamp());

        if (null !== $end && $end->format('Y-m-d') !== $start->format('Y-m-d')) {
            $result .= ' - ' . date_i18n($dateFormat, $end->getTimestamp());
        }

        if ('00:00' !== $start->format('H:i')) {
            $result .= ' ' . date_i18n('H:i', $start->getTimestamp());

            if (null !== $end && '00:00' !== $end->format('H:i')) {
                $result .= ' - ' . date_i18n('H:i', $end->getTimestamp());
            }
        }

        return $result;
    }

    /**
     * Renders a button linking to the current event's ticket page. Plain
     * PHP rendering (not Block Bindings on core/button's url attribute,
     * which proved unreliable in testing) - this always has a correct href.
     *
     * @param array<string, mixed> $attributes
     */
    public function renderTicketButton(array $attributes, string $content, $block): string
    {
        $postId = $this->contextPostId($block);

        if (0 === $postId) {
            return '';
        }

        // This block reuses core/button's own CSS classes for visual parity
        // rather than shipping its own stylesheet, but the frontend only
        // loads a block's styles when that exact block is actually present
        // on the page - since no real core/button instance exists here,
        // its stylesheet (border-radius etc.) would otherwise never load,
        // making this button look right in the editor (which loads every
        // block's styles) but square/unstyled on the frontend.
        wp_enqueue_block_style('core/button');

        $url = (string) get_post_meta($postId, '_eventmesh_url', true);

        if ('' === $url) {
            return '';
        }

        $soldOut = '1' === (string) get_post_meta($postId, '_eventmesh_sold_out', true);

        // The secondary class is layered on top of the same base button
        // classes in both cases (rather than swapped for a differently
        // sized class), so the sold-out button is always the exact same
        // size and shape as a regular one - only its color changes.
        $class = 'wp-block-button__link wp-element-button';

        if ($soldOut) {
            // Sold out overrides any custom label - it's a status, not a
            // call to action - but still links through to the store since
            // the event may resume selling tickets later.
            $text = __('Sold out', 'eventmesh');
            $class .= ' eventmesh-ticket-button--secondary';
        } else {
            $text = isset($attributes['text']) && is_string($attributes['text']) && '' !== trim($attributes['text'])
                ? $attributes['text']
                : __('Tickets', 'eventmesh');
        }

        $wrapperAttributes = get_block_wrapper_attributes(
            [
                'href' => esc_url($url),
                'class' => $class,
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
            ]
        );

        return sprintf('<a %s>%s</a>', $wrapperAttributes, esc_html($text));
    }

    /**
     * Renders a cached compact player (Spotify/SoundCloud/Mixcloud) for the
     * current event, if one was resolved during sync - see
     * ProviderEmbedEnricher for how _eventmesh_embed_html gets populated.
     * Renders nothing when no eligible provider link was found.
     *
     * @param array<string, mixed> $attributes
     */
    public function renderProviderEmbed(array $attributes, string $content, $block): string
    {
        $postId = $this->contextPostId($block);

        if (0 === $postId) {
            return '';
        }

        $html = (string) get_post_meta($postId, '_eventmesh_embed_html', true);

        if ('' === $html) {
            return '';
        }

        return sprintf(
            '<div %s><div class="eventmesh-provider-embed">%s</div></div>',
            get_block_wrapper_attributes(),
            EmbedHtmlSanitizer::sanitize($html)
        );
    }

    /**
     * Lists any provider links besides whichever one is already shown as a
     * compact embed (if any), so the same link never appears twice on the
     * page - see ProviderEmbedEnricher for how the priority pick and
     * _eventmesh_embed_source_url are determined.
     *
     * @param array<string, mixed> $attributes
     */
    public function renderOtherProviderLinks(array $attributes, string $content, $block): string
    {
        $postId = $this->contextPostId($block);

        if (0 === $postId) {
            return '';
        }

        $embeddedUrl = (string) get_post_meta($postId, '_eventmesh_embed_source_url', true);
        $labels = KnownProviders::labels();
        $links = [];

        foreach (get_post_meta($postId) as $key => $values) {
            if (! str_starts_with($key, '_eventmesh_provider_')) {
                continue;
            }

            $url = trim((string) ($values[0] ?? ''));

            if ('' === $url || $url === $embeddedUrl) {
                continue;
            }

            $providerKey = substr($key, strlen('_eventmesh_provider_'));
            $label = $labels[$providerKey] ?? ucfirst($providerKey);

            $links[] = sprintf('<li><a href="%s">%s</a></li>', esc_url($url), esc_html($label));
        }

        if ([] === $links) {
            return '';
        }

        return sprintf('<ul %s>%s</ul>', get_block_wrapper_attributes(), implode('', $links));
    }

    /**
     * @param mixed $block
     */
    private function contextPostId($block): int
    {
        if (! is_object($block) || ! isset($block->context) || ! is_array($block->context)) {
            return 0;
        }

        $postId = $block->context['postId'] ?? 0;

        return is_numeric($postId) ? (int) $postId : 0;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function render(array $attributes = []): string
    {
        $events = $this->eventQuery->recent(
            [
                'posts_per_page' => (int) ($attributes['count'] ?? 6),
            ]
        );

        $template = isset($attributes['template']) && is_string($attributes['template'])
            ? sanitize_file_name($attributes['template'])
            : 'events-list';

        ob_start();

        $templatePath = EVENTMESH_PLUGIN_DIR . 'templates/frontend/' . $template . '.php';

        if (is_readable($templatePath)) {
            include $templatePath;
        } else {
            include EVENTMESH_PLUGIN_DIR . 'templates/frontend/events-list.php';
        }

        return (string) ob_get_clean();
    }

    private function registerPattern(): void
    {
        if (! function_exists('register_block_pattern') || ! function_exists('register_block_pattern_category')) {
            return;
        }

        register_block_pattern_category(
            'eventmesh',
            [
                'label' => __('EventMesh', 'eventmesh'),
            ]
        );

        register_block_pattern(
            'eventmesh/event-list-pattern',
            [
                'title' => __('EventMesh events', 'eventmesh'),
                'description' => __('Displays a curated list of upcoming events.', 'eventmesh'),
                'content' => '<!-- wp:eventmesh/event-list {"count":3,"template":"events-list"} /-->',
                'categories' => ['eventmesh'],
                'keywords' => [__('events', 'eventmesh'), __('calendar', 'eventmesh')],
            ]
        );

        $this->registerQueryLoopPattern();
    }

    /**
     * A Query Loop built mostly from core blocks (columns, featured image,
     * details, group) for layout, with the per-event date/title/venue/
     * ticket-link values rendered by this plugin's own small dynamic blocks
     * (eventmesh/event-field, eventmesh/ticket-button) rather than Block
     * Bindings on core blocks. Everything around those - groups, spacers,
     * columns, the details toggle - remains ordinary, freely rearrangeable
     * blocks.
     */
    private function registerQueryLoopPattern(): void
    {
        $content = <<<'HTML'
<!-- wp:query {"query":{"perPage":6,"pages":0,"offset":0,"postType":"eventmesh_event","order":"asc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false},"displayLayout":{"type":"list"}} -->
<div class="wp-block-query">
<!-- wp:post-template -->
<!-- wp:eventmesh/past-events-marker /-->
<!-- wp:columns {"verticalAlignment":"top","className":"eventmesh-event-row"} -->
<div class="wp-block-columns are-vertically-aligned-top eventmesh-event-row">

<!-- wp:column {"verticalAlignment":"top","width":"15%"} -->
<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:15%">
<!-- wp:post-featured-image {"width":"80px","height":"80px","isLink":true} /-->
</div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"top","width":"55%"} -->
<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:55%">

<!-- wp:eventmesh/event-field {"field":"starts_at","style":{"typography":{"fontSize":"0.85em"},"spacing":{"margin":{"top":"0","bottom":"0"}}}} /-->

<!-- wp:eventmesh/event-field {"field":"title","style":{"spacing":{"margin":{"top":"0"}}}} /-->

<!-- wp:eventmesh/provider-embed {"style":{"spacing":{"margin":{"top":"0.5em"}}}} /-->

<!-- wp:details {"style":{"typography":{"fontSize":"0.8rem"},"spacing":{"margin":{"top":"0.35em"}}}} -->
<details class="wp-block-details" style="margin-top:0.35em;font-size:0.8rem"><summary>__EVENTMESH_SHOW_MORE__</summary>

<!-- wp:group {"style":{"typography":{"fontSize":"1rem"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="font-size:1rem">

<!-- wp:eventmesh/event-field {"field":"venue"} /-->

<!-- wp:post-content /-->

<!-- wp:eventmesh/ticket-button {"style":{"spacing":{"margin":{"top":"1em"}}}} /-->

</div>
<!-- /wp:group -->

</details>
<!-- /wp:details -->

</div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"top","width":"30%"} -->
<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:30%">
<!-- wp:eventmesh/ticket-button /-->
</div>
<!-- /wp:column -->

</div>
<!-- /wp:columns -->
<!-- /wp:post-template -->

<!-- wp:query-no-results -->
<!-- wp:paragraph -->
<p>__EVENTMESH_NO_RESULTS__</p>
<!-- /wp:paragraph -->
<!-- /wp:query-no-results -->
</div>
<!-- /wp:query -->
HTML;

        $content = strtr(
            $content,
            [
                '__EVENTMESH_SHOW_MORE__' => esc_html__('Show more', 'eventmesh'),
                '__EVENTMESH_NO_RESULTS__' => esc_html__('No events found.', 'eventmesh'),
            ]
        );

        register_block_pattern(
            'eventmesh/event-query-loop-pattern',
            [
                'title' => __('EventMesh events (editable layout)', 'eventmesh'),
                'description' => __(
                    'A Query Loop of events built mostly from ordinary blocks (columns, group, details) so the layout can be freely rearranged, restyled, or extended after inserting it.',
                    'eventmesh'
                ),
                'content' => $content,
                'categories' => ['eventmesh'],
                'keywords' => [__('events', 'eventmesh'), __('query', 'eventmesh'), __('layout', 'eventmesh')],
            ]
        );
    }
}
