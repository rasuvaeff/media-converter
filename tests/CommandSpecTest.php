<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\ConversionFailed;
use Rasuvaeff\MediaConverter\ConversionFailureReason;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CommandSpec::class)]
final class CommandSpecTest
{
    public function addInputReturnsTheZeroBasedIndex(): void
    {
        $spec = new CommandSpec();

        Assert::same($spec->addInput('a.mp4'), 0);
        Assert::same($spec->addInput('b.png'), 1);
        Assert::same($spec->inputs(), [
            ['source' => 'a.mp4', 'options' => []],
            ['source' => 'b.png', 'options' => []],
        ]);
        Assert::same($spec->inputSources(), ['a.mp4', 'b.png']);
        Assert::same($spec->probeSources(), ['a.mp4', 'b.png']);
        Assert::same($spec->timelineSources(), ['a.mp4', 'b.png']);
    }

    public function primaryInputOptionsAppendToTheFirstInputOnly(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('a.mp4');
        $spec->addInput('b.png');
        $spec->addPrimaryInputOptions('trim', '-ss', '30');
        $spec->addPrimaryInputOptions('other', '-noaccurate_seek');

        Assert::same($spec->inputs(), [
            ['source' => 'a.mp4', 'options' => ['-ss', '30', '-noaccurate_seek']],
            ['source' => 'b.png', 'options' => []],
        ]);
        Assert::same($spec->primaryInputOptionOwners(), ['trim', 'other']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function primaryInputOptionsRejectAnEmptyOwner(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('a.mp4');
        $spec->addPrimaryInputOptions('', '-ss', '1');
    }

    #[ExpectException(\LogicException::class)]
    public function primaryInputOptionsRequireARegisteredInput(): void
    {
        (new CommandSpec())->addPrimaryInputOptions('trim', '-ss', '1');
    }

    public function artworkClaimsAccumulateOwners(): void
    {
        $spec = new CommandSpec();
        $spec->claimArtwork('first');
        $spec->claimArtwork('second');

        Assert::same($spec->artworkOwners(), ['first', 'second']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function artworkClaimRejectsAnEmptyOwner(): void
    {
        (new CommandSpec())->claimArtwork('');
    }

    public function inputRolesSeparateAssetsFromProbedTimelineSources(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('video.mp4');
        $spec->addInput('logo.png', probe: false, timeline: false);
        $spec->addInput('audio.m4a', timeline: false);

        Assert::same($spec->inputSources(), ['video.mp4', 'logo.png', 'audio.m4a']);
        Assert::same($spec->probeSources(), ['video.mp4', 'audio.m4a']);
        Assert::same($spec->timelineSources(), ['video.mp4']);
    }

    public function outputSlotsUseLastExplicitSelectionAndPreserveDefaults(): void
    {
        $spec = new CommandSpec();
        $spec->setDefaultVideoOutput('0:v');
        $spec->setVideoOutput('[filtered]');
        $spec->setDefaultVideoOutput('ignored');
        $spec->setDefaultAudioOutput('0:a?');
        $spec->setAudioOutput('2:a');

        Assert::same($spec->videoOutput(), '[filtered]');
        Assert::same($spec->audioOutput(), '2:a');
    }

    public function explicitVideoSelectionClaimsTheExplicitFlag(): void
    {
        $spec = new CommandSpec();

        Assert::false($spec->videoSelectionExplicit());

        $spec->setVideoOutput('0:v:1');

        Assert::true($spec->videoSelectionExplicit());
        Assert::false($spec->videoFedByComplexGraph());
    }

    public function explicitAudioSelectionClaimsTheExplicitFlag(): void
    {
        $spec = new CommandSpec();

        Assert::false($spec->audioSelectionExplicit());

        $spec->setAudioOutput('2:a');

        Assert::true($spec->audioSelectionExplicit());
        Assert::false($spec->audioFedByComplexGraph());
    }

    public function defaultAndGraphVideoOutputsDoNotClaimAnExplicitSelection(): void
    {
        $spec = new CommandSpec();
        $spec->setDefaultVideoOutput('0:v');

        Assert::false($spec->videoSelectionExplicit());

        $spec->setGraphVideoOutput('[outv]');

        Assert::false($spec->videoSelectionExplicit());
        Assert::true($spec->videoFedByComplexGraph());
    }

    public function defaultAndGraphAudioOutputsDoNotClaimAnExplicitSelection(): void
    {
        $spec = new CommandSpec();
        $spec->setDefaultAudioOutput('0:a');

        Assert::false($spec->audioSelectionExplicit());

        $spec->setGraphAudioOutput('[outa]');

        Assert::false($spec->audioSelectionExplicit());
        Assert::true($spec->audioFedByComplexGraph());
    }

    public function defaultOutputSlotsAreAppliedWhenNoExplicitSelectionExists(): void
    {
        $spec = new CommandSpec();
        $spec->setDefaultVideoOutput('0:v?');
        $spec->setDefaultAudioOutput('0:a?');
        $spec->setSubtitleOutput('0:s?');

        Assert::same($spec->videoOutput(), '0:v?');
        Assert::same($spec->audioOutput(), '0:a?');
        Assert::same($spec->subtitleOutput(), '0:s?');
    }

    public function outputModesAreTracked(): void
    {
        $spec = new CommandSpec();
        $spec->requestAudioOnly('extract-audio');

        Assert::same($spec->outputMode()->name, 'AudioOnly');
        Assert::same($spec->outputModeOwner(), 'extract-audio');
    }

    public function timelineTrimComputesOutputDuration(): void
    {
        $spec = new CommandSpec();
        $spec->trimTimeline(Duration::seconds(2), Duration::seconds(7));

        Assert::same($spec->outputDuration(Duration::seconds(10))?->toSeconds(), 5.0);
    }

    public function indeterminateTimelineHasNoOutputDuration(): void
    {
        $spec = new CommandSpec();
        $spec->markProgressIndeterminate();

        Assert::null($spec->outputDuration(Duration::seconds(10)));
    }

    public function collectsFiltersOptionsMapsAndStreamCopyFlag(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('in.mp4');
        $spec->addVideoFilter('scale=640:-2');
        $spec->addAudioFilter('loudnorm');
        $spec->addOutputOption('-c:v', 'libx264');
        $spec->addMap('0:a');
        $spec->requestStreamCopy();

        Assert::same($spec->videoFilters(), ['scale=640:-2']);
        Assert::same($spec->audioFilters(), ['loudnorm']);
        Assert::same($spec->outputOptions(), ['-c:v', 'libx264']);
        Assert::same($spec->maps(), ['0:a']);
        Assert::true($spec->isStreamCopy());
        Assert::true($spec->hasFilters());
    }

    public function hasFiltersIsFalseWithoutAnyFilter(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('in.mp4');

        Assert::false($spec->hasFilters());
    }

    public function hasFiltersIsTrueWithOnlyAVideoFilter(): void
    {
        $spec = new CommandSpec();
        $spec->addVideoFilter('scale=640:-2');

        Assert::true($spec->hasFilters());
    }

    public function hasFiltersIsTrueWithOnlyAnAudioFilter(): void
    {
        $spec = new CommandSpec();
        $spec->addAudioFilter('loudnorm');

        Assert::true($spec->hasFilters());
    }

    public function hasFiltersIsTrueWithOnlyAFilterComplexSegment(): void
    {
        $spec = new CommandSpec();
        $spec->addFilterComplexSegment('[0:v][1:v]overlay=0:0[out]');

        Assert::true($spec->hasFilters());
        Assert::same($spec->filterComplexSegments(), ['[0:v][1:v]overlay=0:0[out]']);
    }

    public function filterComplexSegmentsPreserveInsertionOrder(): void
    {
        $spec = new CommandSpec();
        $spec->addFilterComplexSegment('[1:v]format=rgba[wm]');
        $spec->addFilterComplexSegment('[0:v][wm]overlay=0:0[out]');

        Assert::same($spec->filterComplexSegments(), ['[1:v]format=rgba[wm]', '[0:v][wm]overlay=0:0[out]']);
    }

    public function recordsComplexGraphOwners(): void
    {
        $spec = new CommandSpec();
        $spec->claimFilterComplex('concat');
        $spec->claimFilterComplex('watermark');

        Assert::same($spec->filterComplexOwners(), ['concat', 'watermark']);
    }

    public function recordsPackageOutputLayout(): void
    {
        $spec = new CommandSpec();
        $spec->markHlsOutput('segment-%03d.ts');

        Assert::true($spec->outputLayout()->isHls());
        Assert::same($spec->outputLayout()->hlsSegmentPattern(), 'segment-%03d.ts');
    }

    public function exclusiveVideoGraphIsFalseUntilMarked(): void
    {
        $spec = new CommandSpec();

        Assert::false($spec->hasExclusiveVideoGraph());

        $spec->markExclusiveVideoGraph();

        Assert::true($spec->hasExclusiveVideoGraph());
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptyInputSource(): void
    {
        (new CommandSpec())->addInput('');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptyVideoFilterNode(): void
    {
        (new CommandSpec())->addVideoFilter('');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptyAudioFilterNode(): void
    {
        (new CommandSpec())->addAudioFilter('');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptyFilterComplexSegment(): void
    {
        (new CommandSpec())->addFilterComplexSegment('');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptyFilterComplexOwner(): void
    {
        (new CommandSpec())->claimFilterComplex('');
    }

    public function rejectsTwoPackageOutputOperationsAsIncompatible(): void
    {
        $spec = new CommandSpec();
        $spec->markHlsOutput(null);
        $caught = null;

        try {
            $spec->markDashOutput();
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::same($caught->exitCode, 0);
        Assert::same($caught->stderrTail(), 'Only one package output operation is allowed per pipeline');
    }
}
