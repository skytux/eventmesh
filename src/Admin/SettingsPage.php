<?php

declare(strict_types=1);

namespace EventMesh\Admin;

final class SettingsPage
{
    public function __construct(
        private readonly View $view
    ) {
    }

    public function render(): void
    {
        $this->view->render('settings');
    }
}
