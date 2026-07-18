<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Re-encode video and/or audio. Codec names are ffmpeg encoder names
 * (`libx264`, `aac`, `libvpx-vp9`, `libmp3lame`, …) passed through verbatim —
 * this library does not maintain a lossy alias map. Bitrates are in kbit/s.
 *
 * At least one of the four parameters must be set.
 *
 * @api
 */
final readonly class Transcode implements OperationInterface
{
    public function __construct(
        private ?string $videoCodec = null,
        private ?string $audioCodec = null,
        private ?int $videoBitrateKbps = null,
        private ?int $audioBitrateKbps = null,
    ) {
        if ($videoCodec === null && $audioCodec === null && $videoBitrateKbps === null && $audioBitrateKbps === null) {
            throw new \InvalidArgumentException('Transcode requires at least one codec or bitrate');
        }

        if ($videoCodec === '') {
            throw new \InvalidArgumentException('Video codec cannot be empty');
        }

        if ($audioCodec === '') {
            throw new \InvalidArgumentException('Audio codec cannot be empty');
        }

        if ($videoBitrateKbps !== null && $videoBitrateKbps <= 0) {
            throw new \InvalidArgumentException('Video bitrate must be positive');
        }

        if ($audioBitrateKbps !== null && $audioBitrateKbps <= 0) {
            throw new \InvalidArgumentException('Audio bitrate must be positive');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        if ($this->videoCodec !== null) {
            $spec->addOutputOption('-c:v', $this->videoCodec);
        }

        if ($this->audioCodec !== null) {
            $spec->addOutputOption('-c:a', $this->audioCodec);
        }

        if ($this->videoBitrateKbps !== null) {
            $spec->addOutputOption('-b:v', $this->videoBitrateKbps . 'k');
        }

        if ($this->audioBitrateKbps !== null) {
            $spec->addOutputOption('-b:a', $this->audioBitrateKbps . 'k');
        }
    }
}
