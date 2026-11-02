<?php

declare(strict_types=1);

namespace EventMesh\Translation;

use RuntimeException;

/**
 * Not enough of the run's time budget left to start another request.
 */
final class OutOfTime extends RuntimeException
{
}
