<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use DateTimeImmutable;
use EventMesh\Contracts\ConnectorInterface;
use EventMesh\Services\ConnectorManager;
use EventMesh\Services\HolviSourceManager;
use EventMesh\Services\SourceSettings;

final class SourcesPage
{
    public function __construct(
        private readonly View $view,
        private readonly ConnectorManager $connectors,
        private readonly HolviSourceManager $holviSourceManager,
        private readonly SourceSettings $sourceSettings
    ) {
    }

    public function render(): void
    {
        $this->view->render(
            'sources',
            [
                'connector_rows' => $this->connectorRows(),
                'holvi_sources' => $this->holviSourceManager->all(),
                'dummy_preview' => $this->dummyPreview(),
            ]
        );
    }

    public function save(): void
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to save sources.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_save_sources');

        $holviSources = isset($_POST['eventmesh_holvi_sources'])
            ? (array) $_POST['eventmesh_holvi_sources']
            : [];

        $this->holviSourceManager->save($holviSources);

        // Enablement now lives here rather than on the Settings page: a
        // connector listed in the table is available, so its on/off toggle
        // belongs next to it. The hidden 0 companion input means an unticked
        // box still submits, so unchecking genuinely disables the source.
        $enabled = isset($_POST['eventmesh_source_enabled'])
            ? array_map('absint', (array) $_POST['eventmesh_source_enabled'])
            : [];

        foreach ($enabled as $sourceId => $value) {
            $sourceId = sanitize_key((string) $sourceId);

            if ('' === $sourceId) {
                continue;
            }

            $this->sourceSettings->setEnabled($sourceId, 1 === $value);
        }

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'eventmesh-sources'],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * @return array<int, array{id: string, label: string, enabled: bool}>
     */
    private function connectorRows(): array
    {
        $rows = [];

        foreach ($this->connectors->all() as $id => $connector) {
            $rows[] = [
                'id' => (string) $id,
                'label' => $connector->label(),
                'enabled' => $this->sourceSettings->isEnabled(
                    (string) $id,
                    $connector->enabledByDefault()
                ),
            ];
        }

        return $rows;
    }

    /**
     * The dummy/test connector has no URLs to configure, so in place of a URL
     * list we show exactly what it would generate if enabled - one line per
     * sample event - as a live preview of the sync pipeline's output.
     *
     * @return array<int, string>
     */
    private function dummyPreview(): array
    {
        $dummy = $this->connectors->get('dummy');

        if (! $dummy instanceof ConnectorInterface) {
            return [];
        }

        $lines = [];

        foreach ($dummy->fetch() as $event) {
            $startsAt = $event->startsAt();
            $when = $startsAt instanceof DateTimeImmutable
                ? $startsAt->format('Y-m-d H:i')
                : __('date unknown', 'eventmesh');

            $bits = [$event->title(), $when];

            if ('' !== $event->price()) {
                $bits[] = $event->price();
            }

            if ($event->soldOut()) {
                $bits[] = __('sold out', 'eventmesh');
            }

            $lines[] = implode(' — ', $bits);
        }

        return $lines;
    }
}
