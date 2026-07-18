<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\MediaInfo;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(MediaInfo::class)]
final class MediaInfoTest
{
    public function exposesConstructorValues(): void
    {
        $info = new MediaInfo(
            duration: Duration::seconds(12.5),
            width: 1920,
            height: 1080,
            videoCodec: 'h264',
            audioCodec: 'aac',
            bitrate: 4_500_000,
        );

        Assert::same($info->duration()->toMillis(), 12_500);
        Assert::same($info->width(), 1920);
        Assert::same($info->height(), 1080);
        Assert::same($info->videoCodec(), 'h264');
        Assert::same($info->audioCodec(), 'aac');
        Assert::same($info->bitrate(), 4_500_000);
        Assert::true($info->hasVideo());
        Assert::true($info->hasAudio());
        Assert::false($info->isEncrypted());
    }

    public function audioOnlySourceHasNoVideo(): void
    {
        $info = new MediaInfo(Duration::seconds(200), null, null, null, 'mp3', 320_000);

        Assert::false($info->hasVideo());
        Assert::true($info->hasAudio());
    }

    public function encryptedFlagIsExposed(): void
    {
        $info = new MediaInfo(Duration::seconds(1), 640, 480, 'h264', null, null, encrypted: true);

        Assert::true($info->isEncrypted());
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonPositiveWidth(): void
    {
        new MediaInfo(Duration::zero(), 0, 480, 'h264', null, null);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonPositiveHeight(): void
    {
        new MediaInfo(Duration::zero(), 640, -1, 'h264', null, null);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAZeroHeight(): void
    {
        new MediaInfo(Duration::zero(), 640, 0, 'h264', null, null);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonPositiveBitrate(): void
    {
        new MediaInfo(Duration::zero(), 640, 480, 'h264', 'aac', 0);
    }
}
