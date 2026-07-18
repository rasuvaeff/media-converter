<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Package the output as a DASH manifest (`-f dash`) plus its `.m4s` init and
 * media segments. `-window_size` (segments kept in the manifest) defaults to
 * `0` (unlimited) in ffmpeg itself, so a VOD manifest with every segment
 * needs no extra flag — verified against a real ffmpeg build, unlike HLS.
 *
 * Composes with `Remux` (stream-copy) or `Transcode`, same as `PackageHls`.
 *
 * @api
 */
final readonly class PackageDash implements OperationInterface
{
    public function __construct(
        private int $segmentSeconds = 5,
    ) {
        if ($segmentSeconds <= 0) {
            throw new \InvalidArgumentException('Segment duration must be positive');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->markDashOutput();
        $spec->addOutputOption('-f', 'dash');
        $spec->addOutputOption('-seg_duration', (string) $this->segmentSeconds);
    }
}
