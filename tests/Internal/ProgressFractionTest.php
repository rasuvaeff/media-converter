<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Internal;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\Internal\ProgressFraction;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ProgressFraction::class)]
final class ProgressFractionTest
{
    public function returnsNullWhenTotalIsNull(): void
    {
        Assert::null(ProgressFraction::compute(Duration::seconds(5), null));
    }

    public function returnsNullWhenTotalIsZero(): void
    {
        Assert::null(ProgressFraction::compute(Duration::seconds(5), Duration::zero()));
    }

    public function computesTheExactFractionWithinRange(): void
    {
        Assert::same(ProgressFraction::compute(Duration::seconds(5), Duration::seconds(10)), 0.5);
    }

    #[Property(runs: 200)]
    public function fractionIsMonotonicNonDecreasingWithOutTimeForAFixedTotal(int $firstOutTimeMicros, int $secondOutTimeMicros, int $totalMicros): void
    {
        $loMicros = min($firstOutTimeMicros, $secondOutTimeMicros);
        $hiMicros = max($firstOutTimeMicros, $secondOutTimeMicros);
        $total = Duration::micros($totalMicros);

        $lo = ProgressFraction::compute(Duration::micros($loMicros), $total);
        $hi = ProgressFraction::compute(Duration::micros($hiMicros), $total);

        Assert::notNull($lo);
        Assert::notNull($hi);
        Assert::true($lo <= $hi);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function fractionIsMonotonicNonDecreasingWithOutTimeForAFixedTotalGenerators(): array
    {
        return [
            'firstOutTimeMicros' => Gen::intBetween(0, 200_000_000),
            'secondOutTimeMicros' => Gen::intBetween(0, 200_000_000),
            'totalMicros' => Gen::intBetween(1, 200_000_000),
        ];
    }

    #[Property(runs: 200)]
    public function fractionIsAlwaysClampedToZeroOneEvenPastTheTotal(int $outTimeMicros, int $totalMicros): void
    {
        $fraction = ProgressFraction::compute(Duration::micros($outTimeMicros), Duration::micros($totalMicros));

        Assert::notNull($fraction);
        Assert::true($fraction >= 0.0 && $fraction <= 1.0);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function fractionIsAlwaysClampedToZeroOneEvenPastTheTotalGenerators(): array
    {
        return [
            'outTimeMicros' => Gen::intBetween(0, 1_000_000_000),
            'totalMicros' => Gen::intBetween(1, 200_000_000),
        ];
    }
}
