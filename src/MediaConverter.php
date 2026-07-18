<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

use Rasuvaeff\Bulkhead\Bulkhead;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\Internal\OutputTransaction;
use Rasuvaeff\MediaConverter\Internal\ProgressFraction;
use Rasuvaeff\MediaConverter\Internal\ProgressReportParser;
use Rasuvaeff\MediaConverter\Progress\ConversionPhase;
use Rasuvaeff\MediaConverter\Progress\ProgressEvent;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\RetryExhausted;

/**
 * Runs a {@see Pipeline} through ffmpeg: builds the argv, executes it via a
 * {@see RunsProcess}, and maps the outcome to a {@see ConversionResult} or a
 * typed {@see ConversionFailed}.
 *
 * Resilience is opt-in and injected:
 * - a {@see Bulkhead} caps concurrent ffmpeg processes per worker;
 * - a {@see Retry} retries TRANSIENT failures (a non-zero exit whose stderr
 *   looks like an upstream HTTP 5xx / connection reset — see
 *   {@see isTransientFailure()}).
 *
 * Retry wraps the bulkhead (`retry(bulkhead(run))`), so the concurrency slot
 * is released during backoff instead of starving other work. A
 * {@see \Rasuvaeff\Bulkhead\BulkheadFullException} propagates as itself — it
 * is an operational condition, not a conversion failure.
 *
 * A {@see ProbesMedia} is also opt-in. When given, every run probes the
 * source ONCE before any subprocess (not retried — a probe failure or an
 * encrypted source will not change between attempts):
 * - an encrypted source ({@see MediaInfo::isEncrypted()}) is refused with
 *   {@see ConversionFailureReason::Drm} before ffmpeg ever runs;
 * - the probed duration feeds {@see ProgressFraction}, turning `$onProgress`
 *   samples determinate. Without a prober, progress is always indeterminate
 *   (`ProgressEvent::fraction()` is null).
 *
 * `$onProgress`, when given, adds `-progress pipe:1 -nostats` to the argv
 * (never otherwise) and parses ffmpeg's machine-readable report stream from
 * stdout — never stderr, which stays clean for error classification.
 *
 * Output is transactional: ffmpeg writes into a staging directory beside the
 * destination, so a failed run leaves existing output and package sidecars
 * untouched. Successful package operations commit every generated artifact.
 *
 * @api
 */
