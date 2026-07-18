<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Internal;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Internal\ArgBuilder;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ArgBuilder::class)]
final class ArgBuilderTest
{
    private FfmpegBinary $binary;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->binary = FfmpegBinary::default();
    }

    public function emitsInputOptionsBeforeTheInput(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('https://cdn.example/v.m3u8', ['-headers', 'Referer: https://site/']);

        $argv = ArgBuilder::build($this->binary, $spec, 'out.mp4');

        Assert::same($argv, [
            '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
            '-headers', 'Referer: https://site/',
            '-i', 'https://cdn.example/v.m3u8',
            'out.mp4',
        ]);
    }

    public function ordersStreamCopyFiltersMapsThenOutputOptions(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('a.mkv');
        $spec->addInput('b.png');
        $spec->addVideoFilter('scale=1280:-2');
        $spec->addAudioFilter('loudnorm=I=-16');
        $spec->addMap('0:v');
        $spec->addOutputOption('-preset', 'veryfast');

        $argv = ArgBuilder::build($this->binary, $spec, 'out.mp4');

        Assert::same($argv, [
            '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
            '-i', 'a.mkv',
            '-i', 'b.png',
            '-vf', 'scale=1280:-2',
            '-af', 'loudnorm=I=-16',
            '-map', '0:v',
            '-preset', 'veryfast',
            'out.mp4',
        ]);
    }

    public function emitsFilterComplexSegmentsJoinedBySemicolons(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('a.mp4');
        $spec->addInput('logo.png');
        $spec->addFilterComplexSegment('[1:v]format=rgba[wm]');
        $spec->addFilterComplexSegment('[0:v][wm]overlay=0:0[out]');
        $spec->addMap('[out]');

        $argv = ArgBuilder::build($this->binary, $spec, 'out.mp4');

        Assert::same($argv, [
            '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
            '-i', 'a.mp4',
            '-i', 'logo.png',
            '-filter_complex', '[1:v]format=rgba[wm];[0:v][wm]overlay=0:0[out]',
            '-map', '[out]',
            'out.mp4',
        ]);
    }

    public function emitsSemanticStreamSlotsBeforeAllAdditionalMaps(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('in.mkv');
        $spec->setVideoOutput('0:v:1');
        $spec->setAudioOutput('0:a:2');
        $spec->setSubtitleOutput('0:s:3');
        $spec->addMap('0:d:0');
        $spec->addMap('0:t:0');

        $argv = ArgBuilder::build($this->binary, $spec, 'out.mkv');

        Assert::same(array_slice($argv, -11), [
            '-map', '0:v:1',
            '-map', '0:a:2',
            '-map', '0:s:3',
            '-map', '0:d:0',
            '-map', '0:t:0',
            'out.mkv',
        ]);
    }

    public function streamCopyRendersAsCCopy(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('in.ts');
        $spec->requestStreamCopy();

        $argv = ArgBuilder::build($this->binary, $spec, 'out.mp4');

        Assert::same(array_slice($argv, -5), ['-i', 'in.ts', '-c', 'copy', 'out.mp4']);
    }

    #[ExpectException(\LogicException::class)]
    public function rejectsASpecWithNoInput(): void
    {
        ArgBuilder::build($this->binary, new CommandSpec(), 'out.mp4');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptyOutputPath(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('in.mp4');

        ArgBuilder::build($this->binary, $spec, '');
    }
}
