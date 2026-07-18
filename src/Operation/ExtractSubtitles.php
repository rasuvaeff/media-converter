<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Extract one subtitle stream by its zero-based subtitle-stream index
 * (`-map 0:s:$streamIndex`). Intended as the sole operation of a pipeline
 * whose output path is a subtitle file (`.srt`, `.ass`); the output format
 * is inferred by ffmpeg from the output path's extension.
 *
 * @api
 */
final readonly class ExtractSubtitles implements OperationInterface
{
    public function __construct(
        private int $streamIndex = 0,
    ) {
        if ($streamIndex < 0) {
            throw new \InvalidArgumentException('Subtitle stream index cannot be negative');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->requestSubtitleOnly(self::class);
        $spec->setSubtitleOutput(sprintf('0:s:%d', $this->streamIndex));
    }
}
