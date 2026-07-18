<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Support;

use Psr\SimpleCache\CacheInterface;

final class FakeSimpleCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $storage = [];

    /** @var array<string, \DateInterval|int|null> */
    public array $ttls = [];

    /** @var list<string> */
    public array $requestedKeys = [];

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $this->requestedKeys[] = $key;

        return $this->storage[$key] ?? $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->storage[$key] = $value;
        $this->ttls[$key] = $ttl;

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        unset($this->storage[$key], $this->ttls[$key]);

        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        $this->storage = [];
        $this->ttls = [];

        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
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
        return array_key_exists($key, $this->storage);
    }
}
