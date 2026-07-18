<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Preset;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Preset\Presets;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Presets::class)]
final class PresetsTest
{
    private FfmpegBinary $binary;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->binary = FfmpegBinary::default();
    }

    public function hlsToMp4StreamCopiesAndFixesTheAacBitstream(): void
    {
        $argv = Presets::hlsToMp4('in.m3u8')->toArgv($this->binary, 'out.mp4');

        Assert::same($argv, [
            '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
            '-i', 'in.m3u8',
            '-c', 'copy',
            '-bsf:a', 'aac_adtstoasc',
            'out.mp4',
        ]);
    }

    public function hlsToMp4TranscodeReencodesToH264Aac(): void
    {
        $argv = Presets::hlsToMp4Transcode('in.m3u8')->toArgv($this->binary, 'out.mp4');

        Assert::same($argv, [
            '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
            '-i', 'in.m3u8',
            '-c:v', 'libx264', '-c:a', 'aac',
            'out.mp4',
        ]);
    }

    public function webMp4UsesThePortableH264AacProfile(): void
    {
        $argv = Presets::webMp4('in.mov', 1_500, 128)->toArgv($this->binary, 'out.mp4');

        Assert::true(in_array('libx264', $argv, true));
        Assert::true(in_array('1500k', $argv, true));
        Assert::true(in_array('128k', $argv, true));
    }

    public function dashToMp4StreamCopiesWithoutABitstreamFilter(): void
    {
        $argv = Presets::dashToMp4('in.mpd')->toArgv($this->binary, 'out.mp4');

        Assert::same($argv, [
            '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
            '-i', 'in.mpd',
            '-c', 'copy',
            'out.mp4',
        ]);
    }

    public function mp3UsesLibmp3lameAndTheGivenBitrate(): void
    {
        $argv = Presets::mp3('in.mp4', 128)->toArgv($this->binary, 'out.mp3');

        Assert::same($argv, [
            '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
            '-i', 'in.mp4',
            '-vn', '-c:a', 'libmp3lame', '-b:a', '128k',
            'out.mp3',
        ]);
    }

    public function mp3DefaultsTo192Kbps(): void
    {
        $argv = Presets::mp3('in.mp4')->toArgv($this->binary, 'out.mp3');

        Assert::true(in_array('192k', $argv, true));
    }

    public function aacUsesTheNativeAacEncoder(): void
    {
        $argv = Presets::aac('in.mp4')->toArgv($this->binary, 'out.m4a');

        Assert::same($argv, [
            '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
            '-i', 'in.mp4',
            '-vn', '-c:a', 'aac', '-b:a', '128k',
            'out.m4a',
        ]);
    }

    public function aacUsesTheGivenBitrate(): void
    {
        $argv = Presets::aac('in.mp4', 192)->toArgv($this->binary, 'out.m4a');

        Assert::true(in_array('192k', $argv, true));
    }

    public function webMUsesVp9AndOpus(): void
    {
        $argv = Presets::webM('in.mp4')->toArgv($this->binary, 'out.webm');

        Assert::same($argv, [
            '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
            '-i', 'in.mp4',
            '-c:v', 'libvpx-vp9', '-c:a', 'libopus', '-b:v', '2500k', '-b:a', '128k',
            'out.webm',
        ]);
    }

    public function webMUsesTheGivenBitrates(): void
    {
        $argv = Presets::webM('in.mp4', 1_000, 96)->toArgv($this->binary, 'out.webm');

        Assert::true(in_array('1000k', $argv, true));
        Assert::true(in_array('96k', $argv, true));
    }

    public function webThumbnailSeeksAndScales(): void
    {
        $argv = Presets::webThumbnail('in.mp4', Duration::seconds(5))->toArgv($this->binary, 'out.jpg');

        Assert::same($argv, [
            '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
            '-i', 'in.mp4',
            '-vf', 'scale=320:-2',
            '-ss', '5', '-frames:v', '1', '-an',
            'out.jpg',
        ]);
    }

    public function webThumbnailUsesAGivenWidth(): void
    {
        $argv = Presets::webThumbnail('in.mp4', Duration::seconds(5), 640)->toArgv($this->binary, 'out.jpg');

        Assert::true(in_array('scale=640:-2', $argv, true));
    }

    public function socialClipTrimsScalesAndTranscodes(): void
    {
        $argv = Presets::socialClip('in.mp4', Duration::seconds(10), Duration::seconds(25))
            ->toArgv($this->binary, 'out.mp4');

        Assert::same($argv, [
            '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y',
            '-i', 'in.mp4',
            '-vf', 'scale=720:-2',
            '-ss', '10', '-to', '25',
            '-c:v', 'libx264', '-c:a', 'aac',
            'out.mp4',
        ]);
    }

    public function socialClipUsesAGivenMaxWidth(): void
    {
        $argv = Presets::socialClip('in.mp4', Duration::seconds(0), Duration::seconds(5), maxWidth: 480)
            ->toArgv($this->binary, 'out.mp4');

        Assert::true(in_array('scale=480:-2', $argv, true));
    }
}
