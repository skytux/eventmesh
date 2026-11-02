<?php

declare(strict_types=1);

namespace EventMesh\Translation;

use RuntimeException;

/**
 * A failed request to the LLM server. The message is only ever a short error
 * kind ("network", "http_401", "bad_response", "empty"), never the request or
 * response text, so it is safe to log and to show in wp-admin.
 */
final class LlmException extends RuntimeException
{
    public function kind(): string
    {
        return $this->getMessage();
    }

    /**
     * True for failures of the connection or the server (network, HTTP
     * status, unreadable reply), as opposed to a reply that arrived but was
     * empty.
     */
    public function isTransport(): bool
    {
        return 'empty' !== $this->getMessage();
    }
}
