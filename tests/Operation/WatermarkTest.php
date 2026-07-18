<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\Position;
use Rasuvaeff\MediaConverter\Operation\Watermark;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Watermark::class)]
final class WatermarkTest
{
    public function addsTheImageInputAndOverlaysWithoutOpacity(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('video.mp4');
        (new Watermark('logo.png'))->applyTo($spec);

        Assert::same($spec->inputs(), [
            ['source' => 'video.mp4', 'options' => []],
            ['source' => 'logo.png', 'options' => []],
        ]);
        Assert::same(
            $spec->filterComplexSegments(),
            ['[0:v][1:v]overlay=main_w-overlay_w-16:main_h-overlay_h-16[wmout]'],
        );
        Assert::same($spec->videoOutput(), '[wmout]');
        Assert::same($spec->audioOutput(), '0:a?');
        Assert::same($spec->maps(), []);
        Assert::same($spec->probeSources(), ['video.mp4']);
        Assert::true($spec->hasFilters());
    }

    public function insertsAFormatAndColorchannelmixerStageWhenOpacityIsGiven(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('video.mp4');
        (new Watermark('logo.png', opacity: 0.5))->applyTo($spec);

        Assert::same($spec->filterComplexSegments(), [
            '[1:v]format=rgba,colorchannelmixer=aa=0.5[wm]',
            '[0:v][wm]overlay=main_w-overlay_w-16:main_h-overlay_h-16[wmout]',
        ]);
    }

    public function referencesTheCorrectInputIndexWhenNotFirst(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('video.mp4');
        $spec->addInput('audio.mp3');
        (new Watermark('logo.png'))->applyTo($spec);

        Assert::same($spec->filterComplexSegments(), [
            '[0:v][2:v]overlay=main_w-overlay_w-16:main_h-overlay_h-16[wmout]',
        ]);
    }

    public function claimsTheFilterComplexOwnership(): void
    {
        // A second complex-graph operation (another Watermark, Concat) would
        // collide on the pad labels; the claim lets Pipeline reject it.
        $spec = new CommandSpec();
        $spec->addInput('video.mp4');
        (new Watermark('logo.png'))->applyTo($spec);

        Assert::same($spec->filterComplexOwners(), [Watermark::class]);
    }

    public function theImageInputStaysOffTheTimeline(): void
    {
        // The overlay image has no duration of its own — letting it join the
        // timeline sources would skew progress totals.
        $spec = new CommandSpec();
        $spec->addInput('video.mp4');
        (new Watermark('logo.png'))->applyTo($spec);

        Assert::same($spec->timelineSources(), ['video.mp4']);
    }

    #[DataProvider('positionProvider')]
    public function placesTheOverlayAtEachAnchor(Position $position, string $expectedX, string $expectedY): void
    {
        $spec = new CommandSpec();
        $spec->addInput('video.mp4');
        (new Watermark('logo.png', position: $position, margin: 10))->applyTo($spec);

        Assert::same($spec->filterComplexSegments(), [sprintf('[0:v][1:v]overlay=%s:%s[wmout]', $expectedX, $expectedY)]);
    }

    public static function positionProvider(): iterable
    {
        yield 'top-left' => [Position::TopLeft, '10', '10'];
        yield 'top-center' => [Position::TopCenter, '(main_w-overlay_w)/2', '10'];
        yield 'top-right' => [Position::TopRight, 'main_w-overlay_w-10', '10'];
        yield 'middle-left' => [Position::MiddleLeft, '10', '(main_h-overlay_h)/2'];
        yield 'center' => [Position::Center, '(main_w-overlay_w)/2', '(main_h-overlay_h)/2'];
        yield 'middle-right' => [Position::MiddleRight, 'main_w-overlay_w-10', '(main_h-overlay_h)/2'];
        yield 'bottom-left' => [Position::BottomLeft, '10', 'main_h-overlay_h-10'];
        yield 'bottom-center' => [Position::BottomCenter, '(main_w-overlay_w)/2', 'main_h-overlay_h-10'];
        yield 'bottom-right' => [Position::BottomRight, 'main_w-overlay_w-10', 'main_h-overlay_h-10'];
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptyImage(): void
    {
        new Watermark('');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANegativeMargin(): void
    {
        new Watermark('logo.png', margin: -1);
    }

    public function acceptsAZeroMargin(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('video.mp4');
        (new Watermark('logo.png', margin: 0))->applyTo($spec);

        Assert::same($spec->filterComplexSegments(), ['[0:v][1:v]overlay=main_w-overlay_w-0:main_h-overlay_h-0[wmout]']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAZeroOpacity(): void
    {
        new Watermark('logo.png', opacity: 0.0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANegativeOpacity(): void
    {
        new Watermark('logo.png', opacity: -0.1);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnOpacityAboveOne(): void
    {
        new Watermark('logo.png', opacity: 1.1);
    }

    public function acceptsAnOpacityOfOne(): void
    {
        $spec = new CommandSpec();
        $spec->addInput('video.mp4');
        (new Watermark('logo.png', opacity: 1.0))->applyTo($spec);

        Assert::same($spec->filterComplexSegments()[0], '[1:v]format=rgba,colorchannelmixer=aa=1[wm]');
    }
}
