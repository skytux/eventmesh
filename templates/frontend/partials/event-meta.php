<?php
/**
 * Shared per-event provider-links list, included by both events-list.php
 * and events-card.php so a future fix only needs to happen in one place.
 *
 * @var array<string, mixed> $event
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
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
