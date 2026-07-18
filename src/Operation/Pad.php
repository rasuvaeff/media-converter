<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Pad the video to `width×height`, placing the original at offset `(x, y)`
 * and filling the border with `color` (an ffmpeg color name or `0xRRGGBB`).
 *
 * @api
 */
final readonly class Pad implements OperationInterface
{
    public function __construct(
        private int $width,
        private int $height,
        private int $x = 0,
        private int $y = 0,
        private string $color = 'black',
    ) {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Pad width and height must be positive');
        }

        if ($x < 0 || $y < 0) {
            throw new \InvalidArgumentException('Pad offset cannot be negative');
        }

        if ($color === '') {
            throw new \InvalidArgumentException('Pad color cannot be empty');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->addVideoFilter(sprintf('pad=%d:%d:%d:%d:%s', $this->width, $this->height, $this->x, $this->y, $this->color));
    }
}
