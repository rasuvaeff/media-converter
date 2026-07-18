<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Embed a cover-art image (JPEG/PNG, added as an extra `-i`) as an
 * `attached_pic` stream. The target container decides the stream layout, and
 * an operation cannot see the output path — so the caller states it via the
 * named constructor: {@see forAudio()} for audio-only targets (MP3/M4A/FLAC:
 * the cover becomes the only video stream, `v:0`), {@see forVideo()} for
 * video targets (MP4/MKV: main video stays `v:0`, the cover becomes `v:1`).
 *
 * The image stream is stream-COPIED (`-c:v:N copy`), never re-encoded — a
 * JPEG input is already an MJPEG stream, a PNG stays PNG; both are valid
 * `attached_pic` codecs. That also lets this compose with {@see Remux}. When
 * combined with a video {@see Transcode}, add the artwork AFTER the
 * transcode — ffmpeg applies the LAST matching codec option per stream, so
 * `-c:v:N copy` must follow the transcode's `-c:v` in the argv.
 *
 * The image's existence is not validated here (like every other source,
 * that is ffmpeg's zone — fail-fast at run time).
 *
 * @api
 */
final readonly class AddArtwork implements OperationInterface
{
    private function __construct(
        private string $image,
        private bool $audioOnly,
        private bool $id3v2,
    ) {
        if ($image === '') {
            throw new \InvalidArgumentException('Artwork image cannot be empty');
        }
    }

    /**
     * Cover art for an audio-only target. `$id3v2` (default true) emits the
     * MP3-specific `-id3v2_version 3`; pass false for M4A/FLAC — their muxers
     * reject the option.
     */
    public static function forAudio(string $image, bool $id3v2 = true): self
    {
        return new self($image, audioOnly: true, id3v2: $id3v2);
    }

    /**
     * Cover art for a video target (MP4/MKV): every primary stream keeps its
     * place, the cover is appended as video stream `v:1`.
     */
    public static function forVideo(string $image): self
    {
        return new self($image, audioOnly: false, id3v2: false);
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->claimArtwork(self::class);
        $imageInputIndex = $spec->addInput($this->image, probe: false, timeline: false);

        if ($this->audioOnly) {
            $spec->setAudioOutput('0:a');
            $coverStreamIndex = 0;
        } else {
            $spec->setDefaultVideoOutput('0:v');
            $spec->setDefaultAudioOutput('0:a?');
            $coverStreamIndex = 1;
        }

        $spec->addMap(sprintf('%d:v', $imageInputIndex));
        $spec->addOutputOption(sprintf('-c:v:%d', $coverStreamIndex), 'copy');
        $spec->addOutputOption(sprintf('-disposition:v:%d', $coverStreamIndex), 'attached_pic');

        if ($this->id3v2) {
            $spec->addOutputOption('-id3v2_version', '3');
        }
    }
}
