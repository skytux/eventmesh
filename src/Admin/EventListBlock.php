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
            'price' => $this->renderPriceField($postId, $attributes),
            'date_range',
            'start_date',
            'end_date',
            'start_time',
            'end_time',
            'time_range' => $this->renderDateComponentField($postId, $attributes, $field),
            default => $this->renderStartsAtField($postId, $attributes),
        };
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function renderPriceField(int $postId, array $attributes): string
    {
        $price = trim((string) get_post_meta($postId, '_eventmesh_price', true));

        if ('' === $price) {
            return '';
        }

        return $this->renderTextField($postId, $attributes, $price, 'p');
    }

    /**
     * One of the granular date/time pieces (a single date or time, or a
     * date/time range), struck through when the event is canceled - the same
     * treatment the full starts_at field and the title already get.
     *
     * @param array<string, mixed> $attributes
     */
    private function renderDateComponentField(int $postId, array $attributes, string $which): string
    {
        $text = match ($which) {
            'date_range' => $this->dateRangeText($postId),
            'start_date' => $this->startDateText($postId),
            'end_date' => $this->endDateText($postId),
            'start_time' => $this->startTimeText($postId),
            'end_time' => $this->endTimeText($postId),
            'time_range' => $this->timeRangeText($postId),
            default => null,
        };

        if (null === $text || '' === $text) {
            return '';
        }

        $strikethrough = EventStatus::isCanceled(get_the_title($postId)) ? ' style="text-decoration:line-through"' : '';

        return $this->renderTextField($postId, $attributes, $text, 'p', $strikethrough);
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

        return $this->renderTextField($postId, $attributes, $title, 'p', $strikethrough);
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
     * Shared by every event-field variant: wraps $text in the
     * attributes-chosen tag (falling back to $defaultTag, a paragraph for all
     * fields), optionally linking it to the event's own permalink. "linked"
     * defaults to false (matching block.json) - nothing links unless the
     * editor turns it on for that field - and is likewise false on the
     * single-event template's own fields, since a field linking back to the
     * very page it's already on is meaningless there.
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
        $linked = isset($attributes['linked']) && (bool) $attributes['linked'];

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
     * The full "starts_at" field: a date (or date range) plus a time range
     * when a time-of-day is known, e.g. "Sat 27 June 2026 - Sun 28 June 2026
     * 18:00 - 21:00". Composed from the same granular pieces the individual
     * date/time fields expose, so they can never drift apart.
     */
    private function formattedStartDate(int $postId): ?string
    {
        $dateRange = $this->dateRangeText($postId);

        if (null === $dateRange) {
            return null;
        }

        $timeRange = $this->timeRangeText($postId);

        return null !== $timeRange ? $dateRange . ' ' . $timeRange : $dateRange;
    }

    /**
     * Reads the event's start/end DateTimes and whether the year is known,
     * from post meta. Either date may be null.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: bool}
     */
    private function eventDates(int $postId): array
    {
        $start = $this->parseMetaDate((string) get_post_meta($postId, '_eventmesh_starts_at', true));
        $end = $this->parseMetaDate((string) get_post_meta($postId, '_eventmesh_ends_at', true));
        $yearKnown = '1' === (string) get_post_meta($postId, '_eventmesh_starts_at_year_known', true);

        return [$start, $end, $yearKnown];
    }

    private function parseMetaDate(string $raw): ?\DateTimeImmutable
    {
        if ('' === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * "Sat 11 June 2026" (year dropped when the source never stated one).
     */
    private function startDateText(int $postId): ?string
    {
        [$start, , $yearKnown] = $this->eventDates($postId);

        if (null === $start) {
            return null;
        }

        return date_i18n($yearKnown ? 'D j F Y' : 'D j F', $start->getTimestamp());
    }

    private function endDateText(int $postId): ?string
    {
        [, $end, $yearKnown] = $this->eventDates($postId);

        if (null === $end) {
            return null;
        }

        return date_i18n($yearKnown ? 'D j F Y' : 'D j F', $end->getTimestamp());
    }

    /**
     * The start date, extended with the end date only when it falls on a
     * different day ("Sat 27 June 2026 - Sun 28 June 2026").
     */
    private function dateRangeText(int $postId): ?string
    {
        [$start, $end, ] = $this->eventDates($postId);

        if (null === $start) {
            return null;
        }

        $text = (string) $this->startDateText($postId);

        if (null !== $end && $end->format('Y-m-d') !== $start->format('Y-m-d')) {
            $text .= ' - ' . $this->endDateText($postId);
        }

        return $text;
    }

    /**
     * A date-only value always carries a midnight time component, so a start
     * of exactly 00:00 is treated as "no time known" and yields null.
     */
    private function startTimeText(int $postId): ?string
    {
        [$start, , ] = $this->eventDates($postId);

        if (null === $start || '00:00' === $start->format('H:i')) {
            return null;
        }

        return date_i18n('H:i', $start->getTimestamp());
    }

    private function endTimeText(int $postId): ?string
    {
        [, $end, ] = $this->eventDates($postId);

        if (null === $end || '00:00' === $end->format('H:i')) {
            return null;
        }

        return date_i18n('H:i', $end->getTimestamp());
    }

    /**
     * "18:00" or "18:00 - 21:00"; null when the event has no known start time.
     */
    private function timeRangeText(int $postId): ?string
    {
        $startTime = $this->startTimeText($postId);

        if (null === $startTime) {
            return null;
        }

        $endTime = $this->endTimeText($postId);

        return null !== $endTime ? $startTime . ' - ' . $endTime : $startTime;
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

        // A past event's tickets can no longer be bought, so the button is
        // meaningless - drop it entirely rather than link to a dead sale.
        if ($this->isPastEvent($postId)) {
            return '';
        }

        $this->enqueueCoreButtonStyle();

        $url = (string) get_post_meta($postId, '_eventmesh_url', true);

        if ('' === $url) {
            return '';
        }

        // The secondary class is layered on top of the same base button
        // classes (rather than swapped for a differently sized class), so the
        // sold-out marker is always the exact same size and shape as a
        // regular button - only its color changes.
        $class = 'wp-block-button__link wp-element-button';

        if ($this->isSoldOut($postId)) {
            // Sold out is a status, not a call to action: render it as a
            // non-link so nothing invites a click, and let it override any
            // custom label or price.
            $wrapperAttributes = get_block_wrapper_attributes(
                ['class' => $class . ' eventmesh-ticket-button--secondary']
            );

            return sprintf('<span %s>%s</span>', $wrapperAttributes, esc_html__('Sold out', 'eventmesh'));
        }

        $text = $this->ticketButtonLabel($postId, $attributes);

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
     * The button shows the event's actual price when the source provided one,
     * falling back to the block's own custom label, then to "Tickets".
     *
     * @param array<string, mixed> $attributes
     */
    private function ticketButtonLabel(int $postId, array $attributes): string
    {
        $price = trim((string) get_post_meta($postId, '_eventmesh_price', true));

        if ('' !== $price) {
            return $price;
        }

        if (isset($attributes['text']) && is_string($attributes['text']) && '' !== trim($attributes['text'])) {
            return $attributes['text'];
        }

        return __('Tickets', 'eventmesh');
    }

    /**
     * This block reuses core/button's own CSS classes (wp-block-button__link
     * / wp-element-button) for visual parity rather than shipping its own
     * stylesheet, but the frontend only loads a block's styles when that
     * exact block is actually present on the page - since no real
     * core/button instance exists here, its stylesheet (border-radius etc.)
     * would otherwise never load, making this button look right in the
     * editor (which loads every block's styles) but square/unstyled on the
     * frontend.
     *
     * Core registers each block's style under the handle "wp-block-{name}",
     * so enqueuing "wp-block-button" pulls in exactly that stylesheet. The
     * registered-check guards against a WP build/theme where the handle
     * isn't present, so this can never fatal. (wp_enqueue_block_style() is
     * deliberately NOT used - that function is for *registering* a new block
     * style and requires a second $args argument describing the source.)
     */
    private function enqueueCoreButtonStyle(): void
    {
        if (! function_exists('wp_style_is') || ! function_exists('wp_enqueue_style')) {
            return;
        }

        if (wp_style_is('wp-block-button', 'registered')) {
            wp_enqueue_style('wp-block-button');
        }
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
        // WordPress block delimiter comments require their JSON attributes on
        // a single line, so the long lines in this markup can't be wrapped.
        // phpcs:disable Generic.Files.LineLength.TooLong
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
        // phpcs:enable Generic.Files.LineLength.TooLong

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
                    // phpcs:ignore Generic.Files.LineLength.TooLong -- single translatable string, must not be split for extraction
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
