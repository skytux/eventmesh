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
        <?php $soldOut = ! empty($event['sold_out']); ?>
        <article class="eventmesh-event-card<?php echo ! empty($event['is_past']) ? ' eventmesh-event-past' : ''; ?>">
            <?php if ('' !== $image) : ?>
                <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($title); ?>" />
            <?php endif; ?>
            <h3<?php echo $soldOut ? ' style="text-decoration:line-through"' : ''; ?>><?php echo esc_html($title); ?></h3>
            <?php if (! empty($event['excerpt'])) : ?>
                <p><?php echo esc_html((string) $event['excerpt']); ?></p>
            <?php endif; ?>
            <?php if (! empty($event['source_url'])) : ?>
                <p>
                    <a
                        class="button<?php echo $soldOut ? ' eventmesh-ticket-button--secondary' : ''; ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        href="<?php echo esc_url((string) $event['source_url']); ?>"
                    ><?php echo $soldOut ? esc_html__('Sold out', 'eventmesh') : esc_html__('Tickets', 'eventmesh'); ?></a>
                </p>
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
