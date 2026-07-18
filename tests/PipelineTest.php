<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\ConversionFailed;
use Rasuvaeff\MediaConverter\ConversionFailureReason;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\AddArtwork;
use Rasuvaeff\MediaConverter\Operation\AddMetadata;
use Rasuvaeff\MediaConverter\Operation\AnimatedPreview;
use Rasuvaeff\MediaConverter\Operation\Crop;
use Rasuvaeff\MediaConverter\Operation\ExtractAudio;
use Rasuvaeff\MediaConverter\Operation\ExtractSubtitles;
use Rasuvaeff\MediaConverter\Operation\Fps;
use Rasuvaeff\MediaConverter\Operation\NormalizeLoudness;
use Rasuvaeff\MediaConverter\Operation\OperationInterface;
use Rasuvaeff\MediaConverter\Operation\Remux;
use Rasuvaeff\MediaConverter\Operation\ReplaceAudio;
use Rasuvaeff\MediaConverter\Operation\Scale;
use Rasuvaeff\MediaConverter\Operation\SelectStreams;
use Rasuvaeff\MediaConverter\Operation\SpriteSheet;
use Rasuvaeff\MediaConverter\Operation\Thumbnail;
use Rasuvaeff\MediaConverter\Operation\Transcode;
use Rasuvaeff\MediaConverter\Operation\Trim;
use Rasuvaeff\MediaConverter\Operation\Watermark;
use Rasuvaeff\MediaConverter\Pipeline;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Pipeline::class)]
#[Covers(AnimatedPreview::class)]
#[Covers(ExtractAudio::class)]
#[Covers(ExtractSubtitles::class)]
#[Covers(SpriteSheet::class)]
#[Covers(Thumbnail::class)]
final class PipelineTest
{
    private FfmpegBinary $binary;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->binary = FfmpegBinary::default();
    }

    public function emptyPipelineJustReadsAndWritesTheSource(): void
    {
        $argv = Pipeline::from('in.mkv')->toArgv($this->binary, 'out.mkv');

        Assert::same($argv, ['/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y', '-i', 'in.mkv', 'out.mkv']);
    }

    public function sourcesExposesTheSinglePrimarySourceOfANormalPipeline(): void
    {
        Assert::same(Pipeline::from('in.mp4')->sources(), ['in.mp4']);
    }

    public function transcodeAddsCodecOptionsAfterTheInput(): void
    {
        $argv = Pipeline::from('in.mkv')
            ->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac'))
            ->toArgv($this->binary, 'out.mp4');

        Assert::same($argv, [
            '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
            '-i', 'in.mkv',
            '-c:v', 'libx264', '-c:a', 'aac',
            'out.mp4',
        ]);
    }

    public function remuxRequestsStreamCopy(): void
    {
        $argv = Pipeline::from('in.ts')->add(new Remux())->toArgv($this->binary, 'out.mp4');

        Assert::true(in_array('-c', $argv, true));
        Assert::same($argv[array_search('-c', $argv, true) + 1], 'copy');
    }

    public function filtersAreJoinedIntoASingleVfChainInInsertionOrder(): void
    {
        $argv = Pipeline::from('in.mp4')
            ->add(new Scale(width: 1280))
            ->add(new Crop(640, 480, 10, 20))
            ->add(new Fps(30))
            ->toArgv($this->binary, 'out.mp4');

        $vf = $argv[array_search('-vf', $argv, true) + 1];
        Assert::same($vf, 'scale=1280:-2,crop=640:480:10:20,fps=30');
    }

    public function pipelineIsImmutableAddReturnsANewInstance(): void
    {
        $base = Pipeline::from('in.mp4');
        $withScale = $base->add(new Scale(width: 640));

        // The base pipeline is untouched: no -vf.
        Assert::false(in_array('-vf', $base->toArgv($this->binary, 'out.mp4'), true));
        Assert::true(in_array('-vf', $withScale->toArgv($this->binary, 'out.mp4'), true));
    }

    public function streamCopyWithAFilterIsRejectedBeforeRunning(): void
    {
        $caught = null;

        try {
            Pipeline::from('in.ts')
                ->add(new Remux())
                ->add(new Scale(width: 640))
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::string($caught->getMessage())->contains('incompatible-operations');
        Assert::same($caught->exitCode, 0);
    }

    public function streamCopyWithATranscodeCodecIsRejected(): void
    {
        $caught = null;

        try {
            Pipeline::from('in.ts')
                ->add(new Remux())
                ->add(new Transcode(videoCodec: 'libx264'))
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::same($caught->exitCode, 0);
    }

    public function streamCopyWithABitrateOnlyOptionIsRejected(): void
    {
        $caught = null;

        try {
            Pipeline::from('in.ts')
                ->add(new Remux())
                ->add(new Transcode(videoBitrateKbps: 128))
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::same($caught->exitCode, 0);
    }

    public function streamCopyWithALiteralCodecOptionIsRejected(): void
    {
        $emitsCodec = new class implements OperationInterface {
            #[\Override]
            public function applyTo(CommandSpec $spec): void
            {
                $spec->addOutputOption('-codec');
            }
        };

        $caught = null;

        try {
            Pipeline::from('in.ts')
                ->add(new Remux())
                ->add($emitsCodec)
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::same($caught->exitCode, 0);
    }

    public function streamCopyWithAHarmlessOutputOptionIsAccepted(): void
    {
        $argv = Pipeline::from('in.ts')
            ->add(new Remux())
            ->add(new Trim(Duration::seconds(1)))
            ->toArgv($this->binary, 'out.mp4');

        Assert::true(in_array('-ss', $argv, true));
    }

    public function streamCopyComposesWithMetadataTags(): void
    {
        // Writing container tags needs no re-encode.
        $argv = Pipeline::from('in.mp4')
            ->add(new Remux())
            ->add(new AddMetadata(['title' => 'T']))
            ->toArgv($this->binary, 'out.mp4');

        Assert::true(in_array('-metadata', $argv, true));
        Assert::true(in_array('title=T', $argv, true));
    }

    public function streamCopyComposesWithCoverArt(): void
    {
        // AddArtwork's -c:v:1 copy is itself stream copy, not a re-encode —
        // the remux guard must not mistake it for a codec option.
        $argv = Pipeline::from('in.mp4')
            ->add(new Remux())
            ->add(AddArtwork::forVideo('cover.jpg'))
            ->toArgv($this->binary, 'out.mp4');

        Assert::true(in_array('-disposition:v:1', $argv, true));
        Assert::true(in_array('-c', $argv, true));
    }

    public function transcodeThenCoverArtKeepsTheCoverCopyAfterTheCodec(): void
    {
        // ffmpeg applies the LAST matching codec option per stream: -c:v:1 copy
        // must land after -c:v libx264 for the cover to stay un-encoded.
        $argv = Pipeline::from('in.mp4')
            ->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac'))
            ->add(AddArtwork::forVideo('cover.jpg'))
            ->toArgv($this->binary, 'out.mp4');

        Assert::true(array_search('-c:v:1', $argv, true) > array_search('-c:v', $argv, true));
    }

    public function watermarkComposesWithCoverArt(): void
    {
        $argv = Pipeline::from('in.mp4')
            ->add(new Watermark('logo.png'))
            ->add(AddArtwork::forVideo('cover.jpg'))
            ->toArgv($this->binary, 'out.mp4');
        $maps = [];

        foreach ($argv as $index => $argument) {
            if ($argument === '-map') {
                $maps[] = $argv[$index + 1];
            }
        }

        // Composed video is v:0, the cover stays v:1 — the disposition index
        // holds even when the main video comes from the complex graph.
        Assert::same($maps, ['[wmout]', '0:a?', '2:v']);
        Assert::true(in_array('-disposition:v:1', $argv, true));
    }

    public function twoCoverArtOperationsAreRejected(): void
    {
        $caught = null;

        try {
            Pipeline::from('in.mp4')
                ->add(AddArtwork::forVideo('front.jpg'))
                ->add(AddArtwork::forVideo('back.jpg'))
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::same($caught->exitCode, 0);
        Assert::same($caught->stderrTail(), sprintf('Only one operation may attach cover art; got: %s, %s', AddArtwork::class, AddArtwork::class));
    }

    public function extractAudioWithCoverArtIsRejected(): void
    {
        // ExtractAudio's -vn discards every video stream, including the mapped
        // cover — embed art via Transcode(audioCodec: ...) + forAudio instead.
        $caught = null;

        try {
            Pipeline::from('in.mp4')
                ->add(new ExtractAudio())
                ->add(AddArtwork::forAudio('cover.jpg'))
                ->toArgv($this->binary, 'out.mp3');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
    }

    public function audioTranscodeWithCoverArtComposesIntoAnMp3WithArt(): void
    {
        $argv = Pipeline::from('in.mp4')
            ->add(new Transcode(audioCodec: 'libmp3lame', audioBitrateKbps: 192))
            ->add(AddArtwork::forAudio('cover.jpg'))
            ->toArgv($this->binary, 'out.mp3');
        $maps = [];

        foreach ($argv as $index => $argument) {
            if ($argument === '-map') {
                $maps[] = $argv[$index + 1];
            }
        }

        Assert::same($maps, ['0:a', '1:v']);
        Assert::true(in_array('-id3v2_version', $argv, true));
    }

    public function videoTranscodeFollowedByArtworkComposes(): void
    {
        // AddArtwork AFTER a video Transcode: the per-stream -c:v:1 copy must
        // follow the generic -c:v in argv, so the cover stream is preserved.
        $argv = Pipeline::from('in.mp4')
            ->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac'))
            ->add(AddArtwork::forVideo('cover.jpg'))
            ->toArgv($this->binary, 'out.mp4');

        $videoCodecIndex = array_search('-c:v', $argv, true);
        $coverCodecIndex = null;

        foreach ($argv as $index => $argument) {
            if ($argument === '-c:v:1') {
                $coverCodecIndex = $index;

                break;
            }
        }

        Assert::notNull($coverCodecIndex);
        Assert::true($videoCodecIndex < $coverCodecIndex);
    }

    public function artworkBeforeAVideoTranscodeIsRejected(): void
    {
        // ffmpeg applies the LAST matching -c:v option per stream, so adding
        // the cover BEFORE the transcode would let -c:v override -c:v:1 copy
        // and discard the cover. Pipeline rejects this before ffmpeg runs.
        $caught = null;

        try {
            Pipeline::from('in.mp4')
                ->add(AddArtwork::forVideo('cover.jpg'))
                ->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac'))
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::string($caught->stderrTail())->contains('AFTER a video Transcode');
    }

    public function fastSeekTrimEmitsTheSeekBeforeTheInput(): void
    {
        $argv = Pipeline::from('in.mp4')
            ->add(new Trim(Duration::seconds(30), Duration::seconds(90), fastSeek: true))
            ->toArgv($this->binary, 'out.mp4');

        Assert::same($argv, [
            '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
            '-ss', '30', '-i', 'in.mp4',
            '-t', '60',
            'out.mp4',
        ]);
    }

    public function fastSeekTrimComposesWithRemux(): void
    {
        $argv = Pipeline::from('in.mp4')
            ->add(new Remux())
            ->add(new Trim(Duration::seconds(30), fastSeek: true))
            ->toArgv($this->binary, 'out.mp4');

        Assert::same(array_slice($argv, 4, 3), ['-ss', '30', '-i']);
        Assert::true(in_array('copy', $argv, true));
    }

    public function concatWithAFastSeekTrimIsRejected(): void
    {
        // The input-side -ss would apply to the first segment only.
        $caught = null;

        try {
            Pipeline::concat(['a.mp4', 'b.mp4'])
                ->add(new Trim(Duration::seconds(1), fastSeek: true))
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::same($caught->exitCode, 0);
        Assert::string($caught->stderrTail())->contains('primary-input options');
    }

    public function streamCopyStillRejectsAReencodingOptionAfterTheCoverCopyPair(): void
    {
        // The artwork's '-c:v:1 copy' pair must not short-circuit the remux
        // guard: a later re-encoding option is still rejected.
        $caught = null;

        try {
            Pipeline::from('in.mp4')
                ->add(new Remux())
                ->add(AddArtwork::forVideo('cover.jpg'))
                ->add(new Transcode(videoBitrateKbps: 128))
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::string($caught->stderrTail())->contains('-b:v');
    }

    public function aSoleExclusiveVideoGraphOperationSucceeds(): void
    {
        $argv = Pipeline::from('in.mp4')
            ->add(new AnimatedPreview(Duration::seconds(0), Duration::seconds(1)))
            ->toArgv($this->binary, 'out.gif');

        Assert::true(in_array('-vf', $argv, true));
    }

    public function anExclusiveVideoGraphWithAnotherVideoFilterIsRejected(): void
    {
        $caught = null;

        try {
            Pipeline::from('in.mp4')
                ->add(new AnimatedPreview(Duration::seconds(0), Duration::seconds(1)))
                ->add(new Scale(width: 320))
                ->toArgv($this->binary, 'out.gif');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::same($caught->exitCode, 0);
    }

    public function aSoleMultiInputOperationSucceeds(): void
    {
        $argv = Pipeline::from('in.mp4')
            ->add(new Watermark('logo.png'))
            ->toArgv($this->binary, 'out.mp4');

        Assert::true(in_array('-filter_complex', $argv, true));
        Assert::false(in_array('-vf', $argv, true));
    }

    public function aMultiInputOperationComposesWithAPlainAudioFilter(): void
    {
        $argv = Pipeline::from('in.mp4')
            ->add(new Watermark('logo.png'))
            ->add(new NormalizeLoudness())
            ->toArgv($this->binary, 'out.mp4');

        Assert::true(in_array('-filter_complex', $argv, true));
        Assert::true(in_array('-af', $argv, true));
    }

    public function aMultiInputOperationWithAPlainVideoFilterIsRejected(): void
    {
        $caught = null;

        try {
            Pipeline::from('in.mp4')
                ->add(new Watermark('logo.png'))
                ->add(new Scale(width: 320))
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::same($caught->exitCode, 0);
    }

    public function aMultiInputOperationWithAPlainVideoFilterIsRejectedRegardlessOfOrder(): void
    {
        $caught = null;

        try {
            Pipeline::from('in.mp4')
                ->add(new Scale(width: 320))
                ->add(new Watermark('logo.png'))
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
    }

    public function streamCopyWithAMultiInputOperationIsRejected(): void
    {
        $caught = null;

        try {
            Pipeline::from('in.mp4')
                ->add(new Remux())
                ->add(new Watermark('logo.png'))
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsEmptySource(): void
    {
        Pipeline::from('');
    }

    public function concatBuildsAFilterComplexGraphOverEverySegment(): void
    {
        $pipeline = Pipeline::concat(['a.mp4', 'b.mp4', 'c.mp4']);
        $argv = $pipeline->toArgv($this->binary, 'out.mp4');

        Assert::same($pipeline->source(), 'a.mp4');
        Assert::same($pipeline->sources(), ['a.mp4', 'b.mp4', 'c.mp4']);
        Assert::same(
            $argv,
            [
                '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
                '-i', 'a.mp4', '-i', 'b.mp4', '-i', 'c.mp4',
                '-filter_complex', '[0:v][0:a][1:v][1:a][2:v][2:a]concat=n=3:v=1:a=1[outv][outa]',
                '-map', '[outv]', '-map', '[outa]',
                'out.mp4',
            ],
        );
    }

    public function concatWithoutAudioOmitsTheAudioPadsAndMap(): void
    {
        $argv = Pipeline::concat(['a.mp4', 'b.mp4'], hasAudio: false)->toArgv($this->binary, 'out.mp4');

        Assert::same(
            $argv,
            [
                '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
                '-i', 'a.mp4', '-i', 'b.mp4',
                '-filter_complex', '[0:v][1:v]concat=n=2:v=1:a=0[outv]',
                '-map', '[outv]',
                'out.mp4',
            ],
        );
    }

    public function concatComposesWithACodecOnlyOperation(): void
    {
        $argv = Pipeline::concat(['a.mp4', 'b.mp4'])
            ->add(new Transcode(videoCodec: 'libx264'))
            ->toArgv($this->binary, 'out.mp4');

        Assert::true(in_array('-filter_complex', $argv, true));
        Assert::true(in_array('-c:v', $argv, true));
    }

    public function concatWithAPlainVideoFilterOperationIsRejected(): void
    {
        $caught = null;

        try {
            Pipeline::concat(['a.mp4', 'b.mp4'])
                ->add(new Scale(width: 320))
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
    }

    public function concatWithWatermarkIsRejectedBeforeRunning(): void
    {
        $caught = null;

        try {
            Pipeline::concat(['a.mp4', 'b.mp4'])
                ->add(new Watermark('logo.png'))
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
    }

    public function twoWatermarksAreRejectedBeforeRunning(): void
    {
        $caught = null;

        try {
            Pipeline::from('in.mp4')
                ->add(new Watermark('first.png'))
                ->add(new Watermark('second.png'))
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::same($caught->exitCode, 0);
        Assert::same($caught->stderrTail(), sprintf('Only one operation may own the complex video graph; got: %s, %s', Watermark::class, Watermark::class));
    }

    public function watermarkAndReplaceAudioComposeIntoOneVideoAndOneAudioStream(): void
    {
        $argv = Pipeline::from('video.mp4')
            ->add(new Watermark('logo.png'))
            ->add(new ReplaceAudio('audio.m4a'))
            ->toArgv($this->binary, 'out.mp4');

        $maps = [];

        foreach ($argv as $index => $argument) {
            if ($argument === '-map') {
                $maps[] = $argv[$index + 1];
            }
        }

        Assert::same($maps, ['[wmout]', '2:a']);
    }

    public function terminalAudioOutputRejectsWatermarkVideoOutput(): void
    {
        $caught = null;

        try {
            Pipeline::from('in.mp4')->add(new Watermark('logo.png'))->add(new ExtractAudio())->toArgv(FfmpegBinary::default(), 'out.mp3');
        } catch (ConversionFailed $failure) {
            $caught = $failure;
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
    }

    public function audioOnlyRejectsEveryVideoTargetIndependently(): void
    {
        $conflicts = [
            static fn(CommandSpec $spec): null => self::configure($spec, videoFilter: 'scale=10:10'),
            static fn(CommandSpec $spec): null => self::configure($spec, complexFilter: '[0:v]null[out]'),
            static fn(CommandSpec $spec): null => self::configure($spec, videoOutput: '0:v'),
            static fn(CommandSpec $spec): null => self::configure($spec, outputOption: '-c:v'),
            static fn(CommandSpec $spec): null => self::configure($spec, outputOption: '-b:v'),
            static fn(CommandSpec $spec): null => self::configure($spec, outputOption: '-frames:v'),
            static fn(CommandSpec $spec): null => self::configure($spec, map: '1:v'),
        ];

        foreach ($conflicts as $conflict) {
            self::assertTerminalConflict(static function (CommandSpec $spec) use ($conflict): void {
                $spec->requestAudioOnly('audio');
                $conflict($spec);
            });
        }
    }

    public function videoOnlyRejectsEveryAudioOrSubtitleTargetIndependently(): void
    {
        $conflicts = [
            static fn(CommandSpec $spec): null => self::configure($spec, audioFilter: 'loudnorm'),
            static fn(CommandSpec $spec): null => self::configure($spec, audioOutput: '0:a'),
            static fn(CommandSpec $spec): null => self::configure($spec, subtitleOutput: '0:s'),
            static fn(CommandSpec $spec): null => self::configure($spec, outputOption: '-c:a'),
            static fn(CommandSpec $spec): null => self::configure($spec, outputOption: '-b:a'),
            static fn(CommandSpec $spec): null => self::configure($spec, map: '1:v'),
        ];

        foreach ($conflicts as $conflict) {
            self::assertTerminalConflict(static function (CommandSpec $spec) use ($conflict): void {
                $spec->requestVideoOnly('video');
                $conflict($spec);
            });
        }
    }

    public function subtitleOnlyRejectsEachAudioAndVideoTargetIndependently(): void
    {
        $conflicts = [
            static fn(CommandSpec $spec): null => self::configure($spec, videoFilter: 'scale=10:10'),
            static fn(CommandSpec $spec): null => self::configure($spec, audioFilter: 'loudnorm'),
            static fn(CommandSpec $spec): null => self::configure($spec, complexFilter: '[0:v]null[out]'),
            static fn(CommandSpec $spec): null => self::configure($spec, videoOutput: '0:v'),
            static fn(CommandSpec $spec): null => self::configure($spec, audioOutput: '0:a'),
            static fn(CommandSpec $spec): null => self::configure($spec, outputOption: '-c:v'),
            static fn(CommandSpec $spec): null => self::configure($spec, outputOption: '-c:a'),
            static fn(CommandSpec $spec): null => self::configure($spec, map: '1:v'),
        ];

        foreach ($conflicts as $conflict) {
            self::assertTerminalConflict(static function (CommandSpec $spec) use ($conflict): void {
                $spec->requestSubtitleOnly('subtitle');
                $conflict($spec);
            });
        }
    }

    public function multipleTerminalOperationsAreRejectedWithTheirOwners(): void
    {
        $caught = self::terminalConflict(static function (CommandSpec $spec): void {
            $spec->requestAudioOnly('first');
            $spec->requestVideoOnly('second');
        });

        Assert::string($caught->stderrTail())->contains('first, second');
        Assert::same($caught->exitCode, 0);
    }

    public function builtInTerminalOperationsActuallyClaimTheirOutputMode(): void
    {
        $pipelines = [
            Pipeline::from('in.mp4')->add(new AnimatedPreview(Duration::zero(), Duration::seconds(1)))->add(new NormalizeLoudness()),
            Pipeline::from('in.mp4')->add(new Thumbnail(Duration::zero()))->add(new NormalizeLoudness()),
            Pipeline::from('in.mp4')->add(new SpriteSheet(rows: 2, cols: 2, interval: Duration::seconds(1)))->add(new NormalizeLoudness()),
            Pipeline::from('in.mkv')->add(new ExtractSubtitles())->add(new Scale(width: 10)),
        ];

        foreach ($pipelines as $pipeline) {
            $caught = null;

            try {
                $pipeline->toArgv($this->binary, 'out');
            } catch (ConversionFailed $failure) {
                $caught = $failure;
            }

            Assert::notNull($caught);
            Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        }
    }

    public function audioOnlyOutputAcceptsItsOwnAudioSideOptions(): void
    {
        // ExtractAudio emits -vn, -c:a and -b:a: audio-side options must not be
        // mistaken for video-side ones by the terminal-output validation.
        $argv = Pipeline::from('in.mp4')->add(new ExtractAudio())->toArgv($this->binary, 'out.mp3');

        Assert::true(in_array('-c:a', $argv, true));
        Assert::true(in_array('-b:a', $argv, true));
        Assert::false(in_array('-c:v', $argv, true));
    }

    public function subtitleOnlyOutputComposesWhenNothingTargetsAnotherStreamKind(): void
    {
        $argv = Pipeline::from('in.mkv')->add(new ExtractSubtitles(streamIndex: 1))->toArgv($this->binary, 'out.srt');

        Assert::true(in_array('0:s:1', $argv, true));
    }

    public function terminalConflictReportsTheOwningOperation(): void
    {
        $caught = self::terminalConflict(static function (CommandSpec $spec): void {
            $spec->requestAudioOnly('extract-audio');
            $spec->addVideoFilter('scale=10:10');
        });

        Assert::same($caught->stderrTail(), 'Terminal output operation extract-audio conflicts with operations targeting another stream kind');
    }

    public function selectStreamsUsesSemanticSlots(): void
    {
        $argv = Pipeline::from('in.mkv')->add(new SelectStreams(videoIndex: 1, audioIndex: 2, optional: true))->toArgv(FfmpegBinary::default(), 'out.mkv');

        Assert::same(array_slice($argv, -5), ['-map', '0:v:1?', '-map', '0:a:2?', 'out.mkv']);
    }

    public function concatAndReplaceAudioKeepConcatVideoAndUseReplacementAudio(): void
    {
        $argv = Pipeline::concat(['first.mp4', 'second.mp4'])
            ->add(new ReplaceAudio('audio.m4a'))
            ->toArgv($this->binary, 'out.mp4');
        $maps = [];

        foreach ($argv as $index => $argument) {
            if ($argument === '-map') {
                $maps[] = $argv[$index + 1];
            }
        }

        Assert::same($maps, ['[outv]', '2:a']);
        // The segments' own audio pads are dropped (a=0): emitting a=1 would
        // leave the [outa] label unconnected, which ffmpeg rejects outright.
        Assert::true(in_array('[0:v][1:v]concat=n=2:v=1:a=0[outv]', $argv, true));
        Assert::false(in_array('[0:v][0:a][1:v][1:a]concat=n=2:v=1:a=1[outv][outa]', $argv, true));
    }

    public function concatAndAudioSelectionDropSegmentAudioPads(): void
    {
        $argv = Pipeline::concat(['first.mp4', 'second.mp4'])
            ->add(new SelectStreams(audioIndex: 1))
            ->toArgv($this->binary, 'out.mp4');
        $maps = [];

        foreach ($argv as $index => $argument) {
            if ($argument === '-map') {
                $maps[] = $argv[$index + 1];
            }
        }

        Assert::same($maps, ['[outv]', '0:a:1']);
        Assert::true(in_array('[0:v][1:v]concat=n=2:v=1:a=0[outv]', $argv, true));
    }

    public function concatWithAPlainAudioFilterOperationIsRejected(): void
    {
        $caught = null;

        try {
            Pipeline::concat(['a.mp4', 'b.mp4'])
                ->add(new NormalizeLoudness())
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::same($caught->exitCode, 0);
        Assert::same($caught->stderrTail(), 'A concat pipeline routes audio through the complex filter graph and is incompatible with a plain audio filter operation; ffmpeg cannot combine -af and -filter_complex for the same stream. Apply the audio filter upstream (re-encode each segment first), or drop ReplaceAudio so concat\'s audio pads drop (a=0)');
    }

    public function concatWithAVideoSelectionIsRejected(): void
    {
        $caught = null;

        try {
            Pipeline::concat(['a.mp4', 'b.mp4'])
                ->add(new SelectStreams(videoIndex: 0))
                ->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::same($caught->exitCode, 0);
        Assert::same($caught->stderrTail(), 'Explicit video stream selection is incompatible with an operation that produces the video stream through the complex filter graph (e.g. Watermark, concat)');
    }

    #[DataProvider('watermarkAndVideoSelectionOrdersProvider')]
    public function watermarkWithAVideoSelectionIsRejectedRegardlessOfOrder(Pipeline $pipeline): void
    {
        $caught = null;

        try {
            $pipeline->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
    }

    public static function watermarkAndVideoSelectionOrdersProvider(): iterable
    {
        yield 'selection after watermark' => [
            Pipeline::from('in.mp4')->add(new Watermark('logo.png'))->add(new SelectStreams(videoIndex: 0)),
        ];
        yield 'selection before watermark' => [
            Pipeline::from('in.mp4')->add(new SelectStreams(videoIndex: 0))->add(new Watermark('logo.png')),
        ];
    }

    public function extractAudioWithASubtitleSelectionIsRejected(): void
    {
        $caught = null;

        try {
            Pipeline::from('in.mkv')
                ->add(new ExtractAudio())
                ->add(new SelectStreams(subtitleIndex: 0))
                ->toArgv($this->binary, 'out.mp3');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
    }

    #[DataProvider('duplicateSeekProvider')]
    public function aSecondOutputSeekOperationIsRejected(Pipeline $pipeline): void
    {
        $caught = null;

        try {
            $pipeline->toArgv($this->binary, 'out.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::same($caught->exitCode, 0);
        Assert::string($caught->stderrTail())->contains('seek the output timeline');
    }

    public static function duplicateSeekProvider(): iterable
    {
        yield 'trim then thumbnail' => [
            Pipeline::from('in.mp4')->add(new Trim(Duration::seconds(1), Duration::seconds(2)))->add(new Thumbnail(Duration::seconds(5))),
        ];
        yield 'two trims' => [
            Pipeline::from('in.mp4')->add(new Trim(Duration::seconds(1)))->add(new Trim(Duration::seconds(2))),
        ];
    }

    public function trimWithAnimatedPreviewStillComposes(): void
    {
        // AnimatedPreview emits its own -ss/-to AFTER Trim's, fully overriding
        // both — documented as harmless but redundant, so it stays accepted.
        $argv = Pipeline::from('in.mp4')
            ->add(new Trim(Duration::seconds(1), Duration::seconds(2)))
            ->add(new AnimatedPreview(Duration::seconds(3), Duration::seconds(4)))
            ->toArgv($this->binary, 'out.gif');

        Assert::true(in_array('-ss', $argv, true));
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function concatRejectsFewerThanTwoSegments(): void
    {
        Pipeline::concat(['a.mp4']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function concatRejectsAnEmptySegment(): void
    {
        Pipeline::concat(['a.mp4', '']);
    }

    /**
     * The builder is order-preserving: the Nth filter operation becomes the
     * Nth node of the -vf chain, whatever the sequence. (Filters are NOT
     * commutative, so this — not commutativity — is the invariant that holds.)
     */
    #[Property(runs: 200)]
    public function filterChainPreservesOperationOrder(array $kinds): void
    {
        $operations = [];
        $expected = [];

        foreach ($kinds as $index => $kind) {
            [$operation, $node] = self::filterFor($kind, $index);
            $operations[] = $operation;
            $expected[] = $node;
        }

        $pipeline = Pipeline::from('in.mp4');
        foreach ($operations as $operation) {
            $pipeline = $pipeline->add($operation);
        }

        $argv = $pipeline->toArgv($this->binary, 'out.mp4');
        $vf = $argv[array_search('-vf', $argv, true) + 1];

        Assert::same(explode(',', $vf), $expected);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function filterChainPreservesOperationOrderGenerators(): array
    {
        return ['kinds' => Gen::nonEmptyArrayOf(Gen::intBetween(0, 2), 6)];
    }

    /**
     * @return array{0: OperationInterface, 1: string}
     */
    private static function filterFor(int $kind, int $index): array
    {
        return match ($kind % 3) {
            0 => [new Scale(width: 100 + $index), 'scale=' . (100 + $index) . ':-2'],
            1 => [new Crop(10 + $index, 20 + $index), 'crop=' . (10 + $index) . ':' . (20 + $index) . ':0:0'],
            default => [new Fps(1 + $index), 'fps=' . (1 + $index)],
        };
    }

    /** @param \Closure(CommandSpec): void $configure */
    private static function assertTerminalConflict(\Closure $configure): void
    {
        $caught = self::terminalConflict($configure);

        Assert::same($caught->reason, ConversionFailureReason::IncompatibleOperations);
        Assert::same($caught->exitCode, 0);
        Assert::string($caught->stderrTail())->contains('conflicts');
    }

    /** @param \Closure(CommandSpec): void $configure */
    private static function terminalConflict(\Closure $configure): ConversionFailed
    {
        $operation = new readonly class ($configure) implements OperationInterface {
            /** @param \Closure(CommandSpec): void $configure */
            public function __construct(
                private \Closure $configure,
            ) {}

            #[\Override]
            public function applyTo(CommandSpec $spec): void
            {
                ($this->configure)($spec);
            }
        };

        try {
            Pipeline::from('in.mkv')->add($operation)->toArgv(FfmpegBinary::default(), 'out.mkv');
        } catch (ConversionFailed $failure) {
            return $failure;
        }

        throw new \LogicException('Expected an incompatible terminal operation');
    }

    private static function configure(
        CommandSpec $spec,
        ?string $videoFilter = null,
        ?string $audioFilter = null,
        ?string $complexFilter = null,
        ?string $videoOutput = null,
        ?string $audioOutput = null,
        ?string $subtitleOutput = null,
        ?string $outputOption = null,
        ?string $map = null,
    ): null {
        if ($videoFilter !== null) {
            $spec->addVideoFilter($videoFilter);
        }
        if ($audioFilter !== null) {
            $spec->addAudioFilter($audioFilter);
        }
        if ($complexFilter !== null) {
            $spec->addFilterComplexSegment($complexFilter);
        }
        if ($videoOutput !== null) {
            $spec->setVideoOutput($videoOutput);
        }
        if ($audioOutput !== null) {
            $spec->setAudioOutput($audioOutput);
        }
        if ($subtitleOutput !== null) {
            $spec->setSubtitleOutput($subtitleOutput);
        }
        if ($outputOption !== null) {
            $spec->addOutputOption($outputOption, 'value');
        }
        if ($map !== null) {
            $spec->addMap($map);
        }

        return null;
    }
}
