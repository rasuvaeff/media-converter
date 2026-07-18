<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

/**
 * Locations of the ffmpeg and ffprobe binaries used by the converter.
 *
 * The paths are stored verbatim and never executed at construction time.
 * Call {@see assertExecutable()}, {@see assertFfmpegExecutable()}, or
 * {@see assertFfprobeExecutable()} when a missing binary should fail fast
 * rather than surface as a subprocess error.
 *
 * @api
 */
final readonly class FfmpegBinary
{
    public function __construct(
        private string $ffmpegPath = '/usr/bin/ffmpeg',
        private string $ffprobePath = '/usr/bin/ffprobe',
    ) {
        if ($ffmpegPath === '') {
            throw new \InvalidArgumentException('ffmpeg path cannot be empty');
        }

        if ($ffprobePath === '') {
            throw new \InvalidArgumentException('ffprobe path cannot be empty');
        }
    }

    public static function default(): self
    {
        return new self();
    }

    public function ffmpegPath(): string
    {
        return $this->ffmpegPath;
    }

    public function ffprobePath(): string
    {
        return $this->ffprobePath;
    }

    /**
     * @throws ConversionFailed if either binary is missing or not executable
     */
    public function assertExecutable(): void
    {
        $this->assertFfmpegExecutable();
        $this->assertFfprobeExecutable();
    }

    public function assertFfmpegExecutable(): void
    {
        if (!is_file($this->ffmpegPath) || !is_executable($this->ffmpegPath)) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::FfmpegNotExecutable,
                exitCode: 127,
                stderr: $this->ffmpegPath,
            );
        }
    }

    public function assertFfprobeExecutable(): void
    {
        if (!is_file($this->ffprobePath) || !is_executable($this->ffprobePath)) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::FfprobeNotExecutable,
                exitCode: 127,
                stderr: $this->ffprobePath,
            );
        }
    }
}
