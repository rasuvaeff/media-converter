<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests;

use Rasuvaeff\Bulkhead\BulkheadFullException;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CancellationToken;
use Rasuvaeff\MediaConverter\ConversionCancelled;
use Rasuvaeff\MediaConverter\ConversionFailed;
use Rasuvaeff\MediaConverter\ConversionFailureReason;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\MediaConverter;
use Rasuvaeff\MediaConverter\MediaInfo;
use Rasuvaeff\MediaConverter\Operation\PackageHls;
use Rasuvaeff\MediaConverter\Operation\Remux;
use Rasuvaeff\MediaConverter\Operation\ReplaceAudio;
use Rasuvaeff\MediaConverter\Operation\Scale;
use Rasuvaeff\MediaConverter\Operation\Thumbnail;
use Rasuvaeff\MediaConverter\Operation\Transcode;
use Rasuvaeff\MediaConverter\Operation\Trim;
use Rasuvaeff\MediaConverter\Operation\Watermark;
use Rasuvaeff\MediaConverter\Pipeline;
use Rasuvaeff\MediaConverter\ProcessOutcome;
use Rasuvaeff\MediaConverter\Progress\ConversionPhase;
use Rasuvaeff\MediaConverter\Progress\ProgressEvent;
use Rasuvaeff\MediaConverter\Tests\Support\FakeProber;
use Rasuvaeff\MediaConverter\Tests\Support\FakeRunner;
use Rasuvaeff\MediaConverter\Tests\Support\FullBulkhead;
use Rasuvaeff\MediaConverter\Tests\Support\RecordingBulkhead;
use Rasuvaeff\Retry\Retry;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(MediaConverter::class)]
#[Covers(PackageHls::class)]
#[Covers(ReplaceAudio::class)]
#[Covers(Thumbnail::class)]
#[Covers(Trim::class)]
#[Covers(Watermark::class)]
final class MediaConverterTest
{
    private string $output;

    #[BeforeTest]
    public function prepareOutput(): void
    {
        $this->output = sys_get_temp_dir() . '/media-converter-' . bin2hex(random_bytes(6)) . '.mp4';
    }

    #[AfterTest]
    public function removeOutput(): void
    {
        if (is_file($this->output)) {
            unlink($this->output);
        }

        @unlink(dirname($this->output) . '/.' . basename($this->output) . '.media-converter.json');
        @unlink(dirname($this->output) . '/.' . basename($this->output) . '.media-converter.lock');
    }

    public function runsTheBuiltArgvAndReturnsAResult(): void
    {
        $runner = new FakeRunner([new ProcessOutcome(0, '')], outputContent: 'ABCDE');
        $converter = new MediaConverter(FfmpegBinary::default(), $runner);

        $result = $converter->run(
            Pipeline::from('input.mkv')->add(new Transcode(videoCodec: 'libx264')),
            $this->output,
        );

        Assert::same(array_slice($runner->calls[0], 0, -1), [
            '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
            '-i', 'input.mkv', '-c:v', 'libx264',
        ]);
        Assert::true(str_contains($runner->calls[0][array_key_last($runner->calls[0])], '/.media-converter-'));
        Assert::same($result->outputPath(), $this->output);
        Assert::same($result->outputBytes(), 5);
        // A nonexistent source contributes zero input bytes, and elapsed time
        // for an in-process fake run is a small, sane, forward-moving duration
        // (not, say, the sum of two Unix timestamps).
        Assert::same($result->inputBytes(), 0);
        Assert::true($result->elapsed()->toSeconds() >= 0.0 && $result->elapsed()->toSeconds() < 5.0);
        Assert::same($result->command(), $runner->calls[0]);
    }

    public function preCancelledRunNeverStartsAProcess(): void
    {
        $runner = new FakeRunner([]);
        $token = new CancellationToken();
        $token->cancel();
        $caught = null;

        try {
            (new MediaConverter(FfmpegBinary::default(), $runner))->run(Pipeline::from('in.mp4'), $this->output, token: $token);
        } catch (ConversionCancelled $failure) {
            $caught = $failure;
        }

        Assert::notNull($caught);
        Assert::same($runner->callCount(), 0);
    }

