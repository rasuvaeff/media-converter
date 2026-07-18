<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\Progress\ConversionPhase;
use Rasuvaeff\MediaConverter\Progress\ProgressEvent;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ProgressEvent::class)]
final class ProgressEventTest
{
    public function determinateSampleExposesItsFraction(): void
    {
        $event = new ProgressEvent(
            fraction: 0.5,
            outTime: Duration::seconds(12.5),
            frame: 375,
            fps: 30.0,
            speed: 2.1,
        );

        Assert::true($event->isDeterminate());
        Assert::same($event->fraction(), 0.5);
        Assert::same($event->outTime()->toMillis(), 12_500);
        Assert::same($event->frame(), 375);
        Assert::same($event->fps(), 30.0);
        Assert::same($event->speed(), 2.1);
        Assert::same($event->phase(), ConversionPhase::Running);
    }

    public function indeterminateSampleHasNoFraction(): void
    {
        $event = new ProgressEvent(fraction: null, outTime: Duration::seconds(3));

        Assert::false($event->isDeterminate());
        Assert::null($event->fraction());
        Assert::same($event->outTime()->toMillis(), 3_000);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsFractionAboveOne(): void
    {
        new ProgressEvent(fraction: 1.5, outTime: Duration::zero());
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeFraction(): void
    {
        new ProgressEvent(fraction: -0.1, outTime: Duration::zero());
    }

    public function acceptsFractionBoundaries(): void
    {
        Assert::same((new ProgressEvent(fraction: 0.0, outTime: Duration::zero()))->fraction(), 0.0);
        Assert::same((new ProgressEvent(fraction: 1.0, outTime: Duration::zero()))->fraction(), 1.0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeFrame(): void
    {
        new ProgressEvent(fraction: null, outTime: Duration::zero(), frame: -1);
    }

    public function acceptsAZeroFrame(): void
    {
        $event = new ProgressEvent(fraction: null, outTime: Duration::zero(), frame: 0);

        Assert::same($event->frame(), 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeFps(): void
    {
        new ProgressEvent(fraction: null, outTime: Duration::zero(), fps: -1.0);
    }

    public function acceptsAZeroFps(): void
    {
        $event = new ProgressEvent(fraction: null, outTime: Duration::zero(), fps: 0.0);

        Assert::same($event->fps(), 0.0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeSpeed(): void
    {
        new ProgressEvent(fraction: null, outTime: Duration::zero(), speed: -1.0);
    }

    public function acceptsAZeroSpeed(): void
    {
        $event = new ProgressEvent(fraction: null, outTime: Duration::zero(), speed: 0.0);

        Assert::same($event->speed(), 0.0);
    }
}
