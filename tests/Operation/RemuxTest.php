<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\Remux;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Remux::class)]
final class RemuxTest
{
    public function requestsStreamCopyAndAddsNoFilter(): void
    {
        $spec = new CommandSpec();
        (new Remux())->applyTo($spec);

        Assert::true($spec->isStreamCopy());
        Assert::false($spec->hasFilters());
        Assert::same($spec->outputOptions(), []);
    }
}
