<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Internal;

use Rasuvaeff\MediaConverter\Internal\OutputLayout;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(OutputLayout::class)]
final class OutputLayoutTest
{
    public function singleHasNoHlsConfiguration(): void
    {
        $layout = OutputLayout::single();

        Assert::false($layout->isHls());
        Assert::null($layout->hlsSegmentPattern());
    }

    public function hlsCarriesItsSegmentPattern(): void
    {
        $layout = OutputLayout::hls('segment-%03d.ts');

        Assert::true($layout->isHls());
        Assert::same($layout->hlsSegmentPattern(), 'segment-%03d.ts');
    }

    public function dashIsNotHls(): void
    {
        $layout = OutputLayout::dash();

        Assert::false($layout->isHls());
        Assert::true($layout->isDash());
        Assert::true($layout->isPackage());
    }
}
