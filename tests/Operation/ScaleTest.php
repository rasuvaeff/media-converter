<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\Scale;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Scale::class)]
final class ScaleTest
{
    public function keepsAspectForTheOmittedDimension(): void
    {
        $spec = new CommandSpec();
        (new Scale(width: 1280))->applyTo($spec);

        Assert::same($spec->videoFilters(), ['scale=1280:-2']);
    }

    public function usesBothDimensionsWhenGiven(): void
    {
        $spec = new CommandSpec();
        (new Scale(640, 480))->applyTo($spec);

        Assert::same($spec->videoFilters(), ['scale=640:480']);
    }

    public function heightOnlyKeepsAspectForWidth(): void
    {
        $spec = new CommandSpec();
        (new Scale(height: 720))->applyTo($spec);

        Assert::same($spec->videoFilters(), ['scale=-2:720']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsBothNull(): void
    {
        new Scale();
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonPositiveWidth(): void
    {
        new Scale(width: 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAZeroHeight(): void
    {
        new Scale(width: 100, height: 0);
    }
}
