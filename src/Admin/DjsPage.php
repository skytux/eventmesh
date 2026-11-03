<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Content\EventPostType;
use EventMesh\Djs\Dj;
use EventMesh\Djs\DjDescriptions;
use EventMesh\Djs\DjLinkApplier;
use EventMesh\Djs\DjRepository;
use EventMesh\Support\KnownProviders;

/**
 * Events → DJs: the list of DJs with their other spellings, links and
 * descriptions, and a form to add, change or delete one. After every change
 * all future events are checked again (see DjLinkApplier). The page also
 * holds the Team page setting and the "Fetch descriptions now" button (see
 * DjDescriptions).
 */
final class DjsPage
{
    public const SLUG = 'eventmesh-djs';

    public function __construct(
        private readonly View $view,
        private readonly DjRepository $repository,
        private readonly DjLinkApplier $applier,
        private readonly DjDescriptions $descriptions
    ) {
    }

    public function parentSlug(): string
    {
        return 'edit.php?post_type=' . EventPostType::NAME;
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            $this->parentSlug(),
            __('DJs', 'eventmesh'),
            __('DJs', 'eventmesh'),
            Admin::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );
    }

    // The notice and edit arguments only choose what to show; nothing is
    // changed by reading them.
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    public function render(): void
    {
        $editId = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $notice = isset($_GET['eventmesh_notice']) ? sanitize_key((string) $_GET['eventmesh_notice']) : '';
        $changed = isset($_GET['changed']) ? absint($_GET['changed']) : 0;
        $described = isset($_GET['described']) ? absint($_GET['described']) : 0;
        $left = isset($_GET['left']) ? absint($_GET['left']) : 0;

        $this->view->render(
            'djs',
            [
                'djs' => $this->repository->all(),
                'editing' => $editId > 0 ? $this->repository->find($editId) : null,
                'link_labels' => $this->linkLabels(),
                'source_labels' => self::sourceLabels(),
                'notice' => $notice,
                'changed_events' => $changed,
                'described' => $described,
                'left' => $left,
                'team_page_id' => DjDescriptions::teamPageId(),
                'page_url' => $this->pageUrl(),
            ]
        );
    }
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    public function save(): void
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to edit DJs.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_save_dj');

        $id = isset($_POST['eventmesh_dj_id']) ? absint($_POST['eventmesh_dj_id']) : 0;
        $name = isset($_POST['eventmesh_dj_name'])
            ? trim(sanitize_text_field(wp_unslash((string) $_POST['eventmesh_dj_name'])))
            : '';
        $aliases = isset($_POST['eventmesh_dj_aliases'])
            ? sanitize_textarea_field(wp_unslash((string) $_POST['eventmesh_dj_aliases']))
            : '';

        if ('' === $name) {
            $this->redirect(['eventmesh_notice' => 'no_name', 'edit' => $id]);
        }

        $links = [];

        foreach (Dj::LINK_KINDS as $kind) {
            $field = 'eventmesh_dj_' . $kind;
            $url = isset($_POST[$field]) ? esc_url_raw(trim(wp_unslash((string) $_POST[$field]))) : '';

            if ('' === $url) {
                continue;
            }

            // A Spotify field must hold a Spotify address, and so on; the
            // player for the wrong service would not load.
            if (KnownProviders::keyForUrl($url) !== $kind) {
                $this->redirect(['eventmesh_notice' => 'bad_link', 'edit' => $id]);
            }

            $links[$kind] = $url;
        }

        $description = isset($_POST['eventmesh_dj_description'])
            ? sanitize_textarea_field(wp_unslash((string) $_POST['eventmesh_dj_description']))
            : '';
        [$description, $source] = self::descriptionToStore(
            $description,
            $id > 0 ? $this->repository->find($id) : null
        );

        $savedId = $this->repository->save(
            new Dj($id, $name, Dj::parseAliases($aliases), $links, $description, $source)
        );

        if (0 === $savedId) {
            $this->redirect(['eventmesh_notice' => 'not_saved', 'edit' => $id]);
        }

        $changed = $this->applier->recheckFutureEvents();

        // A manual description is left alone; otherwise the team page or
        // the new links may give this DJ one.
        $this->descriptions->refresh([$savedId], microtime(true) + DjDescriptions::NOW_SECONDS);

        $this->redirect(['eventmesh_notice' => 'saved', 'changed' => $changed]);
    }

    /**
     * The description text and source to store from the form's Description
     * field.
     *
     * - Empty: no description, source ''; the automatic sources may fill it.
     * - The same text the DJ already has from the team page or a profile:
     *   unchanged, so saving the form for another field does not make it
     *   a manual description.
     * - Anything else: typed by an organizer, source 'manual'.
     *
     * @return array{0: string, 1: string}
     */
    public static function descriptionToStore(string $submitted, ?Dj $existing): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $submitted));

        if ('' === $text) {
            return ['', Dj::SOURCE_NONE];
        }

        $text = DjDescriptions::shorten($text);

        if (null !== $existing && ! $existing->hasManualDescription() && $existing->description === $text) {
            return [$existing->description, $existing->descriptionSource];
        }

        return [$text, Dj::SOURCE_MANUAL];
    }

    public function saveTeamPage(): void
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to edit DJs.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_save_dj_team_page');

        $pageId = isset($_POST['eventmesh_dj_team_page']) ? absint($_POST['eventmesh_dj_team_page']) : 0;

        if ($pageId > 0) {
            $page = get_post($pageId);

            if (! $page instanceof \WP_Post || 'page' !== $page->post_type) {
                $pageId = 0;
            }
        }

        update_option(DjDescriptions::TEAM_PAGE_OPTION, $pageId);

        $result = $this->descriptions->refresh();

        $this->redirect(['eventmesh_notice' => 'team_page_saved', 'described' => $result['updated']]);
    }

    public function fetchDescriptions(): void
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to edit DJs.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_fetch_dj_descriptions');

        $result = $this->descriptions->refresh(null, microtime(true) + DjDescriptions::NOW_SECONDS);

        $this->redirect(
            ['eventmesh_notice' => 'descriptions_fetched', 'described' => $result['updated'], 'left' => $result['left']]
        );
    }

    /**
     * @return array<string, string> Description source => label.
     */
    public static function sourceLabels(): array
    {
        return [
            Dj::SOURCE_MANUAL => __('Typed here', 'eventmesh'),
            Dj::SOURCE_TEAM => __('Team page', 'eventmesh'),
            Dj::SOURCE_SOUNDCLOUD => 'SoundCloud',
            Dj::SOURCE_MIXCLOUD => 'Mixcloud',
        ];
    }

    public function delete(): void
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to edit DJs.', 'eventmesh'));
        }

        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        check_admin_referer('eventmesh_delete_dj_' . $id);

        $this->repository->delete($id);

        $changed = $this->applier->recheckFutureEvents();

        $this->redirect(['eventmesh_notice' => 'deleted', 'changed' => $changed]);
    }

    /**
     * @return array<string, string> Service key => label, for the link columns.
     */
    private function linkLabels(): array
    {
        $labels = [];

        foreach (Dj::LINK_KINDS as $kind) {
            $labels[$kind] = KnownProviders::labels()[$kind] ?? $kind;
        }

        return $labels;
    }

    private function pageUrl(): string
    {
        return add_query_arg(['page' => self::SLUG], admin_url($this->parentSlug()));
    }

    /**
     * @param array<string, int|string> $args
     */
    private function redirect(array $args): never
    {
        $args = array_filter($args, static fn ($value): bool => '' !== $value && 0 !== $value);

        wp_safe_redirect(add_query_arg($args, $this->pageUrl()));
        exit;
    }
}
