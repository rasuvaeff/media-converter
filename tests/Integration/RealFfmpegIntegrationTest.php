<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Integration;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\ConversionResult;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\FfprobeMediaInfo;
use Rasuvaeff\MediaConverter\MediaConverter;
use Rasuvaeff\MediaConverter\Operation\AddArtwork;
use Rasuvaeff\MediaConverter\Operation\AddMetadata;
use Rasuvaeff\MediaConverter\Operation\AnimatedPreview;
use Rasuvaeff\MediaConverter\Operation\BurnSubtitles;
use Rasuvaeff\MediaConverter\Operation\Crop;
use Rasuvaeff\MediaConverter\Operation\ExtractAudio;
use Rasuvaeff\MediaConverter\Operation\ExtractSubtitles;
use Rasuvaeff\MediaConverter\Operation\Fps;
use Rasuvaeff\MediaConverter\Operation\NormalizeLoudness;
use Rasuvaeff\MediaConverter\Operation\PackageDash;
use Rasuvaeff\MediaConverter\Operation\PackageHls;
use Rasuvaeff\MediaConverter\Operation\Pad;
use Rasuvaeff\MediaConverter\Operation\Remux;
use Rasuvaeff\MediaConverter\Operation\ReplaceAudio;
use Rasuvaeff\MediaConverter\Operation\Rotate;
use Rasuvaeff\MediaConverter\Operation\Scale;
use Rasuvaeff\MediaConverter\Operation\SpriteSheet;
use Rasuvaeff\MediaConverter\Operation\TextOverlay;
use Rasuvaeff\MediaConverter\Operation\Thumbnail;
use Rasuvaeff\MediaConverter\Operation\Transcode;
use Rasuvaeff\MediaConverter\Operation\Trim;
use Rasuvaeff\MediaConverter\Operation\Watermark;
use Rasuvaeff\MediaConverter\Pipeline;
use Rasuvaeff\MediaConverter\Preset\Presets;
use Rasuvaeff\MediaConverter\Progress\ProgressEvent;
use Rasuvaeff\MediaConverter\RunsProcess;
use Rasuvaeff\MediaConverter\SymfonyProcessRunner;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Runs every operation, `Pipeline::concat()`, two presets, and the progress
 * callback through a REAL ffmpeg/ffprobe, verifying the output via
 * `ffprobe`. Skips (each test returns immediately) when `ffmpeg`/`ffprobe`
 * are not executable — the `composer:2` Docker image used for `composer
 * build` has neither installed, so this suite is green-but-empty there;
 * run it where ffmpeg is available: `vendor/bin/testo --suite=Integration`.
 * `TextOverlay` additionally needs a system font discoverable by fontconfig
 * (`drawtext` defaults to the "Sans" family when no `fontFile` is given) —
 * a bare ffmpeg install (e.g. Alpine's `ffmpeg` apk package) has none;
 * install one (`apk add ttf-dejavu`) alongside ffmpeg or that one test
 * fails with "Cannot find a valid font for the family Sans" (verified).
 *
 * DRM rejection is intentionally NOT covered here — fabricating a genuinely
 * DRM-encrypted fixture is impractical; it is covered at the unit level
 * (`MediaConverterTest::drmEncryptedSourceIsRefusedBeforeAnyProcess`) with a
 * stubbed prober, and `Pipeline`'s incompatible-composition rejection
 * likewise needs no real ffmpeg — both are exhaustively unit-tested already.
 */
#[Test]
#[CoversNothing]
final class RealFfmpegIntegrationTest
{
    private bool $available;

    private FfmpegBinary $binary;

    private RunsProcess $runner;

    private MediaConverter $converter;

    private FfprobeMediaInfo $prober;

    private string $workDir;

    private string $fixture;

    private string $fixture2;

    private string $watermarkImage;

    private string $subtitleFile;

    private string $subtitleFixture;

    private string $otherAudio;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->binary = FfmpegBinary::default();
        $this->available = is_executable($this->binary->ffmpegPath()) && is_executable($this->binary->ffprobePath());

        if (!$this->available) {
            return;
        }

        $this->runner = new SymfonyProcessRunner();
        $this->converter = new MediaConverter($this->binary, $this->runner);
        $this->prober = new FfprobeMediaInfo($this->binary, $this->runner);

        $this->workDir = sys_get_temp_dir() . '/mc-integration-' . bin2hex(random_bytes(6));
        mkdir($this->workDir);

        $this->fixture = $this->path('fixture.mp4');
        $this->ffmpeg([
            '-f', 'lavfi', '-i', 'testsrc=duration=2:size=80x60:rate=10',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2',
            '-c:v', 'libx264', '-c:a', 'aac', '-shortest', $this->fixture,
        ]);

        $this->fixture2 = $this->path('fixture2.mp4');
        $this->ffmpeg([
            '-f', 'lavfi', '-i', 'testsrc=duration=1:size=80x60:rate=10',
            '-f', 'lavfi', '-i', 'sine=frequency=880:duration=1',
            '-c:v', 'libx264', '-c:a', 'aac', '-shortest', $this->fixture2,
        ]);

        $this->watermarkImage = $this->path('logo.png');
        $this->ffmpeg(['-f', 'lavfi', '-i', 'color=red:size=8x8', '-frames:v', '1', $this->watermarkImage]);

        $this->otherAudio = $this->path('other-audio.m4a');
        $this->ffmpeg(['-f', 'lavfi', '-i', 'sine=frequency=220:duration=2', '-c:a', 'aac', $this->otherAudio]);

        $this->subtitleFile = $this->path('subs.srt');
        file_put_contents($this->subtitleFile, "1\n00:00:00,000 --> 00:00:01,000\nHello\n");

        $this->subtitleFixture = $this->path('with-subs.mp4');
        $this->ffmpeg([
            '-i', $this->fixture, '-i', $this->subtitleFile,
            '-map', '0', '-map', '1', '-c', 'copy', '-c:s', 'mov_text',
            $this->subtitleFixture,
        ]);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        if (!$this->available || !is_dir($this->workDir)) {
            return;
        }

        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->workDir . '/.*') ?: [] as $file) {
            if (basename($file) !== '.' && basename($file) !== '..') {
                @unlink($file);
            }
        }

        @rmdir($this->workDir);
    }

    public function transcodeProducesAPlayableOutput(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(
            Pipeline::from($this->fixture)->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac', videoBitrateKbps: 500)),
            'transcode.mp4',
        );

        $info = $this->prober->probe($result->outputPath());
        Assert::same($info->videoCodec(), 'h264');
        Assert::same($info->audioCodec(), 'aac');
    }

    public function remuxStreamCopiesLosslessly(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(Pipeline::from($this->fixture)->add(new Remux()), 'remux.mp4');

        $info = $this->prober->probe($result->outputPath());
        Assert::same($info->videoCodec(), 'h264');
        Assert::same($info->audioCodec(), 'aac');
    }

    public function trimCutsToTheRequestedDuration(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(
            Pipeline::from($this->fixture)->add(new Trim(Duration::millis(500), Duration::millis(1_500))),
            'trim.mp4',
        );

        $info = $this->prober->probe($result->outputPath());
        Assert::true(abs($info->duration()->toSeconds() - 1.0) < 0.2);
    }

    public function fastSeekTrimCutsToTheRequestedDuration(): void
    {
        if (!$this->available) {
            return;
        }

        // Input-side -ss + output -t must produce the same [from, to) slice
        // as the accurate output-side pair.
        $result = $this->run(
            Pipeline::from($this->fixture)->add(new Trim(Duration::millis(500), Duration::millis(1_500), fastSeek: true)),
            'trim-fast.mp4',
        );

        $info = $this->prober->probe($result->outputPath());
        Assert::true(abs($info->duration()->toSeconds() - 1.0) < 0.2);
    }

    public function addMetadataWritesContainerTags(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(
            Pipeline::from($this->fixture)
                ->add(new Remux())
                ->add(new AddMetadata(['title' => 'Integration Title', 'artist' => 'Media Converter'])),
            'metadata.mp4',
        );

        $format = (array) ($this->ffprobeJson($result->outputPath())['format'] ?? []);
        $tags = (array) ($format['tags'] ?? []);
        Assert::same($tags['title'] ?? null, 'Integration Title');
        Assert::same($tags['artist'] ?? null, 'Media Converter');
    }

    public function addArtworkForVideoEmbedsAnAttachedPicture(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(
            Pipeline::from($this->fixture)
                ->add(new Remux())
                ->add(AddArtwork::forVideo($this->watermarkImage)),
            'artwork.mp4',
        );

        Assert::true($this->hasAttachedPicture($result->outputPath()));
    }

    public function addArtworkForAudioEmbedsACoverInAnMp3(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(
            Pipeline::from($this->fixture)
                ->add(new Transcode(audioCodec: 'libmp3lame'))
                ->add(AddArtwork::forAudio($this->watermarkImage)),
            'artwork.mp3',
        );

        Assert::true($this->hasAttachedPicture($result->outputPath()));
        // The prober skips cover-art streams when picking the video stream:
        // an MP3 with embedded art still reports no video track.
        $info = $this->prober->probe($result->outputPath());
        Assert::true($info->hasAudio());
        Assert::false($info->hasVideo());
    }

    public function scaleResizesTheVideo(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(Pipeline::from($this->fixture)->add(new Scale(width: 40)), 'scale.mp4');

        $info = $this->prober->probe($result->outputPath());
        Assert::same($info->width(), 40);
        Assert::same($info->height(), 30);
    }

    public function cropCropsToTheGivenRectangle(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(Pipeline::from($this->fixture)->add(new Crop(40, 30, 0, 0)), 'crop.mp4');

        $info = $this->prober->probe($result->outputPath());
        Assert::same($info->width(), 40);
        Assert::same($info->height(), 30);
    }

    public function rotateSwapsDimensions(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(Pipeline::from($this->fixture)->add(new Rotate(90)), 'rotate.mp4');

        $info = $this->prober->probe($result->outputPath());
        Assert::same($info->width(), 60);
        Assert::same($info->height(), 80);
    }

    public function padExpandsTheCanvas(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(Pipeline::from($this->fixture)->add(new Pad(100, 100, 10, 10)), 'pad.mp4');

        $info = $this->prober->probe($result->outputPath());
        Assert::same($info->width(), 100);
        Assert::same($info->height(), 100);
    }

    public function fpsForcesAConstantFrameRate(): void
    {
        if (!$this->available) {
            return;
        }

        $this->run(Pipeline::from($this->fixture)->add(new Fps(5)), 'fps.mp4');

        Assert::true(is_file($this->path('fps.mp4')));
    }

    public function extractAudioDropsVideoAndKeepsAudio(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(Pipeline::from($this->fixture)->add(new ExtractAudio('libmp3lame', 128)), 'audio.mp3');

        $info = $this->prober->probe($result->outputPath());
        Assert::same($info->audioCodec(), 'mp3');
        Assert::false($info->hasVideo());
    }

    public function thumbnailProducesASingleStillFrame(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(
            Pipeline::from($this->fixture)->add(new Thumbnail(Duration::seconds(1), 40)),
            'thumb.jpg',
        );

        $info = $this->prober->probe($result->outputPath());
        Assert::same($info->width(), 40);
        Assert::false($info->hasAudio());
    }

    public function spriteSheetProducesAContactSheet(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(
            Pipeline::from($this->fixture)->add(new SpriteSheet(rows: 2, cols: 2, interval: Duration::millis(500), tileWidth: 20)),
            'sheet.png',
        );

        $info = $this->prober->probe($result->outputPath());
        Assert::same($info->width(), 40);
    }

    public function animatedPreviewProducesAGif(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(
            Pipeline::from($this->fixture)->add(new AnimatedPreview(Duration::seconds(0), Duration::seconds(1), fps: 5, width: 32)),
            'preview.gif',
        );

        Assert::true(is_file($result->outputPath()));
        $info = $this->prober->probe($result->outputPath());
        Assert::same($info->width(), 32);
    }

    public function textOverlayBurnsTextWithoutChangingDimensions(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(Pipeline::from($this->fixture)->add(new TextOverlay('Hi')), 'text-overlay.mp4');

        $info = $this->prober->probe($result->outputPath());
        Assert::same($info->width(), 80);
    }

    public function watermarkOverlaysAnImage(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(Pipeline::from($this->fixture)->add(new Watermark($this->watermarkImage)), 'watermark.mp4');

        $info = $this->prober->probe($result->outputPath());
        Assert::same($info->width(), 80);
        Assert::true($info->hasAudio());
    }

    public function replaceAudioSwapsTheAudioTrack(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(Pipeline::from($this->fixture)->add(new ReplaceAudio($this->otherAudio)), 'replace-audio.mp4');

        $info = $this->prober->probe($result->outputPath());
        Assert::true($info->hasVideo());
        Assert::true($info->hasAudio());
    }

    public function watermarkAndReplaceAudioComposeIntoSingleOutputStreams(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(
            Pipeline::from($this->fixture)
                ->add(new Watermark($this->watermarkImage))
                ->add(new ReplaceAudio($this->otherAudio)),
            'watermark-replace-audio.mp4',
        );

        $info = $this->prober->probe($result->outputPath());
        Assert::true($info->hasVideo());
        Assert::true($info->hasAudio());
    }

    public function normalizeLoudnessProducesAPlayableAudioTrack(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(
            Pipeline::from($this->fixture)->add(new NormalizeLoudness())->add(new Transcode(audioCodec: 'aac')),
            'normalize.mp4',
        );

        $info = $this->prober->probe($result->outputPath());
        Assert::true($info->hasAudio());
    }

    public function burnSubtitlesRendersWithoutError(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(Pipeline::from($this->fixture)->add(new BurnSubtitles($this->subtitleFile)), 'burn-subs.mp4');

        $info = $this->prober->probe($result->outputPath());
        Assert::true($info->hasVideo());
    }

    public function extractSubtitlesExtractsTheEmbeddedTrack(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(Pipeline::from($this->subtitleFixture)->add(new ExtractSubtitles(0)), 'extracted.srt');

        Assert::string((string) file_get_contents($result->outputPath()))->contains('Hello');
    }

    public function packageHlsProducesAPlaylistAndSegments(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(
            Pipeline::from($this->fixture)->add(new PackageHls(1))->add(new Transcode(videoCodec: 'libx264')),
            'playlist.m3u8',
        );

        $playlist = (string) file_get_contents($result->outputPath());
        Assert::string($playlist)->contains('#EXT-X-ENDLIST');
        Assert::true(glob($this->workDir . '/*.ts') !== [] && glob($this->workDir . '/*.ts') !== false);
        Assert::true(count($result->outputArtifacts()) > 1);
        Assert::true($result->outputBytes() > filesize($result->outputPath()));
    }

    public function packageHlsCommitsACustomSegmentPattern(): void
    {
        if (!$this->available) {
            return;
        }

        $pattern = $this->path('custom-%03d.ts');
        $result = $this->run(
            Pipeline::from($this->fixture)->add(new PackageHls(1, $pattern))->add(new Transcode(videoCodec: 'libx264')),
            'custom-playlist.m3u8',
        );

        Assert::true(glob($this->workDir . '/custom-*.ts') !== [] && glob($this->workDir . '/custom-*.ts') !== false);
        Assert::false(str_contains((string) file_get_contents($result->outputPath()), '.media-converter-'));
    }

    public function packageDashProducesAManifestAndSegments(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(
            Pipeline::from($this->fixture)->add(new PackageDash(1))->add(new Transcode(videoCodec: 'libx264')),
            'manifest.mpd',
        );

        Assert::true(is_file($result->outputPath()));
        Assert::true(glob($this->workDir . '/*.m4s') !== [] && glob($this->workDir . '/*.m4s') !== false);
        Assert::true(count($result->outputArtifacts()) > 1);
    }

    public function concatJoinsTwoSegments(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(Pipeline::concat([$this->fixture, $this->fixture2]), 'concat.mp4');

        $info = $this->prober->probe($result->outputPath());
        Assert::true($info->duration()->toSeconds() > 2.5);
    }

    public function concatAndReplaceAudioProduceOneVideoAndOneAudioStream(): void
    {
        if (!$this->available) {
            return;
        }

        // The segments' audio pads are dropped (a=0) — emitting a=1 alongside
        // the replacement map leaves [outa] unconnected and ffmpeg refuses.
        $result = $this->run(
            Pipeline::concat([$this->fixture, $this->fixture2])->add(new ReplaceAudio($this->otherAudio)),
            'concat-replace-audio.mp4',
        );

        $info = $this->prober->probe($result->outputPath());
        Assert::true($info->hasVideo());
        Assert::true($info->hasAudio());
        Assert::true($info->duration()->toSeconds() > 1.5);
    }

    public function presetWebThumbnailProducesAResizedFrame(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(Presets::webThumbnail($this->fixture, Duration::seconds(1), 40), 'preset-thumb.jpg');

        $info = $this->prober->probe($result->outputPath());
        Assert::same($info->width(), 40);
    }

    public function presetMp3ProducesAnMp3(): void
    {
        if (!$this->available) {
            return;
        }

        $result = $this->run(Presets::mp3($this->fixture, 128), 'preset-audio.mp3');

        $info = $this->prober->probe($result->outputPath());
        Assert::same($info->audioCodec(), 'mp3');
    }

    public function progressCallbackReceivesDeterminateSamplesFromRealFfmpeg(): void
    {
        if (!$this->available) {
            return;
        }

        $converter = new MediaConverter($this->binary, $this->runner, prober: $this->prober);
        $samples = [];

        $converter->run(
            Pipeline::from($this->fixture)->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac')),
            $this->path('progress.mp4'),
            onProgress: static function (ProgressEvent $event) use (&$samples): void {
                $samples[] = $event;
            },
        );

        Assert::true($samples !== []);
        $determinate = array_filter($samples, static fn(ProgressEvent $event): bool => $event->isDeterminate());
        Assert::true($determinate !== []);

        foreach ($determinate as $event) {
            $fraction = $event->fraction();
            Assert::true($fraction !== null && $fraction >= 0.0 && $fraction <= 1.0);
        }
    }

    public function trimmedProgressUsesTheOutputTimeline(): void
    {
        if (!$this->available) {
            return;
        }

        $converter = new MediaConverter($this->binary, $this->runner, prober: $this->prober);
        $fractions = [];

        $converter->run(
            Pipeline::from($this->fixture)->add(new Trim(Duration::millis(500), Duration::millis(1_500))),
            $this->path('trim-progress.mp4'),
            onProgress: static function (ProgressEvent $event) use (&$fractions): void {
                if ($event->fraction() !== null) {
                    $fractions[] = $event->fraction();
                }
            },
        );

        Assert::true($fractions !== []);
        // ffmpeg may report the last encoded timestamp before container/audio
        // finalization, so completion need not produce an exact 1.0 sample.
        Assert::true($fractions[array_key_last($fractions)] > 0.7);
    }

    /**
     * @param list<string> $argv ffmpeg arguments, WITHOUT the binary path or `-hide_banner -nostdin -y`
     */
    private function ffmpeg(array $argv): void
    {
        $outcome = $this->runner->run(
            [$this->binary->ffmpegPath(), '-hide_banner', '-nostdin', '-y', ...$argv],
            Duration::seconds(30),
            Duration::seconds(15),
            static function (string $type, string $chunk): void {},
        );

        if (!$outcome->isSuccess()) {
            throw new \RuntimeException('Fixture generation failed: ' . $outcome->stderrTail);
        }
    }

    /** @return array<string, mixed> */
    private function ffprobeJson(string $path): array
    {
        $stdout = '';
        $outcome = $this->runner->run(
            [$this->binary->ffprobePath(), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path],
            Duration::seconds(30),
            Duration::seconds(15),
            static function (string $type, string $chunk) use (&$stdout): void {
                if ($type === 'out') {
                    $stdout .= $chunk;
                }
            },
        );

        if (!$outcome->isSuccess()) {
            throw new \RuntimeException('ffprobe failed: ' . $outcome->stderrTail);
        }

        return (array) json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    }

    private function hasAttachedPicture(string $path): bool
    {
        foreach ((array) ($this->ffprobeJson($path)['streams'] ?? []) as $stream) {
            $disposition = (array) (((array) $stream)['disposition'] ?? []);

            if (($disposition['attached_pic'] ?? 0) === 1) {
                return true;
            }
        }

        return false;
    }

    private function run(Pipeline $pipeline, string $outputFilename): ConversionResult
    {
        return $this->converter->run($pipeline, $this->path($outputFilename));
    }

    private function path(string $filename): string
    {
        return $this->workDir . '/' . $filename;
    }
}
