<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

/**
 * Stable discriminator for a {@see ConversionFailed}: lets callers and retry
 * policies match on a cause instead of parsing the exception message.
 *
 * @api
 */
enum ConversionFailureReason: string
{
    /** The subprocess exceeded its wall-clock or idle timeout. */
    case Timeout = 'timeout';

    /** ffmpeg/ffprobe exited with a non-zero status. */
    case NonZeroExit = 'non-zero-exit';

    /** The pipeline had no input, or the input could not be opened. */
    case NoInput = 'no-input';

    /** The source is DRM-protected (encrypted) and cannot be processed. */
    case Drm = 'drm';

    /** ffprobe could not read the source metadata. */
    case ProbeFailed = 'probe-failed';

    /**
     * The composed operations conflict (e.g. stream-copy together with a
     * filter). Raised by {@see Pipeline} BEFORE the subprocess starts.
     */
    case IncompatibleOperations = 'incompatible-operations';

    /** The output transaction could not stage or commit its artifacts. */
    case OutputFailed = 'output-failed';

    /** The configured ffmpeg binary is missing or not executable. */
    case FfmpegNotExecutable = 'ffmpeg-not-executable';

    /** The configured ffprobe binary is missing or not executable. */
    case FfprobeNotExecutable = 'ffprobe-not-executable';

    /**
     * Whether a failure with this reason can ever succeed on a retry. A
     * non-zero exit MIGHT be transient (HTTP 5xx, connection reset — the
     * engine inspects stderr); every other reason is deterministic and must
     * not be retried.
     */
    public function isPotentiallyTransient(): bool
    {
        return $this === self::NonZeroExit;
    }
}
