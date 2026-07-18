<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\Rotate;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Rotate::class)]
final class RotateTest
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('rotationProvider')]
    public function emitsTransposeNodes(int $degrees, array $expected): void
    {
        $spec = new CommandSpec();
        (new Rotate($degrees))->applyTo($spec);

        Assert::same($spec->videoFilters(), $expected);
    }

    public static function rotationProvider(): iterable
    {
        yield '90' => [90, ['transpose=1']];
        yield '180' => [180, ['transpose=1', 'transpose=1']];
        yield '270' => [270, ['transpose=2']];
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonRightAngle(): void
    {
        new Rotate(45);
    }
}
