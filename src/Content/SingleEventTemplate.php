<?php

declare(strict_types=1);

namespace EventMesh\Content;

/**
 * Registers an editable, block-based default layout for the single event
 * page and renders it. Uses WordPress 6.7+'s plugin block-template
 * registration API so the layout can be customized the same way the
 * events-list Query Loop pattern already is - via ordinary blocks - rather
 * than being a hardcoded PHP template.
 *
 * Front-end rendering is still done entirely by this class rather than
 * relying on WordPress's own block-template resolution, since that only
 * ever renders automatically on a full block/FSE theme - whatever content
 * is currently registered/customized for TEMPLATE_NAME is fetched and
 * rendered directly, so the page works the same regardless of whether the
 * active theme happens to expose an editing UI for it.
 */
final class SingleEventTemplate
{
    /**
     * The second half must match WordPress's own "single-{post_type}"
     * template-hierarchy naming convention - that's how WordPress recognizes
     * this as the post type's natural/default singular template, rather than
     * just an extra manually-selectable one in the post editor.
     */
    private const TEMPLATE_NAME = 'eventmesh//single-' . EventPostType::NAME;

    /**
     * Image-first: the featured image leads, followed by the title, date,
     * and venue, then the compact provider-embed player (after the image,
     * not before it), the ticket button, any other provider links, and
     * finally the event's own content.
     */
    private const DEFAULT_CONTENT = <<<'HTML'
<!-- wp:post-featured-image {"height":"360px"} /-->
<!-- wp:eventmesh/event-field {"field":"title","tag":"h1","linked":false} /-->
<!-- wp:eventmesh/event-field {"field":"starts_at","linked":false} /-->
<!-- wp:eventmesh/event-field {"field":"venue","linked":false} /-->
<!-- wp:eventmesh/provider-embed {"style":{"spacing":{"margin":{"top":"1em","bottom":"1em"}}}} /-->
<!-- wp:eventmesh/ticket-button /-->
<!-- wp:eventmesh/other-provider-links /-->
<!-- wp:post-content /-->
HTML;

    public function boot(): void
    {
        add_action('init', [$this, 'registerBlockTemplate']);
    }

    public function registerBlockTemplate(): void
    {
        if (! function_exists('register_block_template')) {
            return;
        }

        register_block_template(
            self::TEMPLATE_NAME,
            [
                'title' => __('EventMesh: Single event', 'eventmesh'),
                'description' => __(
                    'Layout for an individual event page. Fully editable as blocks.',
                    'eventmesh'
                ),
                'content' => self::DEFAULT_CONTENT,
                'post_types' => [EventPostType::NAME],
            ]
        );
    }

    public function render(int $postId): string
    {
        $content = $this->resolveContent();
        $innerBlocks = parse_blocks($content);

        // WP_Block is real WordPress core machinery (not stubbed elsewhere
        // in this test suite) - resolveContent() is exposed separately so
        // the content-resolution logic above is unit-testable even though
        // this rendering call itself is only verified live.
        $block = new \WP_Block(
            [
                'blockName' => null,
                'attrs' => [],
                'innerBlocks' => $innerBlocks,
                'innerHTML' => '',
                // WP_Block::render()'s static path walks innerContent (raw
                // HTML chunks interspersed with null placeholders marking
                // "render the next inner block here"), NOT innerBlocks
                // directly - an empty innerContent renders nothing at all,
                // regardless of how many innerBlocks are present.
                'innerContent' => array_map(static fn () => null, $innerBlocks),
            ],
            [
                'postId' => $postId,
                'postType' => EventPostType::NAME,
            ]
        );

        return $block->render(['dynamic' => false]);
    }

    public function resolveContent(): string
    {
        if (! function_exists('get_block_template')) {
            return self::DEFAULT_CONTENT;
        }

        $template = get_block_template(self::TEMPLATE_NAME, 'wp_template');

        if (null === $template || ! isset($template->content) || '' === (string) $template->content) {
            return self::DEFAULT_CONTENT;
        }

        return (string) $template->content;
    }
}
