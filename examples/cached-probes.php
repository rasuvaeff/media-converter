<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CachedProbesMedia;
use Rasuvaeff\MediaConverter\MediaInfo;
use Rasuvaeff\MediaConverter\ProbesMedia;

// CachedProbesMedia decorates any ProbesMedia with a PSR-16 cache so repeated
// ffprobe calls for the same source are served from cache. It needs a
// psr/simple-cache implementation (composer require psr/simple-cache + a
// concrete cache such as symfony/cache); here both the prober and the cache
// are tiny in-memory stand-ins so the example runs without ffmpeg.
$inner = new class implements ProbesMedia {
    public int $calls = 0;

    #[\Override]
    public function probe(string $source): MediaInfo
    {
        ++$this->calls;

        return new MediaInfo(
            duration: Duration::seconds(90),
            width: 1920,
            height: 1080,
            videoCodec: 'h264',
            audioCodec: 'aac',
            bitrate: 5_000_000,
        );
    }
};

$cache = new class implements CacheInterface {
    /** @var array<string, mixed> */
    private array $store = [];

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    #[\Override]
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return isset($this->store[$key]);
    }
};

$prober = new CachedProbesMedia($inner, $cache);

$prober->probe('movie.mp4');
$prober->probe('movie.mp4');

printf("ffprobe ran %d time(s) for two probe() calls (second served from cache)\n", $inner->calls);
