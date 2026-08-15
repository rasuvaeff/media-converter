<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Internal;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\Internal\ProgressFraction;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
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

        // The clamp exists for the overshoot ffmpeg produces near the end of a
        // run; a generator that never overshoots would leave `min(1.0, …)`
        // unexercised and the property still green.
        Classify::cover($outTimeMicros > $totalMicros, 'past the total', 20.0);
        Classify::cover($outTimeMicros <= $totalMicros, 'within the total', 20.0);

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

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function fractionIsAlwaysClampedToZeroOneEvenPastTheTotalExamples(): iterable
    {
        yield 'nothing written yet' => [0, 1_000_000];
        yield 'exactly complete' => [1_000_000, 1_000_000];
        yield 'one microsecond past the total' => [1_000_001, 1_000_000];
        yield 'the shortest possible total' => [500_000, 1];
    }

    #[Property(runs: 200)]
    public function anUnusableTotalIsAlwaysIndeterminate(int $outTimeMicros, ?int $totalMicros): void
    {
        $total = $totalMicros === null ? null : Duration::micros($totalMicros);

        Classify::cover($totalMicros === null, 'total never probed', 20.0);
        Classify::cover($totalMicros !== null, 'total probed as zero', 20.0);

        // A probe that returned nothing and a probe that returned zero are the
        // same situation for the caller: report INDETERMINATE rather than
        // divide, or worse, fabricate 0%.
        Assert::null(ProgressFraction::compute(Duration::micros($outTimeMicros), $total));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function anUnusableTotalIsAlwaysIndeterminateGenerators(): array
    {
        return [
            'outTimeMicros' => Gen::intBetween(0, 1_000_000_000),
            // The only two unusable totals that exist: absent, and zero.
            // A negative one is unrepresentable — Duration rejects it in its
            // constructor — so there is nothing else to generate here.
            'totalMicros' => Gen::nullable(Gen::constant(0)),
        ];
    }
}
