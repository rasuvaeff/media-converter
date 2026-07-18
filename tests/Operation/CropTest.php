<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\Crop;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Crop::class)]
final class CropTest
{
    public function emitsCropFilterWithOffset(): void
    {
        $spec = new CommandSpec();
        (new Crop(640, 480, 10, 20))->applyTo($spec);

        Assert::same($spec->videoFilters(), ['crop=640:480:10:20']);
    }

    public function defaultsOffsetToOrigin(): void
    {
        $spec = new CommandSpec();
        (new Crop(320, 240))->applyTo($spec);

        Assert::same($spec->videoFilters(), ['crop=320:240:0:0']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonPositiveSize(): void
    {
        new Crop(0, 240);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAZeroHeight(): void
    {
        new Crop(320, 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeOffset(): void
    {
        new Crop(320, 240, -1, 0);
    }
}
