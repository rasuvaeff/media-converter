<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Benchmarks;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Internal\ProgressReportParser;
use Rasuvaeff\MediaConverter\Operation\Scale;
use Rasuvaeff\MediaConverter\Operation\Transcode;
use Rasuvaeff\MediaConverter\Operation\Trim;
use Rasuvaeff\MediaConverter\Pipeline;
use Testo\Bench;

/**
 * The two CPU-bound hot paths that run without ffmpeg:
 *
 * - {@see Pipeline::toArgv()} applies every operation to a fresh CommandSpec,
 *   validates the composition, and renders the argv — the cost a caller pays
 *   per conversion (and per inspection). Measured against a single-operation
 *   pipeline to show how composition depth drives the cost.
 * - {@see ProgressReportParser::feed()} parses ffmpeg's `-progress pipe:1`
 *   key=value stream; it runs once per stdout chunk during a live run and can
 *   see hundreds of reports per second. Measured against a short stream.
 */
final class PipelineBench
{
    #[Bench(callables: ['single_operation' => [self::class, 'buildArgvForSingleOperationPipeline']])]
    public static function buildArgvForComposedPipeline(): array
    {
        return Pipeline::from('input.mp4')
            ->add(new Trim(Duration::seconds(5), Duration::seconds(65)))
            ->add(new Scale(1280, 720))
            ->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac', videoBitrateKbps: 4_000))
            ->toArgv(FfmpegBinary::default(), 'output.mp4');
    }

    public static function buildArgvForSingleOperationPipeline(): array
    {
        return Pipeline::from('input.mp4')
            ->add(new Scale(1280, 720))
            ->toArgv(FfmpegBinary::default(), 'output.mp4');
    }

    #[Bench(callables: ['short_stream' => [self::class, 'parseShortProgressStream']])]
    public static function parseHundredReportProgressStream(): array
    {
        return (new ProgressReportParser())->feed(self::progressStream(100));
    }

    public static function parseShortProgressStream(): array
    {
        return (new ProgressReportParser())->feed(self::progressStream(5));
    }

    private static function progressStream(int $reports): string
    {
        $report = "frame=%d\nfps=%d\nout_time_us=%d\nspeed=%.2fx\nprogress=continue\n";
        $stream = '';

        for ($i = 1; $i <= $reports; ++$i) {
            $stream .= sprintf($report, $i * 24, 24, $i * 1_000_000, 1.5);
        }

        return $stream;
    }
}
