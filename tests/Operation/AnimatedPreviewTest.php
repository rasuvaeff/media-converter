<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\AnimatedPreview;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(AnimatedPreview::class)]
final class AnimatedPreviewTest
{
    public function emitsTrimBoundsAndAPaletteOptimisedGraph(): void
    {
        $spec = new CommandSpec();
        (new AnimatedPreview(Duration::seconds(5), Duration::seconds(8), fps: 12, width: 240))->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-ss', '5', '-to', '8']);
        Assert::same(
            $spec->videoFilters(),
            ['fps=12,scale=240:-2,split[s0][s1];[s0]palettegen[p];[s1][p]paletteuse'],
        );
        Assert::true($spec->hasExclusiveVideoGraph());
    }

    public function usesTheDefaultFpsAndWidth(): void
    {
        $spec = new CommandSpec();
        (new AnimatedPreview(Duration::seconds(0), Duration::seconds(3)))->applyTo($spec);

        Assert::same(
            $spec->videoFilters(),
            ['fps=10,scale=320:-2,split[s0][s1];[s0]palettegen[p];[s1][p]paletteuse'],
        );
    }

    public function registersTheTrimRangeOnTheTimeline(): void
    {
        // The [from, to) range must reach the timeline so progress reporting
        // scales against the preview's duration, not the whole input's.
        $spec = new CommandSpec();
        (new AnimatedPreview(Duration::seconds(5), Duration::seconds(8)))->applyTo($spec);

        Assert::same($spec->outputDuration(Duration::seconds(60))?->toMicros(), 3_000_000);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANegativeStart(): void
    {
        new AnimatedPreview(Duration::micros(-1), Duration::seconds(1));
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsEndNotAfterStart(): void
    {
        new AnimatedPreview(Duration::seconds(5), Duration::seconds(5));
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANonPositiveFps(): void
    {
        new AnimatedPreview(Duration::seconds(0), Duration::seconds(1), fps: 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANonPositiveWidth(): void
    {
        new AnimatedPreview(Duration::seconds(0), Duration::seconds(1), width: 0);
    }
}
