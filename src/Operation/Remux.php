<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Stream-copy remux: rewrap the streams into a new container without
 * re-encoding (`-c copy`). Fast and lossless, but the target container must
 * accept the source codecs. Incompatible with any filter — the pipeline
 * rejects that combination before running ffmpeg.
 *
 * @api
 */
final readonly class Remux implements OperationInterface
{
    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->requestStreamCopy();
    }
}
