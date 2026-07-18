<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\Transcode;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Transcode::class)]
final class TranscodeTest
{
    public function emitsCodecAndBitrateOptions(): void
    {
        $spec = new CommandSpec();
        (new Transcode(videoCodec: 'libx264', audioCodec: 'aac', videoBitrateKbps: 2_500, audioBitrateKbps: 128))->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-c:v', 'libx264', '-c:a', 'aac', '-b:v', '2500k', '-b:a', '128k']);
    }

    public function emitsOnlyTheGivenParameters(): void
    {
        $spec = new CommandSpec();
        (new Transcode(audioCodec: 'libopus'))->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-c:a', 'libopus']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAllNull(): void
    {
        new Transcode();
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsEmptyVideoCodec(): void
    {
        new Transcode(videoCodec: '');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonPositiveVideoBitrate(): void
    {
        new Transcode(videoBitrateKbps: 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAZeroAudioBitrate(): void
    {
        new Transcode(videoCodec: 'libx264', audioBitrateKbps: 0);
    }
}
