<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\PackageDash;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PackageDash::class)]
final class PackageDashTest
{
    public function usesTheDefaultSegmentDuration(): void
    {
        $spec = new CommandSpec();
        (new PackageDash())->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-f', 'dash', '-seg_duration', '5']);
    }

    public function usesACustomSegmentDuration(): void
    {
        $spec = new CommandSpec();
        (new PackageDash(2))->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-f', 'dash', '-seg_duration', '2']);
    }

    public function marksTheDashOutputLayout(): void
    {
        // Without the layout mark the transaction would treat the manifest as
        // a single-file output and never stage the .m4s sidecars.
        $spec = new CommandSpec();
        (new PackageDash())->applyTo($spec);

        Assert::true($spec->outputLayout()->isDash());
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANonPositiveSegmentDuration(): void
    {
        new PackageDash(0);
    }
}
