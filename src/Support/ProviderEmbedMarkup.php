<?php

declare(strict_types=1);

namespace EventMesh\Support;

/**
 * Turns a stored provider-embed iframe into the markup echoed on the front
 * end. Always sanitizes; when the "Load embedded players only when scrolled
 * into view" setting is on it also defers the iframe - swapping src for
 * data-src so the browser never requests the player until embed-lazy.js sets
 * src as it approaches the viewport - and enqueues that script.
 *
 * The single place both output paths (the provider-embed block and the
 * event-list template) go through, so deferral is consistent and already
 * stored embeds get it without a re-sync.
 */
final class ProviderEmbedMarkup
{
    public static function render(string $storedHtml): string
    {
        $sanitized = EmbedHtmlSanitizer::sanitize($storedHtml);

        if ('' === trim($sanitized)) {
            return '';
        }

        if ('1' !== (string) get_option('eventmesh_defer_embeds', '0')) {
            return $sanitized;
        }

        // Swap the iframe's src for data-src (its only src, from the trusted
        // oEmbed response) so it doesn't load until the script promotes it.
        $deferred = (string) preg_replace('/\ssrc=/', ' data-src=', $sanitized, 1);

        if (function_exists('wp_enqueue_script')) {
            wp_enqueue_script('eventmesh-embed-lazy');
        }

        // The <noscript> copy keeps the embed working with no JavaScript, so
        // deferral can never leave a blank frame.
        return $deferred . '<noscript>' . $sanitized . '</noscript>';
    }
}