    public function preCancelledRunEmitsNoProgressEvents(): void
    {
        // The pre-flight cancellation check fires BEFORE any phase is
        // emitted: a token cancelled up-front must not leak even a Running
        // phase event before the ConversionCancelled propagates.
        $runner = new FakeRunner([]);
        $token = new CancellationToken();
        $token->cancel();
        $phases = [];
        $caught = null;

        try {
            (new MediaConverter(FfmpegBinary::default(), $runner))->run(
                Pipeline::from('in.mp4'),
                $this->output,
                static function (ProgressEvent $event) use (&$phases): void {
                    $phases[] = $event->phase();
                },
                $token,
            );
        } catch (ConversionCancelled $caught) {
        }

        Assert::notNull($caught);
        Assert::same($phases, []);
        Assert::same($runner->callCount(), 0);
    }

    public function cancellationDuringProbeEmitsOnlyTheProbingPhase(): void
    {
        // A token cancelled DURING the probe must abort right after
        // probeSources() — before the Running phase is ever emitted.
        $runner = new FakeRunner([]);
        $token = new CancellationToken();
        $prober = new readonly class ($token) implements \Rasuvaeff\MediaConverter\ProbesMedia {
            public function __construct(
                private CancellationToken $token,
            ) {}

            #[\Override]
            public function probe(string $source): MediaInfo
            {
                $this->token->cancel();

                return new MediaInfo(Duration::seconds(1), null, null, null, null, null);
            }
        };
        $phases = [];
        $caught = null;

        try {
            (new MediaConverter(FfmpegBinary::default(), $runner, prober: $prober))->run(
                Pipeline::from('in.mp4'),
                $this->output,
                static function (ProgressEvent $event) use (&$phases): void {
                    $phases[] = $event->phase();
                },
                $token,
            );
        } catch (ConversionCancelled $caught) {
        }

        Assert::notNull($caught);
        Assert::same($phases, [ConversionPhase::Probing]);
        Assert::same($runner->callCount(), 0);
    }

    public function progressReportsLifecyclePhases(): void
    {
        $runner = new FakeRunner([new ProcessOutcome(0, '')]);
        $prober = new FakeProber(new MediaInfo(Duration::seconds(1), 80, 60, 'h264', 'aac', null));
        $phases = [];

        (new MediaConverter(FfmpegBinary::default(), $runner, prober: $prober))->run(
            Pipeline::from('in.mp4'),
            $this->output,
            static function (ProgressEvent $event) use (&$phases): void {
                $phases[] = $event->phase();
            },
        );

        Assert::same($phases, [
            ConversionPhase::Probing,
            ConversionPhase::Running,
            ConversionPhase::Committing,
            ConversionPhase::Completed,
        ]);
    }

    public function cancellationDuringProbingPhaseDoesNotProbeOrRun(): void
    {
        $runner = new FakeRunner([]);
        $prober = new FakeProber(new MediaInfo(Duration::seconds(1), null, null, null, null, null));
        $token = new CancellationToken();
        $caught = null;

        try {
            (new MediaConverter(FfmpegBinary::default(), $runner, prober: $prober))->run(
                Pipeline::from('in.mp4'),
                $this->output,
                static function (ProgressEvent $event) use ($token): void {
                    if ($event->phase() === ConversionPhase::Probing) {
                        $token->cancel();
                    }
                },
                $token,
            );
        } catch (ConversionCancelled $failure) {
            $caught = $failure;
        }

        Assert::notNull($caught);
        Assert::same($prober->sources, []);
        Assert::same($runner->callCount(), 0);
    }

