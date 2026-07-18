<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests;

use Rasuvaeff\MediaConverter\ProcessOutcome;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ProcessOutcome::class)]
final class ProcessOutcomeTest
{
    public function zeroExitWithoutTimeoutIsSuccess(): void
    {
        $outcome = new ProcessOutcome(exitCode: 0, stderrTail: '');

        Assert::true($outcome->isSuccess());
        Assert::same($outcome->exitCode, 0);
        Assert::false($outcome->timedOut);
    }

    public function nonZeroExitIsNotSuccess(): void
    {
        Assert::false((new ProcessOutcome(1, 'boom'))->isSuccess());
    }

    public function timeoutIsNotSuccessEvenWithZeroExit(): void
    {
        $outcome = new ProcessOutcome(exitCode: 0, stderrTail: '', timedOut: true);

        Assert::false($outcome->isSuccess());
        Assert::true($outcome->timedOut);
    }
}
