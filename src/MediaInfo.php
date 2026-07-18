<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

use Rasuvaeff\Duration\Duration;

/**
 * Metadata extracted from a source by {@see ProbesMedia::probe()}.
 *
 * @api
 */
final readonly class MediaInfo
{
    public function __construct(
        private Duration $duration,
        private ?int $width,
        private ?int $height,
        private ?string $videoCodec,
        private ?string $audioCodec,
        private ?int $bitrate,
        private bool $encrypted = false,
    ) {
        if ($width !== null && $width <= 0) {
            throw new \InvalidArgumentException('Width must be positive');
        }

        if ($height !== null && $height <= 0) {
            throw new \InvalidArgumentException('Height must be positive');
        }

        if ($bitrate !== null && $bitrate <= 0) {
            throw new \InvalidArgumentException('Bitrate must be positive');
        }
    }

    public function duration(): Duration
    {
        return $this->duration;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function height(): ?int
    {
        return $this->height;
    }

    public function videoCodec(): ?string
    {
        return $this->videoCodec;
    }

    public function audioCodec(): ?string
    {
        return $this->audioCodec;
    }

    public function bitrate(): ?int
    {
        return $this->bitrate;
    }

    public function hasVideo(): bool
    {
        return $this->videoCodec !== null;
    }

    public function hasAudio(): bool
    {
        return $this->audioCodec !== null;
    }

    /**
     * Whether ffprobe reported the source as DRM-protected (encrypted).
     * Operations over an encrypted source fail with
     * {@see ConversionFailureReason::Drm}.
     */
    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }
}