    public function cancellationAfterProbeDoesNotRun(): void
    {
        $runner = new FakeRunner([]);
        $token = new CancellationToken();
        $prober = new readonly class ($token) implements \Rasuvaeff\MediaConverter\ProbesMedia {
            public function __construct(
                private CancellationToken $token,
            ) {}

            #[\Override]
            public function probe(string $source): MediaInfo
            {
                $this->token->cancel();

                return new MediaInfo(Duration::seconds(1), null, null, null, null, null);
            }
        };
        $caught = null;

        try {
            (new MediaConverter(FfmpegBinary::default(), $runner, prober: $prober))->run(Pipeline::from('in.mp4'), $this->output, token: $token);
        } catch (ConversionCancelled $failure) {
            $caught = $failure;
        }

        Assert::notNull($caught);
        Assert::same($runner->callCount(), 0);
    }

    public function cancellationDuringRunningPhaseDoesNotStartRunner(): void
    {
        $runner = new FakeRunner([]);
        $token = new CancellationToken();
        $caught = null;

        try {
            (new MediaConverter(FfmpegBinary::default(), $runner))->run(
                Pipeline::from('in.mp4'),
                $this->output,
                static function (ProgressEvent $event) use ($token): void {
                    if ($event->phase() === ConversionPhase::Running) {
                        $token->cancel();
                    }
                },
                $token,
            );
        } catch (ConversionCancelled $failure) {
            $caught = $failure;
        }

        Assert::notNull($caught);
        Assert::same($runner->callCount(), 0);
    }

    public function cancellationDuringCommittingPhaseRollsBackOutput(): void
    {
        file_put_contents($this->output, 'original');
        $runner = new FakeRunner([new ProcessOutcome(0, '')], outputContent: 'replacement');
        $token = new CancellationToken();
        $caught = null;

        try {
            (new MediaConverter(FfmpegBinary::default(), $runner))->run(
                Pipeline::from('in.mp4'),
                $this->output,
                static function (ProgressEvent $event) use ($token): void {
                    if ($event->phase() === ConversionPhase::Committing) {
                        $token->cancel();
                    }
                },
                $token,
            );
        } catch (ConversionCancelled $failure) {
            $caught = $failure;
        }

        Assert::notNull($caught);
        Assert::same($runner->callCount(), 1);
        Assert::same(file_get_contents($this->output), 'original');
    }

