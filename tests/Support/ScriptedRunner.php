<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Support;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\ProcessOutcome;
use Rasuvaeff\MediaConverter\RunsProcess;

/**
 * Minimal {@see RunsProcess} test double: returns queued outcomes and
 * forwards scripted output chunks, with NO file side effects — unlike
 * {@see FakeRunner} it never writes to the last argv entry, which matters for
 * ffprobe calls (the last argv entry is the SOURCE, not an output path).
 */
final class ScriptedRunner implements RunsProcess
{
    /** @var list<list<string>> */
    public array $calls = [];

    /** @var list<array{0: Duration, 1: Duration}> */
    public array $timeouts = [];

    /**
     * @param list<ProcessOutcome> $outcomes one per expected run, in order
     * @param list<array{0: string, 1: string}> $emit ('out'|'err', chunk) pairs forwarded to $onOutput on every run() call
     */
    public function __construct(
        private array $outcomes,
        private readonly array $emit = [],
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
            throw new \LogicException('ScriptedRunner ran out of queued outcomes');
        }

        return $outcome;
    }
}
