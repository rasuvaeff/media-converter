<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Internal;

use Rasuvaeff\Duration\Duration;

/**
 * Computes a completion fraction from an out-time and an optional total
 * duration: null when the total is unknown or non-positive — a stream-copy
 * remux or a live source may never yield a known duration, so the caller
 * reports INDETERMINATE progress rather than a fabricated percentage.
 * Otherwise the fraction is clamped to `[0.0, 1.0]` — ffmpeg's `out_time` can
 * exceed the probed total near the end of a run (rounding, container
 * overhead).
 *
 * @internal
 */
final class ProgressFraction
{
    public static function compute(Duration $outTime, ?Duration $total): ?float
    {
        if (!$total instanceof Duration || !$total->isPositive()) {
            return null;
        }

        return max(0.0, min(1.0, $outTime->toSeconds() / $total->toSeconds()));
    }
}
