<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\ExtractSubtitles;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ExtractSubtitles::class)]
final class ExtractSubtitlesTest
{
    public function mapsTheDefaultFirstSubtitleStream(): void
    {
        $spec = new CommandSpec();
        (new ExtractSubtitles())->applyTo($spec);

        Assert::same($spec->subtitleOutput(), '0:s:0');
    }

    public function mapsAGivenStreamIndex(): void
    {
        $spec = new CommandSpec();
        (new ExtractSubtitles(2))->applyTo($spec);

        Assert::same($spec->subtitleOutput(), '0:s:2');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANegativeStreamIndex(): void
    {
        new ExtractSubtitles(-1);
    }
}
