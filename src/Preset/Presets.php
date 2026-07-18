<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Preset;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\ExtractAudio;
use Rasuvaeff\MediaConverter\Operation\OperationInterface;
use Rasuvaeff\MediaConverter\Operation\Remux;
use Rasuvaeff\MediaConverter\Operation\Scale;
use Rasuvaeff\MediaConverter\Operation\Thumbnail;
use Rasuvaeff\MediaConverter\Operation\Transcode;
use Rasuvaeff\MediaConverter\Operation\Trim;
use Rasuvaeff\MediaConverter\Pipeline;

/**
 * Ready-made {@see Pipeline} wrappers for frequent tasks. Download
 * compatibility (the original Video Download Helper use case: HLS/DASH →
 * MP4) is provided HERE, as ordinary compositions of the core operations —
 * not baked into the core itself.
 *
 * @api
 */
final class Presets
{
    private function __construct() {}

    /**
     * Stream-copy an HLS source into an MP4 container. Adds the
     * `aac_adtstoasc` bitstream filter — historically required (and, when
     * unneeded, harmless — verified against a real ffmpeg build) to convert
     * ADTS AAC audio, as commonly found in `.ts` HLS segments, to the ASC
     * form MP4 expects.
     */
    public static function hlsToMp4(string $source): Pipeline
    {
        return Pipeline::from($source)
            ->add(new Remux())
            ->add(self::adtsToAsc());
    }

    /**
     * Re-encode an HLS source into MP4 with widely-compatible H.264/AAC,
     * instead of stream-copying it.
     */
    public static function hlsToMp4Transcode(string $source): Pipeline
    {
        return Pipeline::from($source)->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac'));
    }

    /**
     * A broadly compatible H.264/AAC MP4 profile for web playback.
     */
    public static function webMp4(string $source, int $videoBitrateKbps = 2_500, int $audioBitrateKbps = 192): Pipeline
    {
        return Pipeline::from($source)->add(new Transcode(
            videoCodec: 'libx264',
            audioCodec: 'aac',
            videoBitrateKbps: $videoBitrateKbps,
            audioBitrateKbps: $audioBitrateKbps,
        ));
    }

    /**
     * Stream-copy a DASH source into an MP4 container. DASH's fragmented-MP4
     * segments are already in ASC form, so — unlike {@see hlsToMp4()} — no
     * bitstream filter is needed.
     */
    public static function dashToMp4(string $source): Pipeline
    {
        return Pipeline::from($source)->add(new Remux());
    }

    /**
     * A VP9/Opus WebM profile. VP9 compresses better than VP8 but encodes
     * slower; swap the codecs via a plain {@see Transcode} if VP8 is needed.
     */
    public static function webM(string $source, int $videoBitrateKbps = 2_500, int $audioBitrateKbps = 128): Pipeline
    {
        return Pipeline::from($source)->add(new Transcode(
            videoCodec: 'libvpx-vp9',
            audioCodec: 'libopus',
            videoBitrateKbps: $videoBitrateKbps,
            audioBitrateKbps: $audioBitrateKbps,
        ));
    }

    /**
     * Extract the audio track as an MP3 file.
     */
    public static function mp3(string $source, int $bitrateKbps = 192): Pipeline
    {
        return Pipeline::from($source)->add(new ExtractAudio('libmp3lame', $bitrateKbps));
    }

    /**
     * Extract the audio track as an AAC file (M4A container).
     */
    public static function aac(string $source, int $bitrateKbps = 128): Pipeline
    {
        return Pipeline::from($source)->add(new ExtractAudio('aac', $bitrateKbps));
    }

    /**
     * A single JPEG/PNG still frame at `$at`, resized to `$width`.
     */
    public static function webThumbnail(string $source, Duration $at, int $width = 320): Pipeline
    {
        return Pipeline::from($source)->add(new Thumbnail($at, $width));
    }

    /**
     * A `[$from, $to)` clip capped to `$maxWidth` and re-encoded to
     * widely-compatible H.264/AAC — sized for sharing, not archival.
     */
    public static function socialClip(string $source, Duration $from, Duration $to, int $maxWidth = 720): Pipeline
    {
        return Pipeline::from($source)
            ->add(new Trim($from, $to))
            ->add(new Scale(width: $maxWidth))
            ->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac'));
    }

    private static function adtsToAsc(): OperationInterface
    {
        return new class implements OperationInterface {
            #[\Override]
            public function applyTo(CommandSpec $spec): void
            {
                $spec->addOutputOption('-bsf:a', 'aac_adtstoasc');
            }
        };
    }
}
