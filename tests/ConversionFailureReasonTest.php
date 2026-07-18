<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests;

use Rasuvaeff\MediaConverter\ConversionFailureReason;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ConversionFailureReason::class)]
final class ConversionFailureReasonTest
{
    #[DataProvider('reasonProvider')]
    public function eachReasonHasAStableWireValue(ConversionFailureReason $reason, string $value): void
    {
        Assert::same($reason->value, $value);
    }

    public static function reasonProvider(): iterable
    {
        yield 'timeout' => [ConversionFailureReason::Timeout, 'timeout'];
        yield 'non-zero' => [ConversionFailureReason::NonZeroExit, 'non-zero-exit'];
        yield 'no-input' => [ConversionFailureReason::NoInput, 'no-input'];
        yield 'drm' => [ConversionFailureReason::Drm, 'drm'];
        yield 'probe' => [ConversionFailureReason::ProbeFailed, 'probe-failed'];
        yield 'incompatible' => [ConversionFailureReason::IncompatibleOperations, 'incompatible-operations'];
        yield 'output' => [ConversionFailureReason::OutputFailed, 'output-failed'];
        yield 'ffmpeg-missing' => [ConversionFailureReason::FfmpegNotExecutable, 'ffmpeg-not-executable'];
        yield 'ffprobe-missing' => [ConversionFailureReason::FfprobeNotExecutable, 'ffprobe-not-executable'];
    }

    public function onlyNonZeroExitIsPotentiallyTransient(): void
    {
        $transient = array_filter(
            ConversionFailureReason::cases(),
            static fn(ConversionFailureReason $reason): bool => $reason->isPotentiallyTransient(),
        );

        Assert::same(array_values($transient), [ConversionFailureReason::NonZeroExit]);
    }
}
