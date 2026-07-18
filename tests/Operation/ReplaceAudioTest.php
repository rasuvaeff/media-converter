<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\ReplaceAudio;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ReplaceAudio::class)]
final class ReplaceAudioTest
{
    public function addsTheAudioInputAndMapsVideoPlusTheNewAudio(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('video.mp4');
        (new ReplaceAudio('audio.mp3'))->applyTo($spec);

        Assert::same($spec->inputs(), [
            ['source' => 'video.mp4', 'options' => []],
            ['source' => 'audio.mp3', 'options' => []],
        ]);
        Assert::same($spec->videoOutput(), '0:v');
        Assert::same($spec->audioOutput(), '1:a');
        Assert::same($spec->maps(), []);
        Assert::same($spec->probeSources(), ['video.mp4', 'audio.mp3']);
        Assert::same($spec->timelineSources(), ['video.mp4']);
        Assert::same($spec->outputOptions(), ['-shortest']);
    }

    public function omitsShortestWhenDisabled(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('video.mp4');
        (new ReplaceAudio('audio.mp3', shortest: false))->applyTo($spec);

        Assert::same($spec->outputOptions(), []);
    }

    public function referencesTheCorrectInputIndexWhenNotFirst(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('video.mp4');
        $spec->addInput('watermark.png');
        (new ReplaceAudio('audio.mp3'))->applyTo($spec);

        Assert::same($spec->videoOutput(), '0:v');
        Assert::same($spec->audioOutput(), '2:a');
    }

    public function shortestMakesProgressIndeterminate(): void
    {
        // -shortest cuts the output at the shorter stream, so the input's
        // duration no longer predicts the output's — no fabricated percentage.
        $spec = new CommandSpec();
        $spec->addInput('video.mp4');
        (new ReplaceAudio('audio.mp3'))->applyTo($spec);

        Assert::null($spec->outputDuration(Duration::seconds(10)));
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptyAudioSource(): void
    {
        new ReplaceAudio('');
    }
}
