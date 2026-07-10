<?php
/**
 * Example custom event card template.
 *
 * @var array<int, array<string, mixed>> $events
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="eventmesh-events-grid">
    <?php foreach ($events as $event) : ?>
        <?php $image = (string) ($event['image'] ?? ''); ?>
        <?php $title = (string) ($event['title'] ?? ''); ?>
        <article class="eventmesh-event-card">
            <?php if ('' !== $image) : ?>
                <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($title); ?>" />
            <?php endif; ?>
            <h3><?php echo esc_html($title); ?></h3>
            <?php if (! empty($event['excerpt'])) : ?>
                <p><?php echo esc_html((string) $event['excerpt']); ?></p>
            <?php endif; ?>
            <?php if (! empty($event['source_url'])) : ?>
                <p><a class="button" href="<?php echo esc_url((string) $event['source_url']); ?>"><?php esc_html_e('Tickets', 'eventmesh'); ?></a></p>
            <?php endif; ?>
            <?php if (! empty($event['providers'])) : ?>
                <ul>
                    <?php foreach ($event['providers'] as $provider => $url) : ?>
                        <?php if ('' === trim((string) $url)) : ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <li><a href="<?php echo esc_url((string) $url); ?>"><?php echo esc_html(ucfirst((string) $provider)); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>
