<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\AddArtwork;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(AddArtwork::class)]
final class AddArtworkTest
{
    public function forVideoAppendsTheCoverAsASecondVideoStream(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('in.mp4');
        AddArtwork::forVideo('cover.jpg')->applyTo($spec);

        Assert::same($spec->inputs(), [
            ['source' => 'in.mp4', 'options' => []],
            ['source' => 'cover.jpg', 'options' => []],
        ]);
        Assert::same($spec->videoOutput(), '0:v');
        Assert::same($spec->audioOutput(), '0:a?');
        Assert::same($spec->maps(), ['1:v']);
        Assert::same($spec->outputOptions(), ['-c:v:1', 'copy', '-disposition:v:1', 'attached_pic']);
        Assert::same($spec->artworkOwners(), [AddArtwork::class]);
    }

    public function forVideoUsesSoftDefaultsSoAGraphFedVideoStillWins(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('in.mp4');
        AddArtwork::forVideo('cover.jpg')->applyTo($spec);

        Assert::false($spec->videoSelectionExplicit());
        Assert::false($spec->audioSelectionExplicit());
    }

    public function forAudioMapsTheAudioExplicitlyAndTagsId3v2(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('in.mp3');
        AddArtwork::forAudio('cover.jpg')->applyTo($spec);

        Assert::same($spec->videoOutput(), null);
        Assert::same($spec->audioOutput(), '0:a');
        Assert::true($spec->audioSelectionExplicit());
        Assert::same($spec->maps(), ['1:v']);
        Assert::same($spec->outputOptions(), [
            '-c:v:0', 'copy',
            '-disposition:v:0', 'attached_pic',
            '-id3v2_version', '3',
        ]);
    }

    public function forAudioWithoutId3v2OmitsTheVersionOption(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('in.flac');
        AddArtwork::forAudio('cover.png', id3v2: false)->applyTo($spec);

        Assert::false(in_array('-id3v2_version', $spec->outputOptions(), true));
    }

    public function referencesTheCorrectInputIndexWhenNotSecond(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('in.mp4');
        $spec->addInput('extra.m4a');
        AddArtwork::forVideo('cover.jpg')->applyTo($spec);

        Assert::same($spec->maps(), ['2:v']);
    }

    public function theCoverInputStaysOffTheProbeAndTimelineLists(): void
    {
        // A still image has no duration; probing it or letting it join the
        // timeline sources would skew progress totals.
        $spec = new CommandSpec();
        $spec->addInput('in.mp4');
        AddArtwork::forVideo('cover.jpg')->applyTo($spec);

        Assert::same($spec->probeSources(), ['in.mp4']);
        Assert::same($spec->timelineSources(), ['in.mp4']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function forAudioRejectsAnEmptyImage(): void
    {
        AddArtwork::forAudio('');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function forVideoRejectsAnEmptyImage(): void
    {
        AddArtwork::forVideo('');
    }
}
