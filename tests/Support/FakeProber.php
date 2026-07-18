<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Support;

use Rasuvaeff\MediaConverter\MediaInfo;
use Rasuvaeff\MediaConverter\ProbesMedia;

final class FakeProber implements ProbesMedia
{
    /** @var list<string> */
    public array $sources = [];

    /** @var list<MediaInfo> */
    private array $info;

    /** @param MediaInfo|list<MediaInfo> $info */
    public function __construct(
        MediaInfo|array $info,
    ) {
        $this->info = $info instanceof MediaInfo ? [$info] : $info;
    }

    #[\Override]
    public function probe(string $source): MediaInfo
    {
        $this->sources[] = $source;
        $info = count($this->info) > 1 ? array_shift($this->info) : $this->info[0] ?? null;

        if (!$info instanceof MediaInfo) {
            throw new \LogicException('FakeProber has no queued media info');
        }

        return $info;
    }
}
