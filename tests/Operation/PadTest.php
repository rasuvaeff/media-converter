<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\Pad;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Pad::class)]
final class PadTest
{
    public function emitsPadFilterWithColor(): void
    {
        $spec = new CommandSpec();
        (new Pad(1920, 1080, 0, 140, 'black'))->applyTo($spec);

        Assert::same($spec->videoFilters(), ['pad=1920:1080:0:140:black']);
    }

    public function defaultsToBlackAtOrigin(): void
    {
        $spec = new CommandSpec();
        (new Pad(640, 480))->applyTo($spec);

        Assert::same($spec->videoFilters(), ['pad=640:480:0:0:black']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonPositiveSize(): void
    {
        new Pad(0, 480);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAZeroHeight(): void
    {
        new Pad(640, 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANegativeXOffsetAlone(): void
    {
        new Pad(640, 480, -1, 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANegativeYOffsetAlone(): void
    {
        new Pad(640, 480, 0, -1);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsEmptyColor(): void
    {
        new Pad(640, 480, 0, 0, '');
    }
}
