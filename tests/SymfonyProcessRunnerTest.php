<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\SymfonyProcessRunner;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SymfonyProcessRunner::class)]
final class SymfonyProcessRunnerTest
{
    public function publicRunnerExecutesAProcess(): void
    {
        $stdout = '';
        $outcome = (new SymfonyProcessRunner())->run(
            ['php', '-r', 'fwrite(STDOUT, "public-runner");'],
            Duration::seconds(2),
            Duration::zero(),
            static function (string $type, string $chunk) use (&$stdout): void {
                if ($type === 'out') {
                    $stdout .= $chunk;
                }
            },
        );

        Assert::true($outcome->isSuccess());
        Assert::same($stdout, 'public-runner');
    }
}
