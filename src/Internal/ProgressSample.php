<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Internal;

use Rasuvaeff\Duration\Duration;

/**
 * One parsed `-progress` report, before it is combined with an optional
 * total duration into a public
 * {@see \Rasuvaeff\MediaConverter\Progress\ProgressEvent}.
 *
 * @internal
 */
final readonly class ProgressSample
{
    public function __construct(
        public Duration $outTime,
        public ?int $frame,
        public ?float $fps,
        public ?float $speed,
    ) {}
}
