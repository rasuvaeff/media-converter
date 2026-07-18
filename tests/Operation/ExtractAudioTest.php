<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\ExtractAudio;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ExtractAudio::class)]
final class ExtractAudioTest
{
    public function dropsVideoAndEncodesAudioWithBitrate(): void
    {
        $spec = new CommandSpec();
        (new ExtractAudio('libmp3lame', 192))->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-vn', '-c:a', 'libmp3lame', '-b:a', '192k']);
    }

    public function omitsBitrateWhenNull(): void
    {
        $spec = new CommandSpec();
        (new ExtractAudio('copy', null))->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-vn', '-c:a', 'copy']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsEmptyCodec(): void
    {
        new ExtractAudio('');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonPositiveBitrate(): void
    {
        new ExtractAudio('aac', 0);
    }
}
