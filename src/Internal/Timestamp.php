<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Internal;

use Rasuvaeff\Duration\Duration;

/**
 * Renders a {@see Duration} as an ffmpeg timestamp (seconds with up to
 * microsecond precision, trailing zeros trimmed), for `-ss`/`-to`.
 *
 * @internal
 */
final class Timestamp
{
    public static function format(Duration $duration): string
    {
        // %.6F, not %.6f: the lowercase specifier is LC_NUMERIC-sensitive and
        // renders "1,500000" under a comma-decimal locale — argv ffmpeg rejects.
        $formatted = rtrim(rtrim(sprintf('%.6F', $duration->toSeconds()), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }
}
