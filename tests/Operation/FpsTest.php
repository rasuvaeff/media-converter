<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\Fps;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Fps::class)]
final class FpsTest
{
    public function emitsFpsFilter(): void
    {
        $spec = new CommandSpec();
        (new Fps(30))->applyTo($spec);

        Assert::same($spec->videoFilters(), ['fps=30']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonPositiveFps(): void
    {
        new Fps(0);
    }
}
