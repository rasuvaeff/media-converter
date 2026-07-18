<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Progress;

use Rasuvaeff\Duration\Duration;

/**
 * One progress sample emitted while ffmpeg is running.
 *
 * {@see $fraction} is 0.0–1.0 when the total duration is known (from
 * ffprobe), or null when it is not — a stream-copy remux or a live input may
 * never yield a linear timeline, so the sample is INDETERMINATE rather than
 * reporting a fabricated percentage. Check {@see isDeterminate()} before
 * reading {@see fraction()}.
 *
 * @api
 */
final readonly class ProgressEvent
{
    public function __construct(
        private ?float $fraction,
        private Duration $outTime,
        private ?int $frame = null,
        private ?float $fps = null,
        private ?float $speed = null,
        private ConversionPhase $phase = ConversionPhase::Running,
    ) {
        if ($fraction !== null && ($fraction < 0.0 || $fraction > 1.0)) {
            throw new \InvalidArgumentException('Fraction must be within [0.0, 1.0]');
        }

        if ($frame !== null && $frame < 0) {
            throw new \InvalidArgumentException('Frame cannot be negative');
        }

        if ($fps !== null && $fps < 0.0) {
            throw new \InvalidArgumentException('Fps cannot be negative');
        }

        if ($speed !== null && $speed < 0.0) {
            throw new \InvalidArgumentException('Speed cannot be negative');
        }
    }

    /**
     * Whether a completion fraction is known for this sample.
     */
    public function isDeterminate(): bool
    {
        return $this->fraction !== null;
    }

    /**
     * Completion fraction in [0.0, 1.0], or null when indeterminate.
     */
    public function fraction(): ?float
    {
        return $this->fraction;
    }

    /**
     * How far into the output timeline ffmpeg currently is.
     */
    public function outTime(): Duration
    {
        return $this->outTime;
    }

    public function frame(): ?int
    {
        return $this->frame;
    }

    public function fps(): ?float
    {
        return $this->fps;
    }

    public function speed(): ?float
    {
        return $this->speed;
    }

    public function phase(): ConversionPhase
    {
        return $this->phase;
    }
}
