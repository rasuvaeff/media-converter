<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CachedProbesMedia;
use Rasuvaeff\MediaConverter\MediaInfo;
use Rasuvaeff\MediaConverter\Tests\Support\FakeProber;
use Rasuvaeff\MediaConverter\Tests\Support\FakeSimpleCache;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CachedProbesMedia::class)]
final class CachedProbesMediaTest
{
    public function aCacheMissCallsTheInnerProberAndStoresWithTheDefaultTtl(): void
    {
        $inner = new FakeProber($this->mediaInfo());
        $cache = new FakeSimpleCache();

        $probed = (new CachedProbesMedia($inner, $cache))->probe('http://example.test/video.mp4');

        Assert::same($inner->sources, ['http://example.test/video.mp4']);
        Assert::same(array_values($cache->storage), [$probed]);
        Assert::same(array_values($cache->ttls), [86_400]);
    }

    public function aCacheHitSkipsTheInnerProber(): void
    {
        $inner = new FakeProber($this->mediaInfo());
        $cache = new FakeSimpleCache();
        $decorator = new CachedProbesMedia($inner, $cache);

        $first = $decorator->probe('http://example.test/video.mp4');
        $second = $decorator->probe('http://example.test/video.mp4');

        Assert::same($second, $first);
        Assert::same($inner->sources, ['http://example.test/video.mp4']);
    }

    public function theKeyIsPrefixedAndFreeOfPsr16ReservedCharacters(): void
    {
        $cache = new FakeSimpleCache();
        (new CachedProbesMedia(new FakeProber($this->mediaInfo()), $cache))->probe('http://example.test/a video.mp4');

        $key = $cache->requestedKeys[0];
        Assert::true(str_starts_with($key, 'rasuvaeff.media-converter.probe.'));
        Assert::same(preg_match('#[{}()/\\\\@:]#', $key), 0);
    }

    public function aGarbageCacheValueIsTreatedAsAMiss(): void
    {
        $inner = new FakeProber($this->mediaInfo());
        $cache = new FakeSimpleCache();
        $decorator = new CachedProbesMedia($inner, $cache);

        $decorator->probe('http://example.test/video.mp4');
        $cache->storage = array_map(static fn(): string => 'garbage', $cache->storage);
        $decorator->probe('http://example.test/video.mp4');

        Assert::same(count($inner->sources), 2);
    }

    public function aChangedFileGetsANewCacheKey(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'probe-cache-');
        Assert::true(is_string($path));

        try {
            file_put_contents($path, 'aa');
            clearstatcache();
            $cache = new FakeSimpleCache();
            $decorator = new CachedProbesMedia(new FakeProber($this->mediaInfo()), $cache);

            $decorator->probe($path);
            file_put_contents($path, 'aaa-grown');
            clearstatcache();
            $decorator->probe($path);

            Assert::true($cache->requestedKeys[0] !== $cache->requestedKeys[1]);
        } finally {
            @unlink($path);
        }
    }

    public function aTouchedFileWithTheSameSizeGetsANewCacheKey(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'probe-cache-');
        Assert::true(is_string($path));

        try {
            file_put_contents($path, 'aa');
            touch($path, 1_000_000_000);
            clearstatcache();
            $cache = new FakeSimpleCache();
            $decorator = new CachedProbesMedia(new FakeProber($this->mediaInfo()), $cache);

            $decorator->probe($path);
            touch($path, 1_000_000_100);
            clearstatcache();
            $decorator->probe($path);

            Assert::true($cache->requestedKeys[0] !== $cache->requestedKeys[1]);
        } finally {
            @unlink($path);
        }
    }

    public function anUnreadableSourceFallsBackToAStablePathOnlyKey(): void
    {
        $inner = new FakeProber($this->mediaInfo());
        $cache = new FakeSimpleCache();
        $decorator = new CachedProbesMedia($inner, $cache);

        $decorator->probe('/no/such/file.mp4');
        $decorator->probe('/no/such/file.mp4');

        Assert::same($cache->requestedKeys[0], $cache->requestedKeys[1]);
        Assert::same($inner->sources, ['/no/such/file.mp4']);
    }

    public function aNullTtlIsForwardedToTheBackend(): void
    {
        $cache = new FakeSimpleCache();
        (new CachedProbesMedia(new FakeProber($this->mediaInfo()), $cache, ttlSeconds: null))->probe('src.mp4');

        Assert::same(array_values($cache->ttls), [null]);
    }

    public function aCustomTtlIsForwardedToTheBackend(): void
    {
        $cache = new FakeSimpleCache();
        (new CachedProbesMedia(new FakeProber($this->mediaInfo()), $cache, ttlSeconds: 60))->probe('src.mp4');

        Assert::same(array_values($cache->ttls), [60]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAZeroTtl(): void
    {
        new CachedProbesMedia(new FakeProber($this->mediaInfo()), new FakeSimpleCache(), ttlSeconds: 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANegativeTtl(): void
    {
        new CachedProbesMedia(new FakeProber($this->mediaInfo()), new FakeSimpleCache(), ttlSeconds: -1);
    }

    private function mediaInfo(): MediaInfo
    {
        return new MediaInfo(
            duration: Duration::seconds(10),
            width: 640,
            height: 480,
            videoCodec: 'h264',
            audioCodec: 'aac',
            bitrate: 1_000_000,
        );
    }
}
