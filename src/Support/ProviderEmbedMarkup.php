<?php

declare(strict_types=1);

namespace EventMesh\Support;

/**
 * Turns a stored provider-embed iframe into the markup echoed on the front
 * end. Always sanitizes, then applies whichever loading strategy is
 * configured.
 *
 * The single place both output paths (the provider-embed block and the
 * event-list template) go through, so the strategy is consistent and already
 * stored embeds get it without a re-sync.
 *
 * Three strategies, in precedence order:
 *
 * 1. Facade (default). Nothing third-party is requested at all until the
 *    visitor clicks a play button we render ourselves. A page listing 20
 *    events then costs 20 buttons instead of 20 Spotify/SoundCloud bundles,
 *    which is the difference between passing and failing a PageSpeed audit -
 *    each player is several hundred KB plus its own main-thread JavaScript,
 *    and loading="lazy" does not save you here: the lazy-load distance
 *    threshold widens to thousands of pixels on the throttled mobile
 *    connections those audits simulate, so most of the list loads anyway.
 *
 * 2. Scroll-into-view deferral, when the facade is switched off but
 *    eventmesh_defer_embeds is on. Players still auto-load, just later.
 *
 * 3. Plain live iframes, when both are off.
 */
final class ProviderEmbedMarkup
{
    /**
     * @param string               $storedHtml The stored provider iframe.
     * @param array<string, mixed> $attributes The provider-embed block's own
     *                                         attributes, from which the facade
     *                                         takes its button text. Empty when
     *                                         rendered outside a block.
     */
    public static function render(string $storedHtml, array $attributes = []): string
    {
        $sanitized = EmbedHtmlSanitizer::sanitize($storedHtml);

        if ('' === trim($sanitized)) {
            return '';
        }

        if ('1' === (string) get_option('eventmesh_embed_facade', '1')) {
            return self::renderFacade($sanitized, $attributes);
        }

        if ('1' !== (string) get_option('eventmesh_defer_embeds', '0')) {
            return $sanitized;
        }

        return self::renderDeferred($sanitized);
    }

    /**
     * Renders a click-to-play button alongside a src-less iframe.
     *
     * The iframe element is emitted up front (hidden, carrying data-src)
     * rather than built in JavaScript on click, so the click handler only has
     * to move one attribute and no markup is ever assembled from a string at
     * runtime.
     *
     * @param array<string, mixed> $attributes
     */
    private static function renderFacade(string $sanitized, array $attributes): string
    {
        $deferred = self::toDataSrc($sanitized);

        // No src to withhold - leave the embed as it is rather than hiding it
        // behind a button that could never reveal anything.
        if ($deferred === $sanitized) {
            return $sanitized;
        }

        if (function_exists('wp_enqueue_script')) {
            wp_enqueue_script('eventmesh-embed-facade');
        }

        // No aria-label: the visible text is the button's accessible name, so
        // adding one would only risk the two disagreeing. The player's own
        // title attribute still identifies it once it loads.
        // The button fills the player's full 70px box so the click target and
        // the reserved height match it exactly; the pill inside is the part
        // that's actually drawn, hugging the text.
        return sprintf(
            '<button type="button" class="eventmesh-embed-facade">' .
            '<span class="eventmesh-embed-facade__pill">' .
            '<span class="eventmesh-embed-facade__icon" aria-hidden="true"></span>' .
            '<span class="eventmesh-embed-facade__label">%s</span>' .
            '</span>' .
            '</button>' .
            '<div class="eventmesh-embed-frame" hidden>%s</div>' .
            '<noscript>%s</noscript>',
            esc_html(self::buttonText($sanitized, $attributes)),
            $deferred,
            $sanitized
        );
    }

    private static function renderDeferred(string $sanitized): string
    {
        if (function_exists('wp_enqueue_script')) {
            wp_enqueue_script('eventmesh-embed-lazy');
        }

        // The <noscript> copy keeps the embed working with no JavaScript, so
        // deferral can never leave a blank frame.
        return self::toDataSrc($sanitized) . '<noscript>' . $sanitized . '</noscript>';
    }

    /**
     * Swaps the iframe's src for data-src (its only src, from the trusted
     * oEmbed response) so the browser never requests the player on its own.
     */
    private static function toDataSrc(string $sanitized): string
    {
        return (string) preg_replace('/\ssrc=/', ' data-src=', $sanitized, 1);
    }

    /**
     * Assembles the visible button text from the block's parts:
     * prefix + label + suffix + platform name, e.g. "" + "Listen to this DJ" +
     * " on " + "Spotify".
     *
     * Prefix and suffix are used exactly as typed, spacing included - the same
     * contract as the ticket-button block's, where the editor writes "From "
     * or " ->" and controls their own spacing.
     *
     * @param array<string, mixed> $attributes
     */
    private static function buttonText(string $sanitized, array $attributes): string
    {
        $prefix = (string) ($attributes['prefix'] ?? '');
        $suffix = (string) ($attributes['suffix'] ?? '');
        $platform = ! empty($attributes['showPlatform'])
            ? (string) (KnownProviders::labelForUrl(self::iframeSrc($sanitized)) ?? '')
            : '';

        // The suffix is what joins the label to the platform name when one is
        // shown (" on "), so a platform we can't name would otherwise leave it
        // dangling - "Listen to this DJ on". Drop the joiner with it.
        if (! empty($attributes['showPlatform']) && '' === $platform) {
            $suffix = '';
        }

        return $prefix . self::playLabel($attributes['playLabel'] ?? null) . $suffix . $platform;
    }

    /**
     * Visible label: whatever the block was given, falling back to the default
     * for embeds rendered outside a block (the event-list template) or with the
     * text cleared.
     *
     * Spelled out as a literal rather than shared with the block's own default
     * through a constant, because translation tooling can only extract a
     * literal here. The matching value lives in provider-embed/block.json;
     * change one and change the other.
     */
    private static function playLabel(mixed $playLabel): string
    {
        $label = trim((string) $playLabel);

        return '' !== $label ? $label : __('Listen to this DJ', 'eventmesh');
    }

    private static function iframeSrc(string $sanitized): string
    {
        return preg_match('/\ssrc="([^"]*)"/i', $sanitized, $matches) ? $matches[1] : '';
    }
}
