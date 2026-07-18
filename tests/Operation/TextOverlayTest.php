<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\Position;
use Rasuvaeff\MediaConverter\Operation\TextOverlay;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(TextOverlay::class)]
final class TextOverlayTest
{
    public function usesTheDefaultBottomRightPositionAndStyle(): void
    {
        $spec = new CommandSpec();
        (new TextOverlay('hello'))->applyTo($spec);

        Assert::same(
            $spec->videoFilters(),
            ["drawtext=expansion=none:text='hello':x=w-text_w-16:y=h-text_h-16:fontsize=24:fontcolor=white"],
        );
    }

    #[DataProvider('positionProvider')]
    public function placesTextAtEachAnchor(Position $position, string $expectedX, string $expectedY): void
    {
        $spec = new CommandSpec();
        (new TextOverlay('t', position: $position, margin: 10))->applyTo($spec);

        Assert::string($spec->videoFilters()[0])->contains(sprintf('x=%s:', $expectedX));
        Assert::string($spec->videoFilters()[0])->contains(sprintf('y=%s:', $expectedY));
    }

    public static function positionProvider(): iterable
    {
        yield 'top-left' => [Position::TopLeft, '10', '10'];
        yield 'top-center' => [Position::TopCenter, '(w-text_w)/2', '10'];
        yield 'top-right' => [Position::TopRight, 'w-text_w-10', '10'];
        yield 'middle-left' => [Position::MiddleLeft, '10', '(h-text_h)/2'];
        yield 'center' => [Position::Center, '(w-text_w)/2', '(h-text_h)/2'];
        yield 'middle-right' => [Position::MiddleRight, 'w-text_w-10', '(h-text_h)/2'];
        yield 'bottom-left' => [Position::BottomLeft, '10', 'h-text_h-10'];
        yield 'bottom-center' => [Position::BottomCenter, '(w-text_w)/2', 'h-text_h-10'];
        yield 'bottom-right' => [Position::BottomRight, 'w-text_w-10', 'h-text_h-10'];
    }

    public function quotesTextContainingFilterMetacharacters(): void
    {
        $spec = new CommandSpec();
        (new TextOverlay("it's: 50%, [ok]"))->applyTo($spec);

        Assert::string($spec->videoFilters()[0])->contains("text='it'\\''s: 50%, [ok]'");
    }

    public function escapesAFontFilePath(): void
    {
        $spec = new CommandSpec();
        (new TextOverlay('t', fontFile: 'C:/fonts/custom.ttf'))->applyTo($spec);

        // Double-escaped colon — see FilterGraph::escapeArgument's docblock.
        Assert::string($spec->videoFilters()[0])->contains('fontfile=C\\\\:/fonts/custom.ttf');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAFontFilePathContainingASingleQuote(): void
    {
        new TextOverlay('t', fontFile: "C:/fonts/it's.ttf");
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsEmptyText(): void
    {
        new TextOverlay('');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANegativeMargin(): void
    {
        new TextOverlay('t', margin: -1);
    }

    public function acceptsAZeroMargin(): void
    {
        $spec = new CommandSpec();
        (new TextOverlay('t', margin: 0))->applyTo($spec);

        Assert::string($spec->videoFilters()[0])->contains('x=w-text_w-0:');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANonPositiveFontSize(): void
    {
        new TextOverlay('t', fontSize: 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnInvalidFontColor(): void
    {
        new TextOverlay('t', fontColor: 'rgb(0,0,0)');
    }
}