final readonly class MediaConverter implements ConvertsMedia
{
    private const int DEFAULT_TIMEOUT_SECONDS = 600;

    private const int DEFAULT_IDLE_TIMEOUT_SECONDS = 120;

    public function __construct(
        private FfmpegBinary $binary,
        private RunsProcess $runner,
        private ?Bulkhead $bulkhead = null,
        private ?Retry $retry = null,
        private ?Duration $timeout = null,
        private ?Duration $idleTimeout = null,
        private ?ProbesMedia $prober = null,
    ) {}

    /**
     * Whether a failure can succeed on a retry: only a non-zero exit whose
     * stderr looks like a transient upstream error (HTTP 5xx, connection
     * reset/timeout). Every deterministic failure (timeout, DRM, no input,
     * incompatible operations, missing binary) returns false.
     */
    public static function isTransientFailure(\Throwable $failure): bool
    {
        if (!$failure instanceof ConversionFailed || $failure->reason !== ConversionFailureReason::NonZeroExit) {
            return false;
        }

        return preg_match(
            '/HTTP (?:error )?5\d\d|Server returned 5\d\d|Connection reset|Connection timed out|Connection refused/i',
            $failure->stderrTail(),
        ) === 1;
    }

    #[\Override]
    /** @param callable(ProgressEvent): void|null $onProgress */
    public function run(
        Pipeline $pipeline,
        string $outputPath,
        ?callable $onProgress = null,
        ?CancellationToken $token = null,
    ): ConversionResult {
        return $this->runInternal($pipeline, $outputPath, $onProgress, $token);
    }

    /** @param callable(ProgressEvent): void|null $onProgress */
    private function runInternal(
        Pipeline $pipeline,
        string $outputPath,
        ?callable $onProgress,
        ?CancellationToken $token,
    ): ConversionResult {
        // Builds and validates the spec before probing or creating staging files.
        $spec = $pipeline->commandSpec();

        $this->throwIfCancelled($token);
        $layout = $spec->outputLayout();
        $totalDuration = null;

        if ($this->prober instanceof ProbesMedia) {
            $this->emitPhase($onProgress, ConversionPhase::Probing);
            $this->throwIfCancelled($token);
            $totalDuration = $this->probeSources($spec);
            $this->throwIfCancelled($token);
        }

        $timeout = $this->timeout ?? Duration::seconds(self::DEFAULT_TIMEOUT_SECONDS);
        $idleTimeout = $this->idleTimeout ?? Duration::seconds(self::DEFAULT_IDLE_TIMEOUT_SECONDS);
        $inputBytes = $this->inputBytes($spec);
        $startedAt = $this->now();
        $transaction = new OutputTransaction($outputPath, $layout);

        try {
            $argv = $transaction->rewriteArgv($pipeline->toArgv($this->binary, $transaction->stagedOutputPath()));
        } catch (\Throwable $failure) {
            // The transaction already holds the lock and staging directory.
            $transaction->rollback();

            throw $failure;
        }

        $execute = function () use ($argv, $transaction, $timeout, $idleTimeout, $onProgress, $totalDuration, $token): ProcessOutcome {
            $runArgv = $onProgress !== null ? $this->withProgressReporting($argv) : $argv;
            $onOutput = $onProgress !== null
                ? $this->progressForwarder($onProgress, $totalDuration)
                : static fn(string $type, string $chunk): null => null;

            $guardedOutput = $this->guardOutput($onOutput, $token);

            $outcome = $this->runner->run($runArgv, $timeout, $idleTimeout, $guardedOutput);

            if ($outcome->isSuccess()) {
                return $outcome;
            }

            $transaction->reset();

            throw new ConversionFailed(
                reason: $outcome->timedOut ? ConversionFailureReason::Timeout : $this->classify($outcome),
                exitCode: $outcome->exitCode,
                stderr: $outcome->stderrTail,
            );
        };

        $bulkhead = $this->bulkhead;
        $slotted = $bulkhead instanceof Bulkhead
            ? static fn(): ProcessOutcome => $bulkhead->call($execute)
            : $execute;

        try {
            $this->emitPhase($onProgress, ConversionPhase::Running);
            $this->throwIfCancelled($token);
            if ($this->retry instanceof Retry) {
                // The engine is authoritative about WHAT retries (only transient
                // failures); the injected policy governs backoff and attempts.
                try {
                    $this->retry
                        ->retryIf(self::isTransientFailure(...))
                        ->stopIf(static fn(\Throwable $failure): bool => !self::isTransientFailure($failure))
                        ->run($slotted);
                } catch (RetryExhausted $exhausted) {
                    $lastFailure = $exhausted->lastException;

                    if ($lastFailure instanceof ConversionFailed) {
                        throw new ConversionFailed(
                            reason: $lastFailure->reason,
                            exitCode: $lastFailure->exitCode,
                            stderr: $lastFailure->stderrTail(),
                            previous: $exhausted,
                        );
                    }

                    throw $exhausted;
                }
            } else {
                $slotted();
            }

            $this->emitPhase($onProgress, ConversionPhase::Committing);
            $this->throwIfCancelled($token);
            $artifacts = $transaction->commit();
        } catch (\Throwable $failure) {
            $transaction->rollback();

            throw $failure;
        }

        $this->emitPhase($onProgress, ConversionPhase::Completed, $totalDuration);

        return new ConversionResult(
            outputPath: $outputPath,
            elapsed: Duration::seconds($this->now() - $startedAt),
            inputBytes: $inputBytes,
            outputBytes: $this->artifactBytes($artifacts),
            outputArtifacts: $artifacts,
            command: $argv,
        );
    }

    private function emitPhase(?callable $onProgress, ConversionPhase $phase, ?Duration $duration = null): void
    {
        if ($onProgress === null) {
            return;
        }

        $onProgress(new ProgressEvent(
            fraction: $phase === ConversionPhase::Completed && $duration instanceof Duration ? 1.0 : null,
            outTime: $duration ?? Duration::zero(),
            phase: $phase,
        ));
    }

    /** @return callable(string, string): void */
    private function guardOutput(callable $onOutput, ?CancellationToken $token): callable
    {
        return static function (string $type, string $chunk) use ($onOutput, $token): void {
            if ($token instanceof CancellationToken && $token->isCancellationRequested()) {
                throw new ConversionCancelled();
            }

            $onOutput($type, $chunk);
        };
    }

    private function throwIfCancelled(?CancellationToken $token): void
    {
        if ($token?->isCancellationRequested() === true) {
            throw new ConversionCancelled();
        }
    }

    /**
     * Global options must precede the first `-i`; splicing right after the
     * ffmpeg binary path (argv[0]) is always valid regardless of what the
     * pipeline emitted. `-nostats` suppresses ffmpeg's default per-second
     * human stats line on stderr, keeping the stderr tail clean for error
     * classification even while progress reporting is on.
     *
     * @param list<string> $argv
     *
     * @return list<string>
     */
    private function withProgressReporting(array $argv): array
    {
        array_splice($argv, 1, 0, ['-progress', 'pipe:1', '-nostats']);

        return $argv;
    }

    /**
     * Builds a fresh {@see ProgressReportParser} per call — each subprocess
     * attempt gets a clean buffer, so a retried run never carries over a
     * previous attempt's partial report.
     *
     * @param callable(ProgressEvent): void $onProgress
     *
     * @return callable(string, string): void
     */
    private function progressForwarder(callable $onProgress, ?Duration $totalDuration): callable
    {
        $parser = new ProgressReportParser();

        return static function (string $type, string $chunk) use ($parser, $onProgress, $totalDuration): void {
            if ($type !== 'out') {
                return;
            }

            foreach ($parser->feed($chunk) as $sample) {
                $onProgress(new ProgressEvent(
                    fraction: ProgressFraction::compute($sample->outTime, $totalDuration),
                    outTime: $sample->outTime,
                    frame: $sample->frame,
                    fps: $sample->fps,
                    speed: $sample->speed,
                ));
            }
        };
    }

    /**
     * Classify a non-timeout, non-zero exit. A recognisable "cannot open the
     * input" signature maps to {@see ConversionFailureReason::NoInput};
     * everything else is a generic non-zero exit.
     */
    private function classify(ProcessOutcome $outcome): ConversionFailureReason
    {
        if ($outcome->exitCode === 127) {
            return ConversionFailureReason::FfmpegNotExecutable;
        }

        if (preg_match('/No such file or directory|Protocol not found|404 Not Found|Invalid data found when processing input|does not exist/i', $outcome->stderrTail) === 1) {
            return ConversionFailureReason::NoInput;
        }

        return ConversionFailureReason::NonZeroExit;
    }

    private function probeSources(CommandSpec $spec): ?Duration
    {
        /** @var array<string, Duration> $durations */
        $durations = [];

        // Probing is deliberately outside retry: metadata and DRM state do not
        // change between conversion attempts.
        foreach ($spec->probeSources() as $source) {
            if (isset($durations[$source])) {
                continue;
            }

            $info = $this->prober?->probe($source);

            if (!$info instanceof MediaInfo) {
                throw new \LogicException('Configured media prober returned no media info');
            }

            if ($info->isEncrypted()) {
                throw new ConversionFailed(
                    reason: ConversionFailureReason::Drm,
                    exitCode: 0,
                    stderr: sprintf('Source is DRM-protected (encrypted); refusing to convert: %s', $source),
                );
            }

            $durations[$source] = $info->duration();
        }

        $total = Duration::zero();

        foreach ($spec->timelineSources() as $source) {
            $duration = $durations[$source] ?? null;

            if (!$duration instanceof Duration || !$duration->isPositive()) {
                return null;
            }

            $total = $total->plus($duration);
        }

        return $spec->outputDuration($total);
    }

    private function inputBytes(CommandSpec $spec): int
    {
        return array_sum(array_map($this->fileSize(...), $spec->inputSources()));
    }

    /** @param non-empty-list<string> $artifacts */
    private function artifactBytes(array $artifacts): int
    {
        return array_sum(array_map($this->fileSize(...), $artifacts));
    }

    private function fileSize(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }

        $size = @filesize($path);

        return $size === false ? 0 : $size;
    }

    private function now(): float
    {
        return microtime(true);
    }
}
