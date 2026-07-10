<?php
/**
 * Single event template.
 */

if (! defined('ABSPATH')) {
    exit;
}

get_header();

$postId = get_the_ID();
$startsAt = get_post_meta($postId, '_eventmesh_starts_at', true);
$endsAt = get_post_meta($postId, '_eventmesh_ends_at', true);
$venueName = get_post_meta($postId, '_eventmesh_venue_name', true);
$sourceUrl = get_post_meta($postId, '_eventmesh_url', true);
$image = get_the_post_thumbnail($postId, 'large');
$providers = [];

foreach (get_post_meta($postId) as $key => $values) {
    if (! str_starts_with($key, '_eventmesh_provider_')) {
        continue;
    }

    $providers[substr($key, strlen('_eventmesh_provider_'))] = (string) ($values[0] ?? '');
}
?>

<main id="primary" class="site-main">
    <article id="post-<?php the_ID(); ?>" <?php post_class('eventmesh-single-event'); ?>>
        <header class="entry-header">
            <h1 class="entry-title"><?php the_title(); ?></h1>
        </header>

        <?php if ('' !== $image) : ?>
            <div class="entry-thumbnail">
                <?php echo $image; ?>
            </div>
        <?php endif; ?>

        <div class="entry-content">
            <?php if (! empty($startsAt)) : ?>
                <p><strong><?php esc_html_e('Starts:', 'eventmesh'); ?></strong> <?php echo esc_html((string) $startsAt); ?></p>
            <?php endif; ?>

            <?php if (! empty($endsAt)) : ?>
                <p><strong><?php esc_html_e('Ends:', 'eventmesh'); ?></strong> <?php echo esc_html((string) $endsAt); ?></p>
            <?php endif; ?>

            <?php if (! empty($venueName)) : ?>
                <p><strong><?php esc_html_e('Venue:', 'eventmesh'); ?></strong> <?php echo esc_html((string) $venueName); ?></p>
            <?php endif; ?>

            <?php if (! empty($sourceUrl)) : ?>
                <p><a class="button" href="<?php echo esc_url((string) $sourceUrl); ?>"><?php esc_html_e('Tickets', 'eventmesh'); ?></a></p>
            <?php endif; ?>

            <?php if (! empty($providers)) : ?>
                <ul>
                    <?php foreach ($providers as $provider => $url) : ?>
                        <?php if ('' === trim((string) $url)) : ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <li><a href="<?php echo esc_url((string) $url); ?>"><?php echo esc_html(ucfirst((string) $provider)); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php the_content(); ?>
        </div>
    </article>
</main>

<?php get_footer(); ?>