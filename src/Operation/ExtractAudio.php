<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Drop the video stream and encode the audio with the given ffmpeg encoder
 * (`libmp3lame`, `aac`, `libopus`, `copy`, …). Bitrate is in kbit/s.
 *
 * @api
 */
final readonly class ExtractAudio implements OperationInterface
{
    public function __construct(
        private string $codec = 'libmp3lame',
        private ?int $bitrateKbps = 192,
    ) {
        if ($codec === '') {
            throw new \InvalidArgumentException('Audio codec cannot be empty');
        }

        if ($bitrateKbps !== null && $bitrateKbps <= 0) {
            throw new \InvalidArgumentException('Audio bitrate must be positive');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->requestAudioOnly(self::class);
        $spec->addOutputOption('-vn', '-c:a', $this->codec);

        if ($this->bitrateKbps !== null) {
            $spec->addOutputOption('-b:a', $this->bitrateKbps . 'k');
        }
    }
}
