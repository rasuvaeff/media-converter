<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests;

use Rasuvaeff\MediaConverter\ConversionCancelled;
use Rasuvaeff\MediaConverter\MediaConverterException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ConversionCancelled::class)]
final class ConversionCancelledTest
{
    public function carriesADescriptiveMessage(): void
    {
        Assert::string((new ConversionCancelled())->getMessage())->contains('cancelled');
    }

    public function isARuntimeExceptionSoCatchMatchesOnType(): void
    {
        Assert::instanceOf(new ConversionCancelled(), \RuntimeException::class);
    }

    public function implementsTheMediaConverterExceptionMarker(): void
    {
        Assert::instanceOf(new ConversionCancelled(), MediaConverterException::class);
    }
}
