<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

/**
 * Result of one {@see RunsProcess} execution: the exit code, the tail of the
 * process stderr, and whether it was killed by a timeout.
 *
 * @api
 */
final readonly class ProcessOutcome
{
    public function __construct(
        public int $exitCode,
        public string $stderrTail,
        public bool $timedOut = false,
    ) {}

    public function isSuccess(): bool
    {
        return !$this->timedOut && $this->exitCode === 0;
    }
}
