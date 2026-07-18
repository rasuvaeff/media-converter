<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Force a constant output frame rate, dropping or duplicating frames as
 * needed (`fps` filter).
 *
 * @api
 */
final readonly class Fps implements OperationInterface
{
    public function __construct(
        private int $fps,
    ) {
        if ($fps <= 0) {
            throw new \InvalidArgumentException('Fps must be positive');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->addVideoFilter(sprintf('fps=%d', $this->fps));
    }
}
