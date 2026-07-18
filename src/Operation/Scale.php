<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Resize the video. A null dimension becomes `-2` (ffmpeg keeps the aspect
 * ratio, rounding to an even number the encoders accept); at least one
 * dimension must be given.
 *
 * @api
 */
final readonly class Scale implements OperationInterface
{
    public function __construct(
        private ?int $width = null,
        private ?int $height = null,
    ) {
        if ($width === null && $height === null) {
            throw new \InvalidArgumentException('Scale requires a width or a height');
        }

        if ($width !== null && $width <= 0) {
            throw new \InvalidArgumentException('Width must be positive');
        }

        if ($height !== null && $height <= 0) {
            throw new \InvalidArgumentException('Height must be positive');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->addVideoFilter(sprintf('scale=%d:%d', $this->width ?? -2, $this->height ?? -2));
    }
}
