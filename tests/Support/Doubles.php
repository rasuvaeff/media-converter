<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Support;

use Rasuvaeff\Bulkhead\Bulkhead;
use Rasuvaeff\Bulkhead\BulkheadFullException;
use Rasuvaeff\MediaConverter\MediaInfo;
use Rasuvaeff\MediaConverter\ProbesMedia;
use Rasuvaeff\MediaConverter\ProcessOutcome;
use Rasuvaeff\MediaConverter\RunsProcess;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;

use function Rasuvaeff\Understudy\when;

/**
 * Understudy doubles shared by the converter, probe and cache suites.
 *
 * @internal
 */
final class Doubles
{
    /**
     * Returns the queued outcomes in order and writes the output file on a
     * successful one (so the engine's output-size probe sees a real file).
     * Running out of outcomes is a test bug and throws.
     *
     * @param list<ProcessOutcome> $outcomes one per expected run, in order
     * @param list<array{0: string, 1: string}> $emit ('out'|'err', chunk) pairs forwarded to $onOutput on every run
     * @param array<string, string> $sidecars filename => content, written beside a successful output
     */
    public static function runner(
        array $outcomes,
        string $outputContent = 'result-bytes',
        array $emit = [],
        bool $writeOnFailure = false,
        array $sidecars = [],
    ): RunsProcess {
        return self::runnerDouble(
            outcomes: $outcomes,
            emit: $emit,
            outputContent: $outputContent,
            writeOnFailure: $writeOnFailure,
            sidecars: $sidecars,
            writesOutput: true,
        );
    }

    /**
     * A runner with NO file side effects: the last argv entry of an ffprobe
     * call is the SOURCE, not an output path, so it must never be written to.
     *
     * @param list<ProcessOutcome> $outcomes one per expected run, in order
     * @param list<array{0: string, 1: string}> $emit ('out'|'err', chunk) pairs forwarded to $onOutput on every run
     */
    public static function probeRunner(array $outcomes, array $emit = []): RunsProcess
    {
        return self::runnerDouble(
            outcomes: $outcomes,
            emit: $emit,
            outputContent: '',
            writeOnFailure: false,
            sidecars: [],
            writesOutput: false,
        );
    }

    /**
     * @param MediaInfo|list<MediaInfo> $info one value per probe, the last one repeats
     */
    public static function prober(MediaInfo|array $info): ProbesMedia
    {
        $infos = $info instanceof MediaInfo ? [$info] : $info;
        $prober = Understudy::for(ProbesMedia::class);
        when(fn() => $prober->probe(Arg::any()))->returns(...$infos);

        return $prober;
    }

    /**
     * Takes a slot per call and delegates straight through.
     */
    public static function bulkhead(): Bulkhead
    {
        $bulkhead = Understudy::for(Bulkhead::class);
        when(fn() => $bulkhead->call(Arg::any()))
            ->answers(static fn(Invocation $call): mixed => $call->arg('callback')());
        when(fn() => $bulkhead->availableSlots())->returns(1);

        return $bulkhead;
    }

    /**
     * Always full: every call throws {@see BulkheadFullException}.
     */
    public static function fullBulkhead(): Bulkhead
    {
        $bulkhead = Understudy::for(Bulkhead::class);
        when(fn() => $bulkhead->call(Arg::any()))->throws(new BulkheadFullException('media-converter', 1));
        when(fn() => $bulkhead->availableSlots())->returns(0);

        return $bulkhead;
    }

    /**
     * @return list<Invocation> every run() the double received, in order
     */
    public static function runs(RunsProcess $runner): array
    {
        return Understudy::calls(fn() => $runner->run(Arg::any(), Arg::any(), Arg::any(), Arg::any()));
    }

    /**
     * @return list<string> the argv of the n-th run
     */
    public static function argv(RunsProcess $runner, int $run): array
    {
        /** @var list<string> */
        return self::runs($runner)[$run]->arg('argv');
    }

    /**
     * @return list<string> the sources passed to probe(), in order
     */
    public static function probed(ProbesMedia $prober): array
    {
        return array_map(
            static fn(Invocation $call): string => $call->arg('source'),
            Understudy::calls(fn() => $prober->probe(Arg::any())),
        );
    }

    /**
     * @param list<ProcessOutcome> $outcomes
     * @param list<array{0: string, 1: string}> $emit
     * @param array<string, string> $sidecars
     */
    private static function runnerDouble(
        array $outcomes,
        array $emit,
        string $outputContent,
        bool $writeOnFailure,
        array $sidecars,
        bool $writesOutput,
    ): RunsProcess {
        $runner = Understudy::for(RunsProcess::class);
        when(fn() => $runner->run(Arg::any(), Arg::any(), Arg::any(), Arg::any()))
            ->answers(static function (Invocation $call) use (&$outcomes, $emit, $outputContent, $writeOnFailure, $sidecars, $writesOutput): ProcessOutcome {
                $onOutput = $call->arg('onOutput');
                foreach ($emit as [$type, $chunk]) {
                    $onOutput($type, $chunk);
                }

                $outcome = array_shift($outcomes);
                if ($outcome === null) {
                    throw new \LogicException('the runner double ran out of queued outcomes');
                }

                if ($writesOutput && ($outcome->isSuccess() || $writeOnFailure)) {
                    /** @var list<string> $argv */
                    $argv = $call->arg('argv');
                    $outputPath = $argv[array_key_last($argv)];
                    file_put_contents($outputPath, $outputContent);
                    foreach ($sidecars as $filename => $content) {
                        file_put_contents(dirname($outputPath) . '/' . $filename, $content);
                    }
                }

                return $outcome;
            });

        return $runner;
    }
}
