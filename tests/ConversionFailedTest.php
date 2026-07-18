<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests;

use Rasuvaeff\MediaConverter\ConversionFailed;
use Rasuvaeff\MediaConverter\ConversionFailureReason;
use Rasuvaeff\MediaConverter\MediaConverterException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ConversionFailed::class)]
final class ConversionFailedTest
{
    public function exposesReasonExitCodeStderrAndPrevious(): void
    {
        $previous = new \RuntimeException('underlying');
        $error = new ConversionFailed(
            reason: ConversionFailureReason::NonZeroExit,
            exitCode: 1,
            stderr: 'Segment not found',
            previous: $previous,
        );

        Assert::same($error->reason, ConversionFailureReason::NonZeroExit);
        Assert::same($error->exitCode, 1);
        Assert::same($error->stderrTail(), 'Segment not found');
        Assert::same($error->getPrevious(), $previous);
        Assert::same($error->getCode(), 0);
    }

    public function isARuntimeExceptionSoRetryAndCatchMatchOnType(): void
    {
        $error = new ConversionFailed(ConversionFailureReason::Timeout, 124, 'timed out');

        Assert::instanceOf($error, \RuntimeException::class);
    }

    public function messageNamesTheReasonAndExitCode(): void
    {
        $error = new ConversionFailed(ConversionFailureReason::Drm, 1, 'encrypted');

        Assert::string($error->getMessage())->contains('drm');
        Assert::string($error->getMessage())->contains('exit=1');
    }

    public function emptyStderrRendersAsPlaceholder(): void
    {
        $error = new ConversionFailed(ConversionFailureReason::NoInput, 1, '');

        Assert::same($error->stderrTail(), '(empty)');
    }

    public function implementsTheMediaConverterExceptionMarker(): void
    {
        $error = new ConversionFailed(ConversionFailureReason::NonZeroExit, 1, 'boom');

        Assert::instanceOf($error, MediaConverterException::class);
    }
}
