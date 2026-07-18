<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

use Rasuvaeff\Duration\Duration;

/**
 * {@see ProbesMedia} over ffprobe's `-print_format json` output.
 *
 * DRM detection is a heuristic: {@see MediaInfo::isEncrypted()} is true when
 * the combined stdout/stderr mentions "decrypt" (case-insensitive) — ffprobe
 * has no dedicated encrypted-stream flag, but its diagnostics for
 * DRM-protected sources consistently name decryption. Successful probes expose
 * the heuristic through {@see MediaInfo::isEncrypted()}; a non-zero ffprobe
 * exit containing a decrypt diagnostic is thrown as {@see ConversionFailed}
 * with reason {@see ConversionFailureReason::Drm}.
 *
 * @api
 */
final readonly class FfprobeMediaInfo implements ProbesMedia
{
    private const int DEFAULT_TIMEOUT_SECONDS = 30;

    public function __construct(
        private FfmpegBinary $binary,
        private RunsProcess $runner,
        private ?Duration $timeout = null,
    ) {}

    #[\Override]
    public function probe(string $source): MediaInfo
    {
        if ($source === '') {
            throw new \InvalidArgumentException('Probe source cannot be empty');
        }

        $argv = [
            $this->binary->ffprobePath(),
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $source,
        ];

        $stdout = '';
        $outcome = $this->runner->run(
            $argv,
            $this->timeout ?? Duration::seconds(self::DEFAULT_TIMEOUT_SECONDS),
            Duration::zero(),
            static function (string $type, string $chunk) use (&$stdout): void {
                if ($type === 'out') {
                    $stdout .= $chunk;
                }
            },
        );

        if (!$outcome->isSuccess()) {
            $reason = match (true) {
                preg_match('/decrypt/i', $this->maskFilenameEcho($stdout) . $outcome->stderrTail) === 1 => ConversionFailureReason::Drm,
                $outcome->timedOut => ConversionFailureReason::Timeout,
                $outcome->exitCode === 127 => ConversionFailureReason::FfprobeNotExecutable,
                default => ConversionFailureReason::ProbeFailed,
            };

            throw new ConversionFailed(
                reason: $reason,
                exitCode: $outcome->exitCode,
                stderr: $outcome->stderrTail,
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($stdout, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::ProbeFailed,
                exitCode: $outcome->exitCode,
                stderr: 'ffprobe produced invalid JSON',
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::ProbeFailed,
                exitCode: $outcome->exitCode,
                stderr: 'ffprobe JSON was not an object',
            );
        }

        /** @var mixed $formatValue */
        $formatValue = $decoded['format'] ?? [];
        $format = is_array($formatValue) ? $formatValue : [];

        /** @var mixed $streamsValue */
        $streamsValue = $decoded['streams'] ?? [];

        $video = $this->firstStreamOfType($streamsValue, 'video');
        $audio = $this->firstStreamOfType($streamsValue, 'audio');

        return new MediaInfo(
            // A corrupt container can report a small negative duration —
            // clamp rather than let the non-negative Duration throw past the
            // ConversionFailed(ProbeFailed) contract.
            duration: Duration::seconds(max(0.0, $this->floatField($format, 'duration') ?? 0.0)),
            width: $video !== null ? $this->positiveIntField($video, 'width') : null,
            height: $video !== null ? $this->positiveIntField($video, 'height') : null,
            videoCodec: $video !== null ? $this->stringField($video, 'codec_name') : null,
            audioCodec: $audio !== null ? $this->stringField($audio, 'codec_name') : null,
            bitrate: $this->positiveIntField($format, 'bit_rate'),
            encrypted: preg_match('/decrypt/i', $this->maskFilenameEcho($stdout) . $outcome->stderrTail) === 1,
        );
    }

    /**
     * `-show_format` echoes the probed path back as `format.filename`; a
     * source living under a path like `/videos/decrypted/movie.mp4` must not
     * trip the `/decrypt/i` DRM heuristic. Diagnostics in stream tags and on
     * stderr keep matching.
     */
    private function maskFilenameEcho(string $stdout): string
    {
        return preg_replace('/"filename"\s*:\s*"(?:[^"\\\\]|\\\\.)*"/', '"filename":""', $stdout) ?? $stdout;
    }

    /**
     * @return ?array<array-key, mixed>
     */
    private function firstStreamOfType(mixed $streams, string $type): ?array
    {
        if (!is_array($streams)) {
            return null;
        }

        /** @var mixed $stream */
        foreach ($streams as $stream) {
            if (!is_array($stream) || ($stream['codec_type'] ?? null) !== $type) {
                continue;
            }

            // Embedded cover art (MP3/M4A/FLAC) is reported as a video stream
            // with disposition.attached_pic=1 — it is not a real video track.
            if ($type === 'video' && $this->isAttachedPicture($stream)) {
                continue;
            }

            return $stream;
        }

        return null;
    }

    /** @param array<array-key, mixed> $stream */
    private function isAttachedPicture(array $stream): bool
    {
        if (!isset($stream['disposition']) || !is_array($stream['disposition'])) {
            return false;
        }

        return ($stream['disposition']['attached_pic'] ?? 0) === 1;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function stringField(array $data, string $key): ?string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            return null;
        }

        return $data[$key] !== '' ? $data[$key] : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function positiveIntField(array $data, string $key): ?int
    {
        if (isset($data[$key]) && is_int($data[$key])) {
            return $data[$key] > 0 ? $data[$key] : null;
        }

        if (isset($data[$key]) && is_string($data[$key]) && preg_match('/^\d+$/', $data[$key]) === 1) {
            $int = (int) $data[$key];

            return $int > 0 ? $int : null;
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function floatField(array $data, string $key): ?float
    {
        if (isset($data[$key]) && (is_float($data[$key]) || is_int($data[$key]))) {
            return (float) $data[$key];
        }

        return isset($data[$key]) && is_string($data[$key]) && is_numeric($data[$key]) ? (float) $data[$key] : null;
    }
}
