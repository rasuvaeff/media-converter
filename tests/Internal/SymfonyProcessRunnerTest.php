<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Internal;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\Internal\SymfonyProcessRunner;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SymfonyProcessRunner::class)]
final class SymfonyProcessRunnerTest
{
    public function capturesExitCodeAndStderrTailAndSplitsStreams(): void
    {
        $runner = new SymfonyProcessRunner();
        /** @var array<string, string> $seen */
        $seen = ['out' => '', 'err' => ''];

        $outcome = $runner->run(
            ['php', '-r', 'fwrite(STDOUT, "hello"); fwrite(STDERR, "oops"); exit(0);'],
            Duration::seconds(10),
            Duration::seconds(10),
            static function (string $type, string $chunk) use (&$seen): void {
                $seen[$type] .= $chunk;
            },
        );

        Assert::true($outcome->isSuccess());
        Assert::same($outcome->exitCode, 0);
        Assert::same($outcome->stderrTail, 'oops');
        // The stderr tail carries only stderr — stdout ('hello') never leaks in.
        Assert::same($seen['out'], 'hello');
        Assert::same($seen['err'], 'oops');
    }

    public function reportsANonZeroExitCode(): void
    {
        $outcome = (new SymfonyProcessRunner())->run(
            ['php', '-r', 'fwrite(STDERR, "boom"); exit(3);'],
            Duration::seconds(10),
            Duration::seconds(10),
            static fn(string $type, string $chunk): null => null,
        );

        Assert::false($outcome->isSuccess());
        Assert::same($outcome->exitCode, 3);
        Assert::same($outcome->stderrTail, 'boom');
    }

    public function killsAndFlagsAProcessThatExceedsTheWallClockTimeout(): void
    {
        $outcome = (new SymfonyProcessRunner())->run(
            ['php', '-r', 'sleep(5);'],
            Duration::millis(150),
            Duration::seconds(10),
            static fn(string $type, string $chunk): null => null,
        );

        Assert::true($outcome->timedOut);
        Assert::false($outcome->isSuccess());
    }

    public function killsAndFlagsAProcessThatExceedsTheIdleTimeout(): void
    {
        // A LONG wall-clock timeout paired with a SHORT idle timeout: only
        // an idle-timeout kill (no output for idleTimeout, distinct from
        // total wall-clock time) can end this run quickly.
        $outcome = (new SymfonyProcessRunner())->run(
            ['php', '-r', 'sleep(2);'],
            Duration::seconds(10),
            Duration::millis(300),
            static fn(string $type, string $chunk): null => null,
        );

        Assert::true($outcome->timedOut);
        Assert::false($outcome->isSuccess());
    }

    public function killsTheProcessWhenTheOutputCallbackThrows(): void
    {
        // Cooperative cancellation unwinds run() via a callback exception —
        // the still-running subprocess must be stopped, not left to finish.
        $runner = new SymfonyProcessRunner();
        $startedAt = microtime(true);
        $caught = null;

        try {
            $runner->run(
                ['php', '-r', 'fwrite(STDOUT, "go\n"); sleep(30);'],
                Duration::seconds(60),
                Duration::zero(),
                static function (): void {
                    throw new \RuntimeException('cancelled');
                },
            );
        } catch (\RuntimeException $caught) {
        }

        Assert::notNull($caught);
        Assert::true(microtime(true) - $startedAt < 10.0);
    }

    public function stopsTheChildBeforeTheCallbackExceptionLeavesRun(): void
    {
        // The finally block stops the still-running child BEFORE the callback
        // exception leaves run() — the Process destructor does NOT reliably
        // fire at unwind time (verified: without the explicit stop the child
        // outlives the catch). The surrogate is a SINGLE directly-exec'd
        // process (like real ffmpeg): it prints, then blocks — no shell, no
        // grandchild — so symfony's tracked PID is exactly the process
        // stop(0) SIGKILLs. A unique marker argv makes survival observable.
        $marker = sprintf('mediaconv_%d', random_int(0, PHP_INT_MAX));
        $runner = new SymfonyProcessRunner();
        $caught = null;

        try {
            $runner->run(
                ['php', '-r', 'fwrite(STDOUT, "go\n"); sleep(30);', $marker],
                Duration::seconds(60),
                Duration::zero(),
                static function (): void {
                    throw new \RuntimeException('cancelled');
                },
            );
        } catch (\RuntimeException $caught) {
        }

        // SIGKILL/TerminateProcess delivery is asynchronous — poll briefly
        // instead of racing it; a leaked child blocks for ~30s, far beyond
        // the poll window.
        $alive = true;

        foreach (range(1, 40) as $_attempt) {
            $alive = self::isProcessAlive($marker);

            if (!$alive) {
                break;
            }

            usleep(50_000);
        }

        self::killProcessByMarker($marker);
        Assert::notNull($caught);
        Assert::false($alive);
    }