    public function completedProgressHasOneFractionAndDuration(): void
    {
        $events = [];
        $prober = new FakeProber(new MediaInfo(Duration::seconds(3), null, null, null, null, null));

        (new MediaConverter(FfmpegBinary::default(), new FakeRunner([new ProcessOutcome(0, '')]), prober: $prober))->run(
            Pipeline::from('in.mp4'),
            $this->output,
            static function (ProgressEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        Assert::null($events[0]->fraction());
        Assert::same($events[array_key_last($events)]->fraction(), 1.0);
        Assert::same($events[array_key_last($events)]->outTime()->toSeconds(), 3.0);
    }

    public function completedProgressWithoutAProberHasNullFraction(): void
    {
        // fraction 1.0 on the Completed event requires BOTH the Completed
        // phase AND a known total duration. Without a prober the duration is
        // unknown — even the terminal event stays indeterminate.
        $events = [];

        (new MediaConverter(FfmpegBinary::default(), new FakeRunner([new ProcessOutcome(0, '')])))->run(
            Pipeline::from('in.mp4'),
            $this->output,
            static function (ProgressEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $last = $events[array_key_last($events)];
        Assert::same($last->phase(), ConversionPhase::Completed);
        Assert::null($last->fraction());
    }

    public function cancellableRunWithoutCancellationForwardsOutputAndCompletes(): void
    {
        // The output guard throws ONLY when the token is actually cancelled —
        // a live token on the happy path must let every chunk through.
        $runner = new FakeRunner(
            [new ProcessOutcome(0, '')],
            emit: [['out', "out_time_us=1000000\nprogress=continue\n"]],
        );
        $events = [];

        $result = (new MediaConverter(FfmpegBinary::default(), $runner))->run(
            Pipeline::from('in.mp4'),
            $this->output,
            static function (ProgressEvent $event) use (&$events): void {
                $events[] = $event;
            },
            new CancellationToken(),
        );

        Assert::same($result->outputPath(), $this->output);
        $samples = array_values(array_filter($events, static fn(ProgressEvent $event): bool => $event->outTime()->toMicros() > 0));
        Assert::same(count($samples), 1);
    }

    public function usesTheInjectedTimeoutsInsteadOfTheDefaults(): void
    {
        $runner = new FakeRunner([new ProcessOutcome(0, '')]);
        $converter = new MediaConverter(
            FfmpegBinary::default(),
            $runner,
            timeout: Duration::seconds(7),
            idleTimeout: Duration::seconds(3),
        );

        $converter->run(Pipeline::from('in.mp4'), $this->output);

        Assert::same($runner->timeouts[0][0]->toSeconds(), 7.0);
        Assert::same($runner->timeouts[0][1]->toSeconds(), 3.0);
    }

    public function timeoutMapsToTheTimeoutReason(): void
    {
        $converter = new MediaConverter(FfmpegBinary::default(), new FakeRunner([new ProcessOutcome(124, 'killed', timedOut: true)]));
        $caught = null;

        try {
            $converter->run(Pipeline::from('in.mp4'), $this->output);
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::Timeout);
    }

    public function nonZeroExitCleansItsStagingFilesAndPreservesExistingOutput(): void
    {
        file_put_contents($this->output, 'original');
        $converter = new MediaConverter(
            FfmpegBinary::default(),
            new FakeRunner([new ProcessOutcome(1, 'muxer error')], writeOnFailure: true, sidecars: ['partial.ts' => 'partial']),
        );
        $caught = null;

        try {
            $converter->run(Pipeline::from('in.mp4'), $this->output);
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::NonZeroExit);
        Assert::same(file_get_contents($this->output), 'original');
        Assert::false(is_file(dirname($this->output) . '/partial.ts'));
    }

    public function sourceMayEqualOutputBecauseFfmpegWritesToStaging(): void
    {
        file_put_contents($this->output, 'original-input');
        $runner = new FakeRunner([new ProcessOutcome(0, '')], outputContent: 'converted');
        $converter = new MediaConverter(FfmpegBinary::default(), $runner);

        $result = $converter->run(Pipeline::from($this->output), $this->output);

        Assert::same(file_get_contents($this->output), 'converted');
        Assert::same($result->inputBytes(), 14);
    }

    public function missingInputStderrMapsToNoInput(): void
    {
        $converter = new MediaConverter(FfmpegBinary::default(), new FakeRunner([new ProcessOutcome(1, "in.mp4: No such file or directory")]));
        $caught = null;

        try {
            $converter->run(Pipeline::from('in.mp4'), $this->output);
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::NoInput);
    }

    public function noInputDetectionIsCaseInsensitive(): void
    {
        $converter = new MediaConverter(FfmpegBinary::default(), new FakeRunner([new ProcessOutcome(1, 'in.mp4: no such file or directory')]));
        $caught = null;

        try {
            $converter->run(Pipeline::from('in.mp4'), $this->output);
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::NoInput);
    }

    public function exitCode127MapsToFfmpegNotExecutable(): void
    {
        $converter = new MediaConverter(FfmpegBinary::default(), new FakeRunner([new ProcessOutcome(127, 'not found')]));
        $caught = null;

        try {
            $converter->run(Pipeline::from('in.mp4'), $this->output);
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::FfmpegNotExecutable);
    }

    public function incompatiblePipelinePropagatesBeforeAnyProcess(): void
    {
        $runner = new FakeRunner([]);
        $converter = new MediaConverter(FfmpegBinary::default(), $runner);
        $caught = null;

        try {
            $converter->run(
                Pipeline::from('in.ts')->add(new Remux())->add(new Scale(width: 640)),
                $this->output,
            );
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::same($runner->callCount(), 0);
    }

    public function retryRepeatsTransientFailuresThenSucceeds(): void
    {
        $runner = new FakeRunner([
            new ProcessOutcome(1, 'HTTP 503 Service Unavailable'),
            new ProcessOutcome(1, 'Connection reset by peer'),
            new ProcessOutcome(0, ''),
        ]);
        $converter = new MediaConverter(
            FfmpegBinary::default(),
            $runner,
            retry: Retry::immediate(maxAttempts: 3),
        );

        $result = $converter->run(Pipeline::from('https://cdn/x.m3u8')->add(new Remux()), $this->output);

        Assert::same($runner->callCount(), 3);
        Assert::same($result->outputPath(), $this->output);
    }

    public function retryDoesNotRepeatADeterministicFailure(): void
    {
        $runner = new FakeRunner([new ProcessOutcome(1, 'Invalid argument')]);
        $converter = new MediaConverter(
            FfmpegBinary::default(),
            $runner,
            retry: Retry::immediate(maxAttempts: 3),
        );
        $caught = null;

        try {
            $converter->run(Pipeline::from('in.mp4'), $this->output);
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($runner->callCount(), 1);
    }

    public function exhaustedRetryStillThrowsConversionFailed(): void
    {
        $runner = new FakeRunner([
            new ProcessOutcome(1, 'HTTP 503 Service Unavailable'),
            new ProcessOutcome(1, 'HTTP 503 Service Unavailable'),
        ]);
        $converter = new MediaConverter(
            FfmpegBinary::default(),
            $runner,
            retry: Retry::immediate(maxAttempts: 2),
        );
        $caught = null;

        try {
            $converter->run(Pipeline::from('https://cdn/x.m3u8'), $this->output);
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::NonZeroExit);
        Assert::instanceOf($caught->getPrevious(), \Rasuvaeff\Retry\RetryExhausted::class);
        Assert::same($runner->callCount(), 2);
    }

    public function bulkheadGuardsEveryRun(): void
    {
        $bulkhead = new RecordingBulkhead();
        $converter = new MediaConverter(FfmpegBinary::default(), new FakeRunner([new ProcessOutcome(0, '')]), bulkhead: $bulkhead);

        $converter->run(Pipeline::from('in.mp4'), $this->output);

        Assert::same($bulkhead->calls, 1);
    }

    public function bulkheadFullPropagatesAsItself(): void
    {
        $converter = new MediaConverter(FfmpegBinary::default(), new FakeRunner([]), bulkhead: new FullBulkhead());
        $caught = null;

        try {
            $converter->run(Pipeline::from('in.mp4'), $this->output);
        } catch (BulkheadFullException $caught) {
        }

        Assert::notNull($caught);
    }

    public function drmEncryptedSourceIsRefusedBeforeAnyProcess(): void
    {
        $runner = new FakeRunner([]);
        $prober = new FakeProber(new MediaInfo(Duration::seconds(10), 1920, 1080, 'h264', 'aac', null, encrypted: true));
        $converter = new MediaConverter(FfmpegBinary::default(), $runner, prober: $prober);
        $caught = null;

        try {
            $converter->run(Pipeline::from('in.mp4'), $this->output);
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::Drm);
        Assert::same($caught->exitCode, 0);
        Assert::same($runner->callCount(), 0);
    }

    public function nonEncryptedSourceRunsNormallyWithAProber(): void
    {
        $runner = new FakeRunner([new ProcessOutcome(0, '')]);
        $prober = new FakeProber(new MediaInfo(Duration::seconds(10), 1920, 1080, 'h264', 'aac', null));
        $converter = new MediaConverter(FfmpegBinary::default(), $runner, prober: $prober);

        $result = $converter->run(Pipeline::from('in.mp4'), $this->output);

        Assert::same($result->outputPath(), $this->output);
    }

    public function concatProbesEverySegmentAndSumsTheirDurations(): void
    {
        $runner = new FakeRunner(
            [new ProcessOutcome(0, '')],
            emit: [['out', "out_time_us=15000000\nprogress=continue\n"]],
        );
        $prober = new FakeProber([
            new MediaInfo(Duration::seconds(10), 1920, 1080, 'h264', 'aac', null),
            new MediaInfo(Duration::seconds(20), 1920, 1080, 'h264', 'aac', null),
        ]);
        $converter = new MediaConverter(FfmpegBinary::default(), $runner, prober: $prober);
        $events = [];

        $converter->run(
            Pipeline::concat(['first.mp4', 'second.mp4']),
            $this->output,
            onProgress: static function (ProgressEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        Assert::same($prober->sources, ['first.mp4', 'second.mp4']);
        $sample = array_values(array_filter($events, static fn(ProgressEvent $event): bool => $event->outTime()->toMicros() > 0))[0];
        Assert::same($sample->fraction(), 0.5);
    }

    public function unknownConcatSegmentDurationKeepsProgressIndeterminate(): void
    {
        $runner = new FakeRunner(
            [new ProcessOutcome(0, '')],
            emit: [['out', "out_time_us=5000000\nprogress=continue\n"]],
        );
        $prober = new FakeProber([
            new MediaInfo(Duration::seconds(10), 1920, 1080, 'h264', 'aac', null),
            new MediaInfo(Duration::zero(), 1920, 1080, 'h264', 'aac', null),
        ]);
        $converter = new MediaConverter(FfmpegBinary::default(), $runner, prober: $prober);
        $events = [];

        $converter->run(
            Pipeline::concat(['first.mp4', 'live.mp4']),
            $this->output,
            onProgress: static function (ProgressEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $sample = array_values(array_filter($events, static fn(ProgressEvent $event): bool => $event->outTime()->toMicros() > 0))[0];
        Assert::null($sample->fraction());
    }

    public function trimUsesItsOutputDurationForProgress(): void
    {
        $runner = new FakeRunner(
            [new ProcessOutcome(0, '')],
            emit: [['out', "out_time_us=5000000\nprogress=end\n"]],
        );
        $prober = new FakeProber(new MediaInfo(Duration::seconds(10), 1920, 1080, 'h264', 'aac', null));
        $converter = new MediaConverter(FfmpegBinary::default(), $runner, prober: $prober);
        $events = [];

        $converter->run(
            Pipeline::from('in.mp4')->add(new Trim(Duration::seconds(2), Duration::seconds(7))),
            $this->output,
            onProgress: static function (ProgressEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $sample = array_values(array_filter($events, static fn(ProgressEvent $event): bool => $event->outTime()->toMicros() > 0))[0];
        Assert::same($sample->fraction(), 1.0);
    }

    public function thumbnailProgressIsIndeterminate(): void
    {
        $runner = new FakeRunner(
            [new ProcessOutcome(0, '')],
            emit: [['out', "out_time_us=1000000\nprogress=end\n"]],
        );
        $prober = new FakeProber(new MediaInfo(Duration::seconds(10), 1920, 1080, 'h264', 'aac', null));
        $converter = new MediaConverter(FfmpegBinary::default(), $runner, prober: $prober);
        $events = [];

        $converter->run(
            Pipeline::from('in.mp4')->add(new Thumbnail(Duration::seconds(2))),
            $this->output,
            onProgress: static function (ProgressEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $sample = array_values(array_filter($events, static fn(ProgressEvent $event): bool => $event->outTime()->toMicros() > 0))[0];
        Assert::null($sample->fraction());
    }

    public function secondaryMediaInputsAreProbedAndIncludedInInputBytes(): void
    {
        $video = $this->output . '.video';
        $audio = $this->output . '.audio';
        file_put_contents($video, 'video');
        file_put_contents($audio, 'audio-data');
        $prober = new FakeProber([
            new MediaInfo(Duration::seconds(10), 1920, 1080, 'h264', 'aac', null),
            new MediaInfo(Duration::seconds(4), null, null, null, 'aac', null),
        ]);
        $converter = new MediaConverter(FfmpegBinary::default(), new FakeRunner([new ProcessOutcome(0, '')]), prober: $prober);

        $result = $converter->run(Pipeline::from($video)->add(new ReplaceAudio($audio)), $this->output);

        Assert::same($prober->sources, [$video, $audio]);
        Assert::same($result->inputBytes(), 15);
        @unlink($video);
        @unlink($audio);
    }

    public function packageResultIncludesSidecarsAndTheirBytes(): void
    {
        $sidecar = pathinfo($this->output, PATHINFO_FILENAME) . '0.ts';
        $sidecarPath = dirname($this->output) . '/' . $sidecar;
        $runner = new FakeRunner(
            [new ProcessOutcome(0, '')],
            outputContent: 'manifest',
            sidecars: [$sidecar => 'segment-data'],
        );
        $converter = new MediaConverter(FfmpegBinary::default(), $runner);

        $result = $converter->run(Pipeline::from('in.mp4')->add(new PackageHls()), $this->output);

        Assert::same($result->outputArtifacts(), [$this->output, $sidecarPath]);
        Assert::same($result->outputBytes(), 20);
        @unlink($sidecarPath);
    }

    public function progressCallbackReceivesADeterminateFractionWhenTheProberKnowsTheDuration(): void
    {
        $runner = new FakeRunner(
            [new ProcessOutcome(0, '')],
            emit: [['out', "frame=100\nfps=25\nout_time_us=5000000\nspeed=1.0x\nprogress=continue\n"]],
        );
        $prober = new FakeProber(new MediaInfo(Duration::seconds(10), 1920, 1080, 'h264', 'aac', null));
        $converter = new MediaConverter(FfmpegBinary::default(), $runner, prober: $prober);

        $events = [];
        $converter->run(Pipeline::from('in.mp4'), $this->output, onProgress: static function (ProgressEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $sample = array_values(array_filter($events, static fn(ProgressEvent $event): bool => $event->frame() !== null))[0];
        Assert::true($sample->isDeterminate());
        Assert::same($sample->fraction(), 0.5);
        Assert::same($sample->frame(), 100);
        Assert::same($sample->fps(), 25.0);
        Assert::same($sample->speed(), 1.0);
    }

    public function progressWithoutAProberYieldsIndeterminateSamples(): void
    {
        $runner = new FakeRunner(
            [new ProcessOutcome(0, '')],
            emit: [['out', "out_time_us=1000000\nprogress=continue\n"]],
        );
        $converter = new MediaConverter(FfmpegBinary::default(), $runner);

        $events = [];
        $converter->run(Pipeline::from('in.mp4'), $this->output, onProgress: static function (ProgressEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $sample = array_values(array_filter($events, static fn(ProgressEvent $event): bool => $event->outTime()->toMicros() > 0))[0];
        Assert::false($sample->isDeterminate());
        Assert::null($sample->fraction());
    }

    public function progressReportingFlagsAreAddedOnlyWhenOnProgressIsGiven(): void
    {
        $runner = new FakeRunner([new ProcessOutcome(0, ''), new ProcessOutcome(0, '')]);
        $converter = new MediaConverter(FfmpegBinary::default(), $runner);

        $converter->run(Pipeline::from('in.mp4'), $this->output);
        Assert::false(in_array('-progress', $runner->calls[0], true));

        $converter->run(Pipeline::from('in.mp4'), $this->output, onProgress: static function (): void {});
        // Exact position: spliced right after argv[0] (the ffmpeg path), never
        // replacing or displacing the pipeline's own leading global options.
        Assert::same(
            array_slice($runner->calls[1], 0, 5),
            ['/usr/bin/ffmpeg', '-progress', 'pipe:1', '-nostats', '-hide_banner'],
        );
    }

    public function transientDetectionMatchesOnlyTransientNonZeroExits(): void
    {
        Assert::true(MediaConverter::isTransientFailure(new ConversionFailed(ConversionFailureReason::NonZeroExit, 1, 'HTTP 502 Bad Gateway')));
        Assert::true(MediaConverter::isTransientFailure(new ConversionFailed(ConversionFailureReason::NonZeroExit, 1, 'Connection timed out')));
        Assert::true(MediaConverter::isTransientFailure(new ConversionFailed(ConversionFailureReason::NonZeroExit, 1, 'http 503 service unavailable')));
        Assert::false(MediaConverter::isTransientFailure(new ConversionFailed(ConversionFailureReason::NonZeroExit, 1, 'Invalid data')));
        Assert::false(MediaConverter::isTransientFailure(new ConversionFailed(ConversionFailureReason::Timeout, 124, 'HTTP 503')));
        Assert::false(MediaConverter::isTransientFailure(new \RuntimeException('HTTP 503')));
    }
}
