<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\ConversionResult;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ConversionResult::class)]
final class ConversionResultTest
{
    public function exposesConstructorValues(): void
    {
        $result = new ConversionResult(
            outputPath: '/tmp/out.mp4',
            elapsed: Duration::seconds(1.5),
            inputBytes: 10_000_000,
            outputBytes: 5_242_880,
            outputArtifacts: ['/tmp/out.mp4', '/tmp/out0.ts'],
            command: ['/usr/bin/ffmpeg', '-i', 'in.mp4', 'out.mp4'],
        );

        Assert::same($result->outputPath(), '/tmp/out.mp4');
        Assert::same($result->elapsed()->toMillis(), 1_500);
        Assert::same($result->inputBytes(), 10_000_000);
        Assert::same($result->outputBytes(), 5_242_880);
        Assert::same($result->outputMebibytes(), 5.0);
        Assert::same($result->outputArtifacts(), ['/tmp/out.mp4', '/tmp/out0.ts']);
        Assert::same($result->command(), ['/usr/bin/ffmpeg', '-i', 'in.mp4', 'out.mp4']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsEmptyOutputPath(): void
    {
        new ConversionResult('', Duration::zero(), 0, 0);
    }

    public function acceptsZeroInputAndOutputBytes(): void
    {
        $result = new ConversionResult('/tmp/out.mp4', Duration::zero(), 0, 0);

        Assert::same($result->inputBytes(), 0);
        Assert::same($result->outputBytes(), 0);
        Assert::same($result->outputArtifacts(), ['/tmp/out.mp4']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeInputBytes(): void
    {
        new ConversionResult('/tmp/out.mp4', Duration::zero(), -1, 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeOutputBytes(): void
    {
        new ConversionResult('/tmp/out.mp4', Duration::zero(), 0, -1);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptyArtifactPath(): void
    {
        new ConversionResult('/tmp/out.mp4', Duration::zero(), 0, 0, ['/tmp/out.mp4', '']);
    }
}
