<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * A `cols x rows` contact sheet, sampling one frame every `$interval` and
 * tiling them (`fps`, optional resize, `tile`). `-frames:v 1` caps the output
 * to exactly one sheet from the first `rows * cols` sampled frames — without
 * it the `tile` filter would start a second sheet once it fills the grid.
 *
 * The sampling rate is expressed as the exact rational `1_000_000 /
 * $interval->toMicros()` rather than a decimal, so no interval is lossy to
 * ffmpeg's `fps` filter regardless of how many microseconds it holds.
 *
 * @api
 */
final readonly class SpriteSheet implements OperationInterface
{
    public function __construct(
        private int $rows,
        private int $cols,
        private Duration $interval,
        private ?int $tileWidth = null,
    ) {
        if ($rows <= 0 || $cols <= 0) {
            throw new \InvalidArgumentException('SpriteSheet rows and columns must be positive');
        }

        if ($interval->toMicros() <= 0) {
            throw new \InvalidArgumentException('SpriteSheet interval must be positive');
        }

        if ($tileWidth !== null && $tileWidth <= 0) {
            throw new \InvalidArgumentException('Tile width must be positive');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->requestVideoOnly(self::class);
        $spec->markProgressIndeterminate();
        $spec->addVideoFilter(sprintf('fps=1000000/%d', $this->interval->toMicros()));

        if ($this->tileWidth !== null) {
            $spec->addVideoFilter(sprintf('scale=%d:-2', $this->tileWidth));
        }

        $spec->addVideoFilter(sprintf('tile=%dx%d', $this->cols, $this->rows));
        $spec->addOutputOption('-frames:v', '1');
        $spec->addOutputOption('-an');
    }
}
