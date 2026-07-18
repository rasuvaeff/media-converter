<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Internal;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\Internal\Timestamp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Timestamp::class)]
final class TimestampTest
{
    #[DataProvider('durationProvider')]
    public function formatsSecondsWithTrailingZerosTrimmed(Duration $duration, string $expected): void
    {
        Assert::same(Timestamp::format($duration), $expected);
    }

    public static function durationProvider(): iterable
    {
        yield 'zero' => [Duration::zero(), '0'];
        yield 'whole seconds' => [Duration::seconds(90), '90'];
        yield 'fractional' => [Duration::millis(1_500), '1.5'];
        yield 'sub-second' => [Duration::millis(250), '0.25'];
        yield 'microsecond precision' => [Duration::micros(1_234_567), '1.234567'];
    }

    public function formatIsLocaleIndependent(): void
    {
        // %.6f would render "1,5" under a comma-decimal LC_NUMERIC — argv
        // ffmpeg rejects. Skips silently where no such locale is installed.
        $previous = setlocale(LC_NUMERIC, '0');

        if (setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'ru_RU.UTF-8', 'ru_RU') === false) {
            return;
        }

        try {
            Assert::same(Timestamp::format(Duration::millis(1_500)), '1.5');
        } finally {
            if ($previous !== false) {
                setlocale(LC_NUMERIC, $previous);
            }
        }
    }
}
