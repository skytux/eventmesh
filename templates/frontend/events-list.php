<?php
/**
 * Front-end events list view.
 *
 * @var array<int, array<string, mixed>> $events
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="eventmesh-events-list">
    <?php if ([] === $events) : ?>
        <p><?php esc_html_e('No events are available yet.', 'eventmesh'); ?></p>
    <?php else : ?>
        <ul class="eventmesh-events-list__items">
            <?php $pastDividerShown = false; ?>
            <?php foreach ($events as $event) : ?>
                <?php $soldOut = ! empty($event['sold_out']); ?>
                <?php $canceled = ! empty($event['is_canceled']); ?>
                <?php if (! $pastDividerShown && ! empty($event['is_past'])) : ?>
                    <?php $pastDividerShown = true; ?>
                    <li class="eventmesh-events-divider"><?php esc_html_e('Past Events', 'eventmesh'); ?></li>
                <?php endif; ?>
                <li class="eventmesh-events-list__item">
                    <?php $image = (string) ($event['image'] ?? ''); ?>
                    <?php if ('' !== $image) : ?>
                        <a href="<?php echo esc_url((string) ($event['url'] ?? '#')); ?>">
                            <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($event['title'] ?? '')); ?>" />
                        </a>
                    <?php endif; ?>
                    <h3<?php echo $canceled ? ' style="text-decoration:line-through"' : ''; ?>><a href="<?php echo esc_url((string) ($event['url'] ?? '#')); ?>"><?php echo esc_html((string) ($event['title'] ?? '')); ?></a></h3>
                    <?php if ($soldOut) : ?>
                        <p class="eventmesh-sold-out-label"><?php esc_html_e('Sold out', 'eventmesh'); ?></p>
                    <?php endif; ?>
                    <?php if (! empty($event['embed_html'])) : ?>
                        <div class="eventmesh-provider-embed"><?php echo EventMesh\Support\ProviderEmbedMarkup::render((string) $event['embed_html']); ?></div>
                    <?php endif; ?>
                    <?php if (! empty($event['starts_at'])) : ?>
                        <p<?php echo $canceled ? ' style="text-decoration:line-through"' : ''; ?>><strong><?php esc_html_e('Starts:', 'eventmesh'); ?></strong> <?php echo esc_html((string) $event['starts_at']); ?></p>
                    <?php endif; ?>
                    <?php if (! empty($event['venue_name'])) : ?>
                        <p><strong><?php esc_html_e('Venue:', 'eventmesh'); ?></strong> <?php echo esc_html((string) $event['venue_name']); ?></p>
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
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
