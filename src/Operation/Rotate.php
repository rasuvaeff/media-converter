<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Rotate the video by a right angle (90, 180 or 270 degrees clockwise),
 * built from ffmpeg `transpose` steps.
 *
 * @api
 */
final readonly class Rotate implements OperationInterface
{
    private const array TRANSPOSE = [
        90 => ['transpose=1'],
        180 => ['transpose=1', 'transpose=1'],
        270 => ['transpose=2'],
    ];

    public function __construct(
        private int $degrees,
    ) {
        if (!array_key_exists($degrees, self::TRANSPOSE)) {
            throw new \InvalidArgumentException('Rotation must be 90, 180 or 270 degrees');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        foreach (self::TRANSPOSE[$this->degrees] as $node) {
            $spec->addVideoFilter($node);
        }
    }
}
