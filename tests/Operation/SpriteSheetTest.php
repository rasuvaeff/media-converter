<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\SpriteSheet;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SpriteSheet::class)]
final class SpriteSheetTest
{
    public function tileLayoutIsColumnsByRows(): void
    {
        $spec = new CommandSpec();
        (new SpriteSheet(rows: 5, cols: 4, interval: Duration::seconds(10)))->applyTo($spec);

        Assert::same($spec->videoFilters(), ['fps=1000000/10000000', 'tile=4x5']);
        Assert::same($spec->outputOptions(), ['-frames:v', '1', '-an']);
    }

    public function samplingRateIsAnExactRationalOfTheInterval(): void
    {
        $spec = new CommandSpec();
        (new SpriteSheet(rows: 1, cols: 1, interval: Duration::millis(1_500)))->applyTo($spec);

        Assert::same($spec->videoFilters()[0], 'fps=1000000/1500000');
    }

    public function addsAScaleFilterBeforeTileWhenTileWidthIsGiven(): void
    {
        $spec = new CommandSpec();
        (new SpriteSheet(rows: 2, cols: 3, interval: Duration::seconds(5), tileWidth: 160))->applyTo($spec);

        Assert::same($spec->videoFilters(), ['fps=1000000/5000000', 'scale=160:-2', 'tile=3x2']);
    }

    public function progressIsIndeterminate(): void
    {
        // The sheet samples a handful of frames — out_time never approaches
        // the input duration, so no percentage must be fabricated.
        $spec = new CommandSpec();
        (new SpriteSheet(rows: 2, cols: 2, interval: Duration::seconds(10)))->applyTo($spec);

        Assert::null($spec->outputDuration(Duration::seconds(60)));
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonPositiveRows(): void
    {
        new SpriteSheet(rows: 0, cols: 4, interval: Duration::seconds(10));
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonPositiveCols(): void
    {
        new SpriteSheet(rows: 4, cols: 0, interval: Duration::seconds(10));
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAZeroInterval(): void
    {
        new SpriteSheet(rows: 4, cols: 4, interval: Duration::zero());
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANonPositiveTileWidth(): void
    {
        new SpriteSheet(rows: 4, cols: 4, interval: Duration::seconds(10), tileWidth: -1);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAZeroTileWidth(): void
    {
        new SpriteSheet(rows: 4, cols: 4, interval: Duration::seconds(10), tileWidth: 0);
    }
}
