<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Internal;

use Rasuvaeff\MediaConverter\Internal\ProgressReportParser;
use Rasuvaeff\MediaConverter\Internal\ProgressSample;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ProgressReportParser::class)]
#[Covers(ProgressSample::class)]
final class ProgressReportParserTest
{
    public function parsesACompleteReportInOneChunk(): void
    {
        $samples = (new ProgressReportParser())->feed(
            "frame=100\nfps=25.0\nout_time_us=5000000\nspeed=1.5x\nprogress=continue\n",
        );

        Assert::same(count($samples), 1);
        Assert::same($samples[0]->frame, 100);
        Assert::same($samples[0]->fps, 25.0);
        Assert::same($samples[0]->outTime->toMicros(), 5_000_000);
        Assert::same($samples[0]->speed, 1.5);
    }

    public function buffersAPartialLineAcrossFeedCalls(): void
    {
        $parser = new ProgressReportParser();

        Assert::same($parser->feed("frame=1\nout_time_us=1000000\nspe"), []);
        $samples = $parser->feed("ed=2.0x\nprogress=continue\n");

        Assert::same(count($samples), 1);
        Assert::same($samples[0]->frame, 1);
        Assert::same($samples[0]->speed, 2.0);
    }

    public function parsesMultipleReportsDeliveredInOneChunk(): void
    {
        $samples = (new ProgressReportParser())->feed(
            "out_time_us=1000000\nprogress=continue\n"
            . "out_time_us=2000000\nprogress=continue\n"
            . "out_time_us=3000000\nprogress=end\n",
        );

        Assert::same(count($samples), 3);
        Assert::same(array_map(static fn($s): int => $s->outTime->toMicros(), $samples), [1_000_000, 2_000_000, 3_000_000]);
    }

    public function eachReportStartsWithAnEmptyFieldSet(): void
    {
        // A field NOT resent in the second report must not leak from the first.
        $samples = (new ProgressReportParser())->feed(
            "frame=10\nout_time_us=1000000\nprogress=continue\n"
            . "out_time_us=2000000\nprogress=continue\n",
        );

        Assert::same($samples[0]->frame, 10);
        Assert::null($samples[1]->frame);
    }

    public function unavailableSpeedIsNull(): void
    {
        $samples = (new ProgressReportParser())->feed("out_time_us=0\nspeed=N/A\nprogress=continue\n");

        Assert::null($samples[0]->speed);
    }

    public function missingFieldsAreNull(): void
    {
        $samples = (new ProgressReportParser())->feed("progress=continue\n");

        Assert::same($samples[0]->outTime->toMicros(), 0);
        Assert::null($samples[0]->frame);
        Assert::null($samples[0]->fps);
        Assert::null($samples[0]->speed);
    }

    public function fallsBackToOutTimeMsWhenOutTimeUsIsAbsent(): void
    {
        $samples = (new ProgressReportParser())->feed("out_time_ms=2500\nprogress=continue\n");

        Assert::same($samples[0]->outTime->toMicros(), 2_500);
    }

    public function outTimeUsTakesPrecedenceOverOutTimeMs(): void
    {
        // Versions emitting both give them identical (microsecond) values;
        // the modern out_time_us must win when they ever disagree.
        $samples = (new ProgressReportParser())->feed("out_time_us=5000000\nout_time_ms=1\nprogress=continue\n");

        Assert::same($samples[0]->outTime->toMicros(), 5_000_000);
    }

    public function frameZeroIsReportedAsZeroNotNull(): void
    {
        // The very first report of a run carries frame=0 — a valid count,
        // distinct from a missing or negative field.
        $samples = (new ProgressReportParser())->feed("frame=0\nout_time_us=0\nprogress=continue\n");

        Assert::same($samples[0]->frame, 0);
    }

    public function malformedLinesWithoutAnEqualsSignAreIgnored(): void
    {
        $samples = (new ProgressReportParser())->feed("garbage-line-no-equals\nout_time_us=1000\nprogress=continue\n");

        Assert::same(count($samples), 1);
        Assert::same($samples[0]->outTime->toMicros(), 1_000);
    }

    public function incompleteFinalReportWithoutATrailingNewlineYieldsNoSample(): void
    {
        $samples = (new ProgressReportParser())->feed("out_time_us=1000\nprogress=contin");

        Assert::same($samples, []);
    }

    public function valuesAreTrimmedOfSurroundingWhitespace(): void
    {
        // Without trimming, the digit-only regex in intField() would reject
        // "100 " outright, so this also guards the anchored-regex behaviour.
        $samples = (new ProgressReportParser())->feed("frame= 100 \nprogress=continue\n");

        Assert::same($samples[0]->frame, 100);
    }

    public function outTimeUsWithATrailingNonDigitTailIsRejected(): void
    {
        $samples = (new ProgressReportParser())->feed("out_time_us=1000x\nprogress=continue\n");

        Assert::same($samples[0]->outTime->toMicros(), 0);
    }

    public function outTimeUsWithALeadingNonDigitIsRejected(): void
    {
        $samples = (new ProgressReportParser())->feed("out_time_us=x1000\nprogress=continue\n");

        Assert::same($samples[0]->outTime->toMicros(), 0);
    }

    public function outTimeUsWithDigitsInterruptedByAMidStringLetterIsRejected(): void
    {
        // "45x00" has trailing digits (satisfies an end-anchored-only regex)
        // but is not a clean digit string; the (int) cast of "45x00" -> 45
        // is what would otherwise mask a caret-less regex from a
        // leading-garbage test alone (no fallback field is set, so a wrongly
        // accepted match would surface as a nonzero out-time).
        $samples = (new ProgressReportParser())->feed("out_time_us=45x00\nprogress=continue\n");

        Assert::same($samples[0]->outTime->toMicros(), 0);
    }

    public function fpsWithANonNumericValueIsNull(): void
    {
        $samples = (new ProgressReportParser())->feed("fps=not-a-number\nprogress=continue\n");

        Assert::null($samples[0]->fps);
    }

    public function negativeOutTimeSentinelIsClampedToZero(): void
    {
        // ffmpeg emits INT64_MIN before the first timestamp is muxed —
        // typically the very first report block of a real run.
        $samples = (new ProgressReportParser())->feed("out_time_us=-9223372036854775808\nprogress=continue\n");

        Assert::same(count($samples), 1);
        Assert::same($samples[0]->outTime->toMicros(), 0);
    }

    public function smallNegativeOutTimeIsClampedToZero(): void
    {
        // Inputs with a negative start_time (MP4 audio priming) report small
        // negative out_time values early in the run.
        $samples = (new ProgressReportParser())->feed("out_time_us=-23220\nprogress=continue\n");

        Assert::same($samples[0]->outTime->toMicros(), 0);
    }

    public function negativeFrameIsReportedAsNull(): void
    {
        $samples = (new ProgressReportParser())->feed("frame=-1\nout_time_us=0\nprogress=continue\n");

        Assert::null($samples[0]->frame);
    }
}