    private static function isProcessAlive(string $marker): bool
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $output = (string) shell_exec(sprintf(
                'powershell -NoProfile -Command "Get-CimInstance Win32_Process | Where-Object { $_.Name -eq \'php.exe\' -and $_.CommandLine -like \'*%s*\' } | Select-Object -ExpandProperty ProcessId"',
                $marker,
            ));

            return trim($output) !== '';
        }

        // The `[m]arker` bracket keeps pgrep's own `sh -c` wrapper (whose
        // cmdline also holds the marker) from self-matching.
        $pattern = sprintf('[%s]%s', $marker[0], substr($marker, 1));

        return trim((string) shell_exec(sprintf('pgrep -f "%s"', $pattern))) !== '';
    }

    private static function killProcessByMarker(string $marker): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            shell_exec(sprintf(
                'powershell -NoProfile -Command "Get-CimInstance Win32_Process | Where-Object { $_.Name -eq \'php.exe\' -and $_.CommandLine -like \'*%s*\' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }"',
                $marker,
            ));

            return;
        }

        $pattern = sprintf('[%s]%s', $marker[0], substr($marker, 1));
        shell_exec(sprintf('pkill -9 -f "%s" 2>/dev/null', $pattern));
    }

    public function killsATermIgnoringProcessWithoutAGracePeriodWhenTheCallbackThrows(): void
    {
        // stop(0) means NO graceful-termination grace period: SIGTERM, then
        // an immediate SIGKILL. A process that ignores SIGTERM dies only via
        // that SIGKILL, so any non-zero grace period (e.g. stop(1)) would
        // stall the unwind for the full period — observable as elapsed time
        // around run() with a wide margin (~1s mutant vs ~0.05s original).
        //
        // No Windows equivalent: Symfony\Process::stop() on Windows always
        // force-kills via taskkill (TerminateProcess), which cannot be
        // trapped/ignored the way SIGTERM can — there is no "ignores the
        // termination signal" scenario to construct there.
        if (\PHP_OS_FAMILY === 'Windows') {
            return;
        }

        $runner = new SymfonyProcessRunner();
        $caught = null;
        $startedAt = microtime(true);

        try {
            $runner->run(
                ['/bin/sh', '-c', "trap '' TERM; echo go; sleep 5"],
                Duration::seconds(60),
                Duration::zero(),
                static function (): void {
                    throw new \RuntimeException('cancelled');
                },
            );
        } catch (\RuntimeException $caught) {
        }

        Assert::notNull($caught);
        Assert::true(microtime(true) - $startedAt < 0.9);
    }

    public function accumulatesStderrChunksInWriteOrderAndTruncatesFromTheFront(): void
    {
        // Two stderr writes, separated by a sleep to force two distinct
        // onOutput callbacks (verified: this reliably yields two chunks, not
        // one merged read). 100 + 8150 = 8250 bytes exceeds the 8192-byte
        // tail, so the correct result both preserves write ORDER ('A's
        // before 'B's) and drops from the FRONT (58 'A's), landing on 42
        // 'A's followed by all 8150 'B's — a value no single-operand or
        // swapped-concat mutation can coincidentally reproduce.
        $outcome = (new SymfonyProcessRunner())->run(
            [
                'php',
                '-r',
                'fwrite(STDERR, str_repeat("A", 100)); usleep(200_000); fwrite(STDERR, str_repeat("B", 8_150));',
            ],
            Duration::seconds(10),
            Duration::seconds(10),
            static fn(string $type, string $chunk): null => null,
        );

        Assert::same($outcome->stderrTail, str_repeat('A', 42) . str_repeat('B', 8_150));
    }
}
