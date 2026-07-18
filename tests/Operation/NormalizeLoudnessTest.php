<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\NormalizeLoudness;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(NormalizeLoudness::class)]
final class NormalizeLoudnessTest
{
    public function usesTheDefaultEbuR128Targets(): void
    {
        $spec = new CommandSpec();
        (new NormalizeLoudness())->applyTo($spec);

        Assert::same($spec->audioFilters(), ['loudnorm=I=-16:TP=-1.5:LRA=11']);
        Assert::false($spec->hasExclusiveVideoGraph());
    }

    public function usesCustomTargets(): void
    {
        $spec = new CommandSpec();
        (new NormalizeLoudness(integratedLufs: -23.0, truePeakDbtp: -2.0, loudnessRangeLu: 7.0))->applyTo($spec);

        Assert::same($spec->audioFilters(), ['loudnorm=I=-23:TP=-2:LRA=7']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnIntegratedLoudnessBelowRange(): void
    {
        new NormalizeLoudness(integratedLufs: -70.1);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnIntegratedLoudnessAboveRange(): void
    {
        new NormalizeLoudness(integratedLufs: -4.9);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsATruePeakBelowRange(): void
    {
        new NormalizeLoudness(truePeakDbtp: -9.1);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsATruePeakAboveRange(): void
    {
        new NormalizeLoudness(truePeakDbtp: 0.1);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsALoudnessRangeBelowRange(): void
    {
        new NormalizeLoudness(loudnessRangeLu: 0.9);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsALoudnessRangeAboveRange(): void
    {
        new NormalizeLoudness(loudnessRangeLu: 50.1);
    }

    public function acceptsTheBoundaryValues(): void
    {
        $spec = new CommandSpec();
        (new NormalizeLoudness(integratedLufs: -70.0, truePeakDbtp: -9.0, loudnessRangeLu: 1.0))->applyTo($spec);

        Assert::same($spec->audioFilters(), ['loudnorm=I=-70:TP=-9:LRA=1']);

        $spec2 = new CommandSpec();
        (new NormalizeLoudness(integratedLufs: -5.0, truePeakDbtp: 0.0, loudnessRangeLu: 50.0))->applyTo($spec2);

        Assert::same($spec2->audioFilters(), ['loudnorm=I=-5:TP=0:LRA=50']);
    }
}
