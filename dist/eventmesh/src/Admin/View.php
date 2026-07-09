<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use InvalidArgumentException;

final class View
{
    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): void
    {
        $path = $this->path($template);

        if (! is_readable($path)) {
            wp_die(
                esc_html__(
                    'The requested EventMesh admin view could not be loaded.',
                    'eventmesh'
                )
            );
        }

        $this->includeTemplate($path, $data);
    }

    private function path(string $template): string
    {
        if (1 !== preg_match('/^[a-z0-9_-]+$/', $template)) {
            throw new InvalidArgumentException(
                sprintf('Invalid admin template "%s".', $template)
            );
        }

        return EVENTMESH_PLUGIN_DIR . 'templates/admin/' . $template . '.php';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function includeTemplate(string $path, array $data): void
    {
        extract($data, EXTR_SKIP);

        include $path;
    }
}
