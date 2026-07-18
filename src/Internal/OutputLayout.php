<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Internal;

/**
 * Describes whether one ffmpeg invocation produces one file or a package with
 * sidecar files.
 *
 * @internal
 */
final readonly class OutputLayout
{
    private const string SINGLE = 'single';

    private const string HLS = 'hls';

    private const string DASH = 'dash';

    private function __construct(
        private string $kind,
        private ?string $hlsSegmentPattern = null,
    ) {}

    public static function single(): self
    {
        return new self(self::SINGLE);
    }

    public static function hls(?string $segmentPattern): self
    {
        return new self(self::HLS, $segmentPattern);
    }

    public static function dash(): self
    {
        return new self(self::DASH);
    }

    public function isHls(): bool
    {
        return $this->kind === self::HLS;
    }

    public function isDash(): bool
    {
        return $this->kind === self::DASH;
    }

    public function isPackage(): bool
    {
        return $this->kind !== self::SINGLE;
    }

    public function hlsSegmentPattern(): ?string
    {
        return $this->hlsSegmentPattern;
    }
}
