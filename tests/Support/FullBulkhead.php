<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Support;

use Rasuvaeff\Bulkhead\Bulkhead;
use Rasuvaeff\Bulkhead\BulkheadFullException;

/**
 * Bulkhead double that is always full — every call throws
 * {@see BulkheadFullException}.
 */
final class FullBulkhead implements Bulkhead
{
    #[\Override]
    public function call(callable $callback): mixed
    {
        throw new BulkheadFullException('media-converter', 1);
    }

    #[\Override]
    public function availableSlots(): int
    {
        return 0;
    }
}
