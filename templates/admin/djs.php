<?php
/**
 * Events → DJs admin view.
 *
 * @var array<int, EventMesh\Djs\Dj> $djs Every DJ, sorted by name.
 * @var EventMesh\Djs\Dj|null $editing The DJ being edited, or null for the add form.
 * @var array<string, string> $link_labels Service key => label (Spotify, SoundCloud, Mixcloud).
 * @var array<string, string> $source_labels Description source => label.
 * @var string $notice Which message to show after a save or delete ('' for none).
 * @var int $changed_events How many future events had their links changed by the last save or delete.
 * @var int $described How many descriptions the last refresh changed.
 * @var int $left How many DJs still wait for a profile fetch in the background.
 * @var int $team_page_id The chosen team page, 0 for none.
 * @var string $page_url URL of this page.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$djs = isset($djs) && is_array($djs) ? $djs : [];
$editing = isset($editing) && $editing instanceof EventMesh\Djs\Dj ? $editing : null;
$link_labels = isset($link_labels) && is_array($link_labels) ? $link_labels : [];
$source_labels = isset($source_labels) && is_array($source_labels) ? $source_labels : [];
$notice = isset($notice) ? (string) $notice : '';
$changed_events = isset($changed_events) ? (int) $changed_events : 0;
$described = isset($described) ? (int) $described : 0;
$left = isset($left) ? (int) $left : 0;
$team_page_id = isset($team_page_id) ? (int) $team_page_id : 0;
$page_url = isset($page_url) ? (string) $page_url : '';

$notice_messages = [
    'saved' => ['success', __('DJ saved.', 'eventmesh')],
    'deleted' => ['success', __('DJ deleted.', 'eventmesh')],
    'no_name' => ['error', __('A DJ needs a name.', 'eventmesh')],
    'bad_link' => ['error', __('Each link must be an address on its own service: a Spotify link in the Spotify field, and so on.', 'eventmesh')],
    'not_saved' => ['error', __('The DJ could not be saved.', 'eventmesh')],
    'team_page_saved' => ['info', __('Team page saved.', 'eventmesh')],
    'descriptions_fetched' => ['info', __('Descriptions fetched.', 'eventmesh')],
];
?>

<div class="wrap">
    <h1><?php esc_html_e('DJs', 'eventmesh'); ?></h1>

    <?php if (isset($notice_messages[$notice])) : ?>
        <div class="notice notice-<?php echo esc_attr($notice_messages[$notice][0]); ?> is-dismissible">
            <p>
                <?php echo esc_html($notice_messages[$notice][1]); ?>
                <?php if ('success' === $notice_messages[$notice][0]) : ?>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d: number of future events */
                            _n('%d future event got new or changed links.', '%d future events got new or changed links.', $changed_events, 'eventmesh'),
                            $changed_events
                        )
                    );
                    ?>
                <?php endif; ?>
                <?php if ('info' === $notice_messages[$notice][0]) : ?>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d: number of DJs */
                            _n('%d DJ got a new or changed description.', '%d DJs got a new or changed description.', $described, 'eventmesh'),
                            $described
                        )
                    );
                    ?>
                    <?php if ($left > 0) : ?>
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %d: number of DJs */
                                _n('%d DJ profile will be fetched in the background.', '%d DJ profiles will be fetched in the background.', $left, 'eventmesh'),
                                $left
                            )
                        );
                        ?>
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <p class="description" style="max-width: 960px;">
        <?php esc_html_e('When a DJ name or other spelling appears as whole words in an event title or text (case and accents do not matter), the event gets the DJ links for each service it has no link for yet, and its player is updated. A link typed on the event itself is never replaced. After every change here, all future events are checked again.', 'eventmesh'); ?>
    </p>

    <table class="widefat striped" style="max-width: 1200px; margin-top: 12px;">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('DJ name', 'eventmesh'); ?></th>
                <th scope="col"><?php esc_html_e('Other spellings', 'eventmesh'); ?></th>
                <?php foreach ($link_labels as $link_label) : ?>
                    <th scope="col"><?php echo esc_html($link_label); ?></th>
                <?php endforeach; ?>
                <th scope="col"><?php esc_html_e('Description', 'eventmesh'); ?></th>
                <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'eventmesh'); ?></span></th>
            </tr>
        </thead>
        <tbody>
            <?php if ([] === $djs) : ?>
                <tr>
                    <td colspan="<?php echo esc_attr((string) (4 + count($link_labels))); ?>">
                        <?php esc_html_e('No DJs yet.', 'eventmesh'); ?>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ($djs as $dj) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($dj->name); ?></strong></td>
                        <td><?php echo esc_html(implode(', ', $dj->aliases)); ?></td>
                        <?php foreach (array_keys($link_labels) as $link_kind) : ?>
                            <td style="word-break: break-all;">
                                <?php if ('' !== $dj->link($link_kind)) : ?>
                                    <a href="<?php echo esc_url($dj->link($link_kind)); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($dj->link($link_kind)); ?></a>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td>
                            <?php if ('' !== $dj->description) : ?>
                                <?php echo esc_html(EventMesh\Djs\DjDescriptions::shorten($dj->description, 120)); ?>
                                <?php if (isset($source_labels[$dj->descriptionSource])) : ?>
                                    <br /><span class="description"><?php echo esc_html($source_labels[$dj->descriptionSource]); ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td style="white-space: nowrap;">
                            <a href="<?php echo esc_url(add_query_arg(['edit' => $dj->id], $page_url)); ?>"><?php esc_html_e('Edit', 'eventmesh'); ?></a>
                            |
                            <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'eventmesh_delete_dj', 'id' => $dj->id], admin_url('admin-post.php')), 'eventmesh_delete_dj_' . $dj->id)); ?>"
                                onclick="return window.confirm('<?php echo esc_js(__('Delete this DJ?', 'eventmesh')); ?>');"
                                style="color: #b32d2e;"><?php esc_html_e('Delete', 'eventmesh'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="card" style="max-width: 960px; margin-top: 20px;">
        <h2><?php echo esc_html(null !== $editing ? __('Edit DJ', 'eventmesh') : __('Add DJ', 'eventmesh')); ?></h2>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="eventmesh_save_dj" />
            <input type="hidden" name="eventmesh_dj_id" value="<?php echo esc_attr((string) (null !== $editing ? $editing->id : 0)); ?>" />
            <?php wp_nonce_field('eventmesh_save_dj'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="eventmesh-dj-name"><?php esc_html_e('DJ name', 'eventmesh'); ?></label></th>
                    <td><input type="text" id="eventmesh-dj-name" name="eventmesh_dj_name" class="regular-text" required value="<?php echo esc_attr(null !== $editing ? $editing->name : ''); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="eventmesh-dj-aliases"><?php esc_html_e('Other spellings', 'eventmesh'); ?></label></th>
                    <td>
                        <textarea id="eventmesh-dj-aliases" name="eventmesh_dj_aliases" rows="3" class="regular-text"><?php echo esc_textarea(null !== $editing ? implode("\n", $editing->aliases) : ''); ?></textarea>
                        <p class="description"><?php esc_html_e('One per line. Matched the same way as the name.', 'eventmesh'); ?></p>
                    </td>
                </tr>
                <?php foreach ($link_labels as $link_kind => $link_label) : ?>
                    <tr>
                        <th scope="row"><label for="eventmesh-dj-<?php echo esc_attr($link_kind); ?>"><?php echo esc_html($link_label); ?></label></th>
                        <td><input type="url" id="eventmesh-dj-<?php echo esc_attr($link_kind); ?>" name="eventmesh_dj_<?php echo esc_attr($link_kind); ?>" class="large-text" value="<?php echo esc_attr(null !== $editing ? $editing->link($link_kind) : ''); ?>" /></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <th scope="row"><label for="eventmesh-dj-description"><?php esc_html_e('Description', 'eventmesh'); ?></label></th>
                    <td>
                        <textarea id="eventmesh-dj-description" name="eventmesh_dj_description" rows="6" class="large-text"><?php echo esc_textarea(null !== $editing ? $editing->description : ''); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('A few sentences in English about the DJ. Text you type here is kept as it is. Leave it empty to fill it from the team page or the SoundCloud or Mixcloud profile.', 'eventmesh'); ?>
                            <?php if (null !== $editing && '' !== $editing->description && isset($source_labels[$editing->descriptionSource])) : ?>
                                <?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: %s: where the description came from, such as Team page or SoundCloud */
                                        __('Now from: %s.', 'eventmesh'),
                                        $source_labels[$editing->descriptionSource]
                                    )
                                );
                                ?>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e('Save DJ', 'eventmesh'); ?></button>
                <?php if (null !== $editing) : ?>
                    <a class="button" href="<?php echo esc_url($page_url); ?>"><?php esc_html_e('Cancel', 'eventmesh'); ?></a>
                <?php endif; ?>
            </p>
        </form>
    </div>

    <div class="card" style="max-width: 960px; margin-top: 20px;">
        <h2><?php esc_html_e('Descriptions', 'eventmesh'); ?></h2>

        <p class="description">
            <?php esc_html_e('A DJ without a typed description gets the text under its name on the team page: a heading (h2 to h4) that is the DJ name or other spelling, and the paragraphs after it. A DJ not on the team page gets the profile text from SoundCloud or Mixcloud. Other plugins read the descriptions with the eventmesh/event_djs filter.', 'eventmesh'); ?>
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="eventmesh_save_dj_team_page" />
            <?php wp_nonce_field('eventmesh_save_dj_team_page'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="eventmesh-dj-team-page"><?php esc_html_e('Team page', 'eventmesh'); ?></label></th>
                    <td>
                        <?php
                        wp_dropdown_pages(
                            [
                                'name' => 'eventmesh_dj_team_page',
                                'id' => 'eventmesh-dj-team-page',
                                'selected' => (int) $team_page_id,
                                'show_option_none' => esc_html__('None', 'eventmesh'),
                                'option_none_value' => '0',
                                'post_status' => 'publish',
                            ]
                        );
                        ?>
                    </td>
                </tr>
            </table>

            <p><button type="submit" class="button"><?php esc_html_e('Save team page', 'eventmesh'); ?></button></p>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="eventmesh_fetch_dj_descriptions" />
            <?php wp_nonce_field('eventmesh_fetch_dj_descriptions'); ?>
            <p>
                <button type="submit" class="button"><?php esc_html_e('Fetch descriptions now', 'eventmesh'); ?></button>
                <span class="description"><?php esc_html_e('Reads the team page and every profile again. Typed descriptions are not changed.', 'eventmesh'); ?></span>
            </p>
        </form>
    </div>
</div>
