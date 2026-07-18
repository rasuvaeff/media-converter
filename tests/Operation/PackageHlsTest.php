<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\PackageHls;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PackageHls::class)]
final class PackageHlsTest
{
    public function usesTheDefaultSegmentDurationAndVodPlaylistType(): void
    {
        $spec = new CommandSpec();
        (new PackageHls())->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-f', 'hls', '-hls_time', '6', '-hls_playlist_type', 'vod']);
    }

    public function usesACustomSegmentDuration(): void
    {
        $spec = new CommandSpec();
        (new PackageHls(2))->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-f', 'hls', '-hls_time', '2', '-hls_playlist_type', 'vod']);
    }

    public function addsTheSegmentFilenamePatternWhenGiven(): void
    {
        $spec = new CommandSpec();
        (new PackageHls(segmentFilenamePattern: 'seg_%03d.ts'))->applyTo($spec);

        Assert::same(
            $spec->outputOptions(),
            ['-f', 'hls', '-hls_time', '6', '-hls_playlist_type', 'vod', '-hls_segment_filename', 'seg_%03d.ts'],
        );
    }

    public function marksTheHlsOutputLayoutWithTheSegmentPattern(): void
    {
        // Without the layout mark the transaction would treat the playlist as
        // a single-file output and never stage the segment sidecars.
        $spec = new CommandSpec();
        (new PackageHls(segmentFilenamePattern: 'seg_%03d.ts'))->applyTo($spec);

        Assert::true($spec->outputLayout()->isHls());
        Assert::same($spec->outputLayout()->hlsSegmentPattern(), 'seg_%03d.ts');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANonPositiveSegmentDuration(): void
    {
        new PackageHls(0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptySegmentFilenamePattern(): void
    {
        new PackageHls(segmentFilenamePattern: '');
    }
}
