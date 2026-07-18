<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests;

use Rasuvaeff\MediaConverter\CancellationToken;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CancellationToken::class)]
final class CancellationTokenTest
{
    public function startsActiveAndCanBeCancelled(): void
    {
        $token = new CancellationToken();

        Assert::false($token->isCancellationRequested());
        $token->cancel();
        Assert::true($token->isCancellationRequested());
    }
}
