<?php

declare(strict_types=1);

namespace EventMesh\Translation;

use RuntimeException;

/**
 * The model answered, but the answer failed the safety check (or was not the
 * expected language code). The message is the short reason only.
 */
final class TranslationRejected extends RuntimeException
{
    public function kind(): string
    {
        return $this->getMessage();
    }
}
