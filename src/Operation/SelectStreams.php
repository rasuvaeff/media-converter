<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Select concrete zero-based streams from the primary input. Null leaves that
 * stream kind on ffmpeg's automatic selection; at least one index is required.
 *
 * @api
 */
final readonly class SelectStreams implements OperationInterface
{
    public function __construct(
        private ?int $videoIndex = null,
        private ?int $audioIndex = null,
        private ?int $subtitleIndex = null,
        private bool $optional = false,
    ) {
        if ($videoIndex === null && $audioIndex === null && $subtitleIndex === null) {
            throw new \InvalidArgumentException('SelectStreams requires at least one stream index');
        }

        foreach ([$videoIndex, $audioIndex, $subtitleIndex] as $index) {
            if ($index !== null && $index < 0) {
                throw new \InvalidArgumentException('Stream index cannot be negative');
            }
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $suffix = $this->optional ? '?' : '';

        if ($this->videoIndex !== null) {
            $spec->setVideoOutput(sprintf('0:v:%d%s', $this->videoIndex, $suffix));
        }

        if ($this->audioIndex !== null) {
            $spec->setAudioOutput(sprintf('0:a:%d%s', $this->audioIndex, $suffix));
        }

        if ($this->subtitleIndex !== null) {
            $spec->setSubtitleOutput(sprintf('0:s:%d%s', $this->subtitleIndex, $suffix));
        }
    }
}
