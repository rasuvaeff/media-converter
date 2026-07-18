<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\Thumbnail;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Thumbnail::class)]
final class ThumbnailTest
{
    public function emitsSeekSingleFrameAndNoAudio(): void
    {
        $spec = new CommandSpec();
        (new Thumbnail(Duration::seconds(12)))->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-ss', '12', '-frames:v', '1', '-an']);
        Assert::false($spec->hasFilters());
    }

    public function addsAScaleFilterWhenWidthIsGiven(): void
    {
        $spec = new CommandSpec();
        (new Thumbnail(Duration::seconds(1), width: 320))->applyTo($spec);

        Assert::same($spec->videoFilters(), ['scale=320:-2']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANegativeTimestamp(): void
    {
        new Thumbnail(Duration::micros(-1));
    }

    public function acceptsAZeroTimestamp(): void
    {
        $spec = new CommandSpec();
        (new Thumbnail(Duration::zero()))->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-ss', '0', '-frames:v', '1', '-an']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANonPositiveWidth(): void
    {
        new Thumbnail(Duration::seconds(1), width: 0);
    }
}
