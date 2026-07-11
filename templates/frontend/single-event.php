<?php
/**
 * Single event template.
 *
 * Renders the editable, block-based layout from SingleEventTemplate rather
 * than echoing each field directly - see that class for the default layout
 * and how it stays editable via WordPress's block-template system.
 */

if (! defined('ABSPATH')) {
    exit;
}

get_header();

$postId = get_the_ID();
?>

<main id="primary" class="site-main">
    <article id="post-<?php the_ID(); ?>" <?php post_class('eventmesh-single-event'); ?>>
        <?php echo (new EventMesh\Content\SingleEventTemplate())->render($postId); ?>
    </article>
</main>

<?php get_footer(); ?>