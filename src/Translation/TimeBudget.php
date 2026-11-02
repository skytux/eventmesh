<?php

declare(strict_types=1);

namespace EventMesh\Translation;

/**
 * The time one run may spend on requests. The host stops PHP after 60
 * seconds, and a cron run may share its request with the event sync, so the
 * budget is counted from the start of the PHP request, not from the start of
 * the run, and a request is only started when it can finish inside it.
 */
final class TimeBudget
{
    /** Seconds from the start of the PHP request. */
    public const DEFAULT_SECONDS = 40;

    /** Do not start a request with less time than this left. */
    public const MIN_REQUEST_SECONDS = 8;

    /** @var callable(): float */
    private $clock;

    /**
     * @param callable(): float|null $clock
     */
    public function __construct(
        private readonly float $deadline,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public static function fromRequestStart(int $seconds = self::DEFAULT_SECONDS): self
    {
        $start = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);

        return new self($start + $seconds);
    }

    public function remaining(): float
    {
        return $this->deadline - ($this->clock)();
    }

    /**
     * The timeout for the next request, or null when there is not enough
     * time left to start one.
     */
    public function nextTimeout(): ?int
    {
        $remaining = (int) floor($this->remaining());

        if ($remaining < self::MIN_REQUEST_SECONDS) {
            return null;
        }

        return min(LlmClient::MAX_TIMEOUT, $remaining);
    }
}
