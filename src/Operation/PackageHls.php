<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Package the output as an HLS VOD playlist (`-f hls`) plus its `.ts`
 * segments. `-hls_playlist_type vod` is always set — verified against a real
 * ffmpeg build: without it, only the last `-hls_list_size` (default 5)
 * segments stay in the playlist (a live-streaming rolling window), which is
 * wrong for VOD packaging; with it, every segment is kept and the playlist
 * ends with `#EXT-X-ENDLIST`.
 *
 * Composes with `Remux` (stream-copy) for lossless repackaging, or with
 * `Transcode` to re-encode while segmenting — verified against a real
 * ffmpeg build.
 *
 * @api
 */
final readonly class PackageHls implements OperationInterface
{
    public function __construct(
        private int $segmentSeconds = 6,
        private ?string $segmentFilenamePattern = null,
    ) {
        if ($segmentSeconds <= 0) {
            throw new \InvalidArgumentException('Segment duration must be positive');
        }

        if ($segmentFilenamePattern === '') {
            throw new \InvalidArgumentException('Segment filename pattern cannot be empty');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->markHlsOutput($this->segmentFilenamePattern);
        $spec->addOutputOption('-f', 'hls');
        $spec->addOutputOption('-hls_time', (string) $this->segmentSeconds);
        $spec->addOutputOption('-hls_playlist_type', 'vod');

        if ($this->segmentFilenamePattern !== null) {
            $spec->addOutputOption('-hls_segment_filename', $this->segmentFilenamePattern);
        }
    }
}
