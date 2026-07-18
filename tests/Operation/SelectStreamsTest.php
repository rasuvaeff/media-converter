<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\SelectStreams;
use Rasuvaeff\MediaConverter\Pipeline;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SelectStreams::class)]
final class SelectStreamsTest
{
    public function selectsVideoAudioAndSubtitleStreams(): void
    {
        $argv = Pipeline::from('in.mkv')->add(new SelectStreams(videoIndex: 1, audioIndex: 2, subtitleIndex: 0))->toArgv(FfmpegBinary::default(), 'out.mkv');

        Assert::same(array_slice($argv, -7), [
            '-map', '0:v:1',
            '-map', '0:a:2',
            '-map', '0:s:0',
            'out.mkv',
        ]);
    }

    public function selectsOnlyAVideoStream(): void
    {
        $spec = new CommandSpec();
        (new SelectStreams(videoIndex: 0))->applyTo($spec);

        Assert::same($spec->videoOutput(), '0:v:0');
        Assert::null($spec->audioOutput());
        Assert::null($spec->subtitleOutput());
    }

    public function selectsOnlyAnAudioStream(): void
    {
        $spec = new CommandSpec();
        (new SelectStreams(audioIndex: 1))->applyTo($spec);

        Assert::same($spec->audioOutput(), '0:a:1');
        Assert::null($spec->videoOutput());
    }

    public function selectsOnlyASubtitleStream(): void
    {
        $spec = new CommandSpec();
        (new SelectStreams(subtitleIndex: 0))->applyTo($spec);

        Assert::same($spec->subtitleOutput(), '0:s:0');
        Assert::null($spec->videoOutput());
    }

    public function appendsAnOptionalSuffixToEveryMap(): void
    {
        $spec = new CommandSpec();
        (new SelectStreams(videoIndex: 0, audioIndex: 1, subtitleIndex: 2, optional: true))->applyTo($spec);

        Assert::same($spec->videoOutput(), '0:v:0?');
        Assert::same($spec->audioOutput(), '0:a:1?');
        Assert::same($spec->subtitleOutput(), '0:s:2?');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANegativeVideoIndex(): void
    {
        new SelectStreams(videoIndex: -1);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNoIndexes(): void
    {
        new SelectStreams();
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeIndexes(): void
    {
        new SelectStreams(audioIndex: -1);
    }
}
