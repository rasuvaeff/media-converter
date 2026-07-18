<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

use Rasuvaeff\MediaConverter\Progress\ProgressEvent;

/**
 * Runs a composed {@see Pipeline} through ffmpeg, writing the result to a
 * local path.
 *
 * @api
 */
interface ConvertsMedia
{
    /**
     * @param non-empty-string                    $outputPath local path for the produced file
     * @param callable(ProgressEvent): void|null  $onProgress receives progress samples while ffmpeg runs
     * @param CancellationToken|null              $token      when given, cooperatively aborts the run between phases
     *                                                        and on the next ffmpeg output chunk
     *
     * @throws ConversionFailed    on timeout, non-zero exit, missing input, DRM, or an incompatible pipeline
     * @throws ConversionCancelled when $token is cancelled while the run is in flight
     */
    public function run(
        Pipeline $pipeline,
        string $outputPath,
        ?callable $onProgress = null,
        ?CancellationToken $token = null,
    ): ConversionResult;
}
