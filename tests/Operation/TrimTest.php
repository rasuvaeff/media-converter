<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\Trim;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Trim::class)]
final class TrimTest
{
    public function emitsAccurateSeekAndEndAsOutputOptions(): void
    {
        $spec = new CommandSpec();
        (new Trim(Duration::seconds(30), Duration::seconds(90)))->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-ss', '30', '-to', '90']);
        Assert::false($spec->hasFilters());
    }

    public function emitsOnlySeekWhenEndIsOmitted(): void
    {
        $spec = new CommandSpec();
        (new Trim(Duration::millis(1_500)))->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-ss', '1.5']);
    }

    public function acceptsAZeroStart(): void
    {
        $spec = new CommandSpec();
        (new Trim(Duration::zero()))->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-ss', '0']);
    }

    public function claimsTheOutputSeek(): void
    {
        // Two output-side -ss owners would silently last-write-wins in ffmpeg;
        // the claim lets Pipeline reject a second one.
        $spec = new CommandSpec();
        (new Trim(Duration::seconds(1)))->applyTo($spec);

        Assert::same($spec->seekOwners(), [Trim::class]);
    }

    public function fastSeekEmitsTheSeekAsAPrimaryInputOption(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('in.mp4');
        (new Trim(Duration::seconds(30), Duration::seconds(90), fastSeek: true))->applyTo($spec);

        Assert::same($spec->inputs(), [['source' => 'in.mp4', 'options' => ['-ss', '30']]]);
        // Input seeking resets output timestamps, so the end bound becomes a
        // duration (-t to-from), preserving the same [from, to) slice.
        Assert::same($spec->outputOptions(), ['-t', '60']);
        Assert::same($spec->primaryInputOptionOwners(), [Trim::class]);
    }

    public function fastSeekWithoutAnEndEmitsNoOutputOption(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('in.mp4');
        (new Trim(Duration::seconds(30), fastSeek: true))->applyTo($spec);

        Assert::same($spec->inputs()[0]['options'], ['-ss', '30']);
        Assert::same($spec->outputOptions(), []);
    }

    public function fastSeekKeepsMicrosecondPrecision(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('in.mp4');
        (new Trim(Duration::millis(1_500), Duration::millis(4_750), fastSeek: true))->applyTo($spec);

        Assert::same($spec->inputs()[0]['options'], ['-ss', '1.5']);
        Assert::same($spec->outputOptions(), ['-t', '3.25']);
    }

    public function fastSeekStillClaimsTheSeekAndTrimsTheProgressTimeline(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('in.mp4');
        (new Trim(Duration::seconds(30), Duration::seconds(90), fastSeek: true))->applyTo($spec);

        Assert::same($spec->seekOwners(), [Trim::class]);
        Assert::same($spec->outputDuration(Duration::seconds(600))?->toMicros(), Duration::seconds(60)->toMicros());
    }

    #[ExpectException(\LogicException::class)]
    public function fastSeekWithoutAPrimaryInputIsALogicError(): void
    {
        (new Trim(Duration::seconds(1), fastSeek: true))->applyTo(new CommandSpec());
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsEndBeforeStart(): void
    {
        new Trim(Duration::seconds(10), Duration::seconds(5));
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsEndEqualToStart(): void
    {
        new Trim(Duration::seconds(10), Duration::seconds(10));
    }
}
