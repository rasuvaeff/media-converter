<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Support;

use Rasuvaeff\Bulkhead\Bulkhead;

/**
 * Bulkhead double that records how many times a slot was taken and delegates
 * straight through.
 */
final class RecordingBulkhead implements Bulkhead
{
    public int $calls = 0;

    #[\Override]
    public function call(callable $callback): mixed
    {
        ++$this->calls;

        return $callback();
    }

    #[\Override]
    public function availableSlots(): int
    {
        return 1;
    }
}
