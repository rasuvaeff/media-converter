<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Support;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\ProcessOutcome;
use Rasuvaeff\MediaConverter\RunsProcess;

/**
 * Test double for {@see RunsProcess}: returns queued outcomes, records every
 * argv it was given, and writes the output file on a successful outcome (so
 * the engine's output-size probe sees a real file).
 */
final class FakeRunner implements RunsProcess
{
    /** @var list<list<string>> */
    public array $calls = [];

    /** @var list<array{0: Duration, 1: Duration}> */
    public array $timeouts = [];

    /**
     * @param list<ProcessOutcome> $outcomes one per expected run, in order
     * @param list<array{0: string, 1: string}> $emit ('out'|'err', chunk) pairs forwarded to $onOutput on every run() call
     * @param array<string, string> $sidecars filename => content, written beside a successful output
     */
    public function __construct(
        private array $outcomes,
        private readonly string $outputContent = 'result-bytes',
        private readonly array $emit = [],
        private readonly bool $writeOnFailure = false,
        private readonly array $sidecars = [],
    ) {}

    #[\Override]
    public function run(array $argv, Duration $timeout, Duration $idleTimeout, callable $onOutput): ProcessOutcome
    {
        $this->calls[] = $argv;
        $this->timeouts[] = [$timeout, $idleTimeout];

        foreach ($this->emit as [$type, $chunk]) {
            $onOutput($type, $chunk);
        }

        $outcome = array_shift($this->outcomes);

        if ($outcome === null) {
            throw new \LogicException('FakeRunner ran out of queued outcomes');
        }

        $outputPath = $argv[array_key_last($argv)];

        if ($outcome->isSuccess() || $this->writeOnFailure) {
            file_put_contents($outputPath, $this->outputContent);
        }

        if ($outcome->isSuccess() || $this->writeOnFailure) {
            foreach ($this->sidecars as $filename => $content) {
                file_put_contents(dirname($outputPath) . '/' . $filename, $content);
            }
        }

        return $outcome;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
