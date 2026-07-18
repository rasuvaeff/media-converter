<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Progress;

use Rasuvaeff\MediaConverter\Progress\ConversionPhase;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ConversionPhase::class)]
final class ConversionPhaseTest
{
    public function exposesStableWireValues(): void
    {
        Assert::same(ConversionPhase::Probing->value, 'probing');
        Assert::same(ConversionPhase::Running->value, 'running');
        Assert::same(ConversionPhase::Committing->value, 'committing');
        Assert::same(ConversionPhase::Completed->value, 'completed');
    }
}
