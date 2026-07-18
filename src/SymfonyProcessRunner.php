<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

use Rasuvaeff\Duration\Duration;

/**
 * Public subprocess runner backed by `symfony/process`.
 *
 * @api
 */
final readonly class SymfonyProcessRunner implements RunsProcess
{
    private Internal\SymfonyProcessRunner $runner;

    public function __construct()
    {
        $this->runner = new Internal\SymfonyProcessRunner();
    }

    #[\Override]
    public function run(array $argv, Duration $timeout, Duration $idleTimeout, callable $onOutput): ProcessOutcome
    {
        return $this->runner->run($argv, $timeout, $idleTimeout, $onOutput);
    }
}
