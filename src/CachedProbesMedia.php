<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache decorator over {@see ProbesMedia}. Caches ffprobe results
 * keyed by a hash of (path, filesize, filemtime) — a changed file gets a new
 * key, so invalidation is automatic and the TTL only guards against inode
 * reuse. For a non-local source (URL) size/mtime are unavailable and the key
 * degrades to the path alone — the cache then trusts the TTL entirely.
 *
 * Keys are dot-separated: PSR-16 reserves `{}()/\@:` in keys.
 *
 * A garbage cache value (wrong type after a backend hiccup) is treated as a
 * miss, never an error.
 *
 * @api
 */
final readonly class CachedProbesMedia implements ProbesMedia
{
    private const string CACHE_KEY_PREFIX = 'rasuvaeff.media-converter.probe.';
    private const int DEFAULT_TTL_SECONDS = 86_400;

    public function __construct(
        private ProbesMedia $inner,
        private CacheInterface $cache,
        private ?int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            throw new \InvalidArgumentException('TTL must be positive');
        }
    }

    #[\Override]
    public function probe(string $source): MediaInfo
    {
        $key = $this->cacheKey($source);
        /** @var mixed $cached */
        $cached = $this->cache->get($key);

        if ($cached instanceof MediaInfo) {
            return $cached;
        }

        $info = $this->inner->probe($source);
        $this->cache->set($key, $info, $this->ttlSeconds);

        return $info;
    }

    private function cacheKey(string $source): string
    {
        $size = is_file($source) ? filesize($source) : false;
        $mtime = is_file($source) ? filemtime($source) : false;

        return self::CACHE_KEY_PREFIX . hash('xxh3', sprintf(
            '%s|%s|%s',
            $source,
            $size === false ? '-' : $size,
            $mtime === false ? '-' : $mtime,
        ));
    }
}
