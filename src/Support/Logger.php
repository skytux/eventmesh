<?php

declare(strict_types=1);

namespace EventMesh\Support;

final class Logger
{
    public function info(string $message): void
    {
        $this->log('INFO', $message);
    }

    public function warning(string $message): void
    {
        $this->log('WARNING', $message);
    }

    public function error(string $message): void
    {
        $this->log('ERROR', $message);
    }

    private function log(string $level, string $message): void
    {
        error_log(
            sprintf(
                '[EventMesh] [%s] %s',
                $level,
                $message
            )
        );
    }
}