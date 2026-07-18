<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Internal;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\ProcessOutcome;
use Rasuvaeff\MediaConverter\RunsProcess;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * {@see RunsProcess} over `symfony/process`. Enforces a wall-clock and an
 * idle timeout, forwards every output chunk to the caller with an
 * `'out'`/`'err'` discriminator, and keeps a bounded tail of STDERR only for
 * error classification.
 *
 * @internal
 */
final readonly class SymfonyProcessRunner implements RunsProcess
{
    /** Conventional exit code for a process killed by a timeout. */
    private const int TIMEOUT_EXIT_CODE = 124;

    private const int STDERR_TAIL_BYTES = 8192;

    #[\Override]
    public function run(array $argv, Duration $timeout, Duration $idleTimeout, callable $onOutput): ProcessOutcome
    {
        $process = new Process($argv);
        $process->setTimeout($timeout->isPositive() ? $timeout->toSeconds() : null);
        $process->setIdleTimeout($idleTimeout->isPositive() ? $idleTimeout->toSeconds() : null);

        $stderrTail = '';
        $capture = static function (string $type, string $chunk) use (&$stderrTail, $onOutput): void {
            if ($type === Process::ERR) {
                $stderrTail = substr($stderrTail . $chunk, -self::STDERR_TAIL_BYTES);
                $onOutput('err', $chunk);

                return;
            }

            $onOutput('out', $chunk);
        };

        try {
            $exitCode = $process->run($capture);
        } catch (ProcessTimedOutException) {
            return new ProcessOutcome(
                exitCode: $process->getExitCode() ?? self::TIMEOUT_EXIT_CODE,
                stderrTail: $stderrTail,
                timedOut: true,
            );
        } finally {
            // A callback exception (e.g. cooperative cancellation) unwinds
            // run() while ffmpeg is still alive — kill it explicitly instead
            // of relying on the Process destructor.
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }

        return new ProcessOutcome(exitCode: $exitCode, stderrTail: $stderrTail);
    }
}
