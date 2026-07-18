<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

use Rasuvaeff\Duration\Duration;

/**
 * Runs an argv as a subprocess under wall-clock and idle timeouts, streaming
 * its output. The seam that lets {@see MediaConverter} be tested without a
 * real ffmpeg — the shipped implementation is {@see SymfonyProcessRunner}.
 *
 * The runner keeps a bounded tail of STDERR only (so it stays clean for error
 * classification even when progress is routed to stdout) and forwards every
 * chunk to $onOutput with an `'out'`/`'err'` discriminator (the hook the
 * progress parser plugs into).
 *
 * @api
 */
interface RunsProcess
{
    /**
     * @param list<string>                            $argv        the command and its arguments
     * @param Duration                                $timeout     wall-clock limit (zero = none)
     * @param Duration                                $idleTimeout no-output limit (zero = none)
     * @param callable(string $type, string $chunk): void $onOutput  called per chunk; $type is 'out' or 'err'
     */
    public function run(array $argv, Duration $timeout, Duration $idleTimeout, callable $onOutput): ProcessOutcome;
}
