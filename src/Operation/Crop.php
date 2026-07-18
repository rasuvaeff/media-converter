<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Crop a `width×height` rectangle at offset `(x, y)` from the top-left.
 *
 * @api
 */
final readonly class Crop implements OperationInterface
{
    public function __construct(
        private int $width,
        private int $height,
        private int $x = 0,
        private int $y = 0,
    ) {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Crop width and height must be positive');
        }

        if ($x < 0 || $y < 0) {
            throw new \InvalidArgumentException('Crop offset cannot be negative');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->addVideoFilter(sprintf('crop=%d:%d:%d:%d', $this->width, $this->height, $this->x, $this->y));
    }
}
