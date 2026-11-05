<?php

declare(strict_types=1);

namespace EventMesh\Core;

use EventMesh\Translation\EventPageLanguage;

final class Plugin
{
    private static ?self $instance = null;

    private Kernel $kernel;

    public static function boot(): void
    {
        if (self::$instance instanceof self) {
            return;
        }

        self::$instance = new self();
    }

    private function __construct()
    {
        $this->kernel = new Kernel();

        // Before plugins_loaded: Polylang picks the language at priority 1.
        (new EventPageLanguage())->boot();

        add_action(
            'plugins_loaded',
            [$this, 'init']
        );
    }

    public function init(): void
    {
        $this->kernel->boot();
    }
}
