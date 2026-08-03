<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests;

use Rasuvaeff\MediaConverter\ConversionFailed;
use Rasuvaeff\MediaConverter\ConversionFailureReason;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FfmpegBinary::class)]
final class FfmpegBinaryTest
{
    public function defaultPointsToStandardUnixPaths(): void
    {
        $binary = FfmpegBinary::default();

        Assert::same($binary->ffmpegPath(), '/usr/bin/ffmpeg');
        Assert::same($binary->ffprobePath(), '/usr/bin/ffprobe');
    }

    public function constructorPreservesCustomPaths(): void
    {
        $binary = new FfmpegBinary(
            ffmpegPath: '/opt/ffmpeg/bin/ffmpeg',
            ffprobePath: '/opt/ffmpeg/bin/ffprobe',
        );

        Assert::same($binary->ffmpegPath(), '/opt/ffmpeg/bin/ffmpeg');
        Assert::same($binary->ffprobePath(), '/opt/ffmpeg/bin/ffprobe');
    }

    public function defaultIsNewInstanceEqualToDefaultFactory(): void
    {
        $one = FfmpegBinary::default();
        $two = new FfmpegBinary();

        Assert::same($one->ffmpegPath(), $two->ffmpegPath());
        Assert::same($one->ffprobePath(), $two->ffprobePath());
    }

    #[DataProvider('emptyPathProvider')]
    public function rejectsEmptyPaths(string $ffmpeg, string $ffprobe, string $expectedMessage): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($expectedMessage);

        new FfmpegBinary(ffmpegPath: $ffmpeg, ffprobePath: $ffprobe);
    }

    public static function emptyPathProvider(): iterable
    {
        yield 'ffmpeg empty' => ['', '/usr/bin/ffprobe', 'ffmpeg path cannot be empty'];
        yield 'ffprobe empty' => ['/usr/bin/ffmpeg', '', 'ffprobe path cannot be empty'];
        yield 'both empty' => ['', '', 'ffmpeg path cannot be empty'];
    }

    public function assertExecutableThrowsWhenFfmpegMissing(): void
    {
        $binary = new FfmpegBinary(
            ffmpegPath: '/definitely/not/installed/ffmpeg-' . \uniqid('', true),
            ffprobePath: '/usr/bin/ffprobe',
        );

        try {
            $binary->assertExecutable();
            Assert::true(false, 'Expected ConversionFailed');
        } catch (ConversionFailed $e) {
            Assert::same($e->reason, ConversionFailureReason::FfmpegNotExecutable);
            Assert::same($e->exitCode, 127);
            Assert::string($e->stderrTail())->contains('ffmpeg-');
        }
    }

    public function ffmpegOnlyPreflightDoesNotRequireFfprobe(): void
    {
        $binary = new FfmpegBinary(PHP_BINARY, '/definitely/missing/ffprobe');

        $binary->assertFfmpegExecutable();

        Assert::same($binary->ffmpegPath(), PHP_BINARY);
    }

    public function assertExecutableThrowsWhenFfprobeMissing(): void
    {
        $binary = new FfmpegBinary(
            ffmpegPath: PHP_BINARY,
            ffprobePath: '/definitely/not/installed/ffprobe-' . \uniqid('', true),
        );

        try {
            $binary->assertExecutable();
            Assert::true(false, 'Expected ConversionFailed');
        } catch (ConversionFailed $e) {
            Assert::same($e->reason, ConversionFailureReason::FfprobeNotExecutable);
            Assert::same($e->exitCode, 127);
        }
    }

    public function ffprobeOnlyPreflightDoesNotRequireFfmpeg(): void
    {
        $binary = new FfmpegBinary('/definitely/missing/ffmpeg', PHP_BINARY);

        $binary->assertFfprobeExecutable();

        Assert::same($binary->ffprobePath(), PHP_BINARY);
    }

    public function assertExecutablePassesForValidBinaries(): void
    {
        $binary = new FfmpegBinary(
            ffmpegPath: PHP_BINARY,
            ffprobePath: PHP_BINARY,
        );

        $binary->assertExecutable();

        Assert::true(true);
    }

    public function assertExecutableThrowsForAnExistingNonExecutableFfmpegFile(): void
    {
        // A file that EXISTS but is NOT executable isolates the `||` from an
        // `&&` mutation: both are true only when the path is missing
        // entirely (covered above), where OR and AND happen to agree.
        $path = (string) \tempnam(\sys_get_temp_dir(), 'mc-not-exec-');
        \chmod($path, 0o644);

        try {
            $binary = new FfmpegBinary(ffmpegPath: $path, ffprobePath: PHP_BINARY);

            try {
                $binary->assertExecutable();
                Assert::true(false, 'Expected ConversionFailed');
            } catch (ConversionFailed $e) {
                Assert::same($e->reason, ConversionFailureReason::FfmpegNotExecutable);
            }
        } finally {
            @\unlink($path);
        }
    }

    public function assertExecutableThrowsForAnExistingNonExecutableFfprobeFile(): void
    {
        $path = (string) \tempnam(\sys_get_temp_dir(), 'mc-not-exec-');
        \chmod($path, 0o644);

        try {
            $binary = new FfmpegBinary(ffmpegPath: PHP_BINARY, ffprobePath: $path);

            try {
                $binary->assertExecutable();
                Assert::true(false, 'Expected ConversionFailed');
            } catch (ConversionFailed $e) {
                Assert::same($e->reason, ConversionFailureReason::FfprobeNotExecutable);
            }
        } finally {
            @\unlink($path);
        }
    }
}
