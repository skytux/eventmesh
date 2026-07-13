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
    <?php $pastDividerShown = false; ?>
    <?php foreach ($events as $event) : ?>
        <?php $image = (string) ($event['image'] ?? ''); ?>
        <?php $title = (string) ($event['title'] ?? ''); ?>
        <?php $soldOut = ! empty($event['sold_out']); ?>
        <?php $canceled = ! empty($event['is_canceled']); ?>
        <?php if (! $pastDividerShown && ! empty($event['is_past'])) : ?>
            <?php $pastDividerShown = true; ?>
            <div class="eventmesh-events-divider eventmesh-events-divider--grid">
                <?php esc_html_e('Past Events', 'eventmesh'); ?>
            </div>
        <?php endif; ?>
        <article class="eventmesh-event-card">
            <?php if ('' !== $image) : ?>
                <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($title); ?>" />
            <?php endif; ?>
            <h3<?php echo $canceled ? ' style="text-decoration:line-through"' : ''; ?>><?php echo esc_html($title); ?></h3>
            <?php if ($soldOut) : ?>
                <p class="eventmesh-sold-out-label"><?php esc_html_e('Sold out', 'eventmesh'); ?></p>
            <?php endif; ?>
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
            <?php include __DIR__ . '/partials/event-meta.php'; ?>
        </article>
    <?php endforeach; ?>
</div>
