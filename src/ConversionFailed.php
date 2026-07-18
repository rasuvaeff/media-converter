<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

/**
 * Thrown when a media operation fails: the subprocess errors or times out,
 * a binary is missing, the source is DRM-protected, or the composed
 * operations conflict.
 *
 * The {@see $reason} discriminator lets callers (and retry policies) match on
 * a stable cause rather than parse the message. All monorepo failures are
 * typed exceptions (cf. `GenerationExhausted`, `DeadlineExceededException`),
 * so operations throw this rather than return a result object.
 *
 * @api
 */
final class ConversionFailed extends \RuntimeException implements MediaConverterException
{
    public function __construct(
        public readonly ConversionFailureReason $reason,
        public readonly int $exitCode,
        private readonly string $stderr,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            message: sprintf('Media operation failed (%s), exit=%d', $reason->value, $exitCode),
            code: 0,
            previous: $previous,
        );
    }

    /**
     * The last chunk of ffmpeg/ffprobe stderr (or an explanatory string for
     * non-subprocess reasons), never empty. This is the single reader for the
     * captured error output.
     *
     * @return non-empty-string
     */
    public function stderrTail(): string
    {
        return $this->stderr === '' ? '(empty)' : $this->stderr;
    }
}
