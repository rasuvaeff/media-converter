<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Internal\Timestamp;

/**
 * Extract a single still frame at a timestamp (`-ss` + `-frames:v 1`), with
 * an optional resize. Output-side seek, like {@see Trim} — accurate at the
 * cost of decoding through the input. No audio stream is muxed into the
 * output image (`-an`).
 *
 * @api
 */
final readonly class Thumbnail implements OperationInterface
{
    public function __construct(
        private Duration $at,
        private ?int $width = null,
    ) {
        if ($at->toMicros() < 0) {
            throw new \InvalidArgumentException('Thumbnail timestamp cannot be negative');
        }

        if ($width !== null && $width <= 0) {
            throw new \InvalidArgumentException('Width must be positive');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->requestVideoOnly(self::class);
        $spec->claimOutputSeek(self::class);
        $spec->markProgressIndeterminate();
        $spec->addOutputOption('-ss', Timestamp::format($this->at));
        $spec->addOutputOption('-frames:v', '1');
        $spec->addOutputOption('-an');

        if ($this->width !== null) {
            $spec->addVideoFilter(sprintf('scale=%d:-2', $this->width));
        }
    }
}
