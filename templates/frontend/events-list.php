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
            <?php foreach ($events as $event) : ?>
                <li class="eventmesh-events-list__item">
                    <?php $image = (string) ($event['image'] ?? ''); ?>
                    <?php if ('' !== $image) : ?>
                        <a href="<?php echo esc_url((string) ($event['url'] ?? '#')); ?>">
                            <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($event['title'] ?? '')); ?>" />
                        </a>
                    <?php endif; ?>
                    <h3><a href="<?php echo esc_url((string) ($event['url'] ?? '#')); ?>"><?php echo esc_html((string) ($event['title'] ?? '')); ?></a></h3>
                    <?php if (! empty($event['starts_at'])) : ?>
                        <p><strong><?php esc_html_e('Starts:', 'eventmesh'); ?></strong> <?php echo esc_html((string) $event['starts_at']); ?></p>
                    <?php endif; ?>
                    <?php if (! empty($event['venue_name'])) : ?>
                        <p><strong><?php esc_html_e('Venue:', 'eventmesh'); ?></strong> <?php echo esc_html((string) $event['venue_name']); ?></p>
                    <?php endif; ?>
                    <?php if (! empty($event['excerpt'])) : ?>
                        <p><?php echo esc_html((string) $event['excerpt']); ?></p>
                    <?php endif; ?>
                    <?php if (! empty($event['source_url'])) : ?>
                        <p><a class="button" href="<?php echo esc_url((string) $event['source_url']); ?>"><?php esc_html_e('Tickets', 'eventmesh'); ?></a></p>
                    <?php endif; ?>
                    <?php if (! empty($event['providers'])) : ?>
                        <ul class="eventmesh-events-list__providers">
                            <?php foreach ($event['providers'] as $provider => $url) : ?>
                                <?php if ('' === trim((string) $url)) : ?>
                                    <?php continue; ?>
                                <?php endif; ?>
                                <li><a href="<?php echo esc_url((string) $url); ?>"><?php echo esc_html(ucfirst((string) $provider)); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
