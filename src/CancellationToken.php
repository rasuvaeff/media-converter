<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

/**
 * Cooperative cancellation state for {@see ConvertsMedia::run()} (pass it as
 * the `$token` argument).
 * A signal handler, queue worker, or application callback can call cancel().
 *
 * @api
 */
final class CancellationToken
{
    private bool $cancelled = false;

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancellationRequested(): bool
    {
        return $this->cancelled;
    }
}
