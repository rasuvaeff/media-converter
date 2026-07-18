<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Internal\FilterGraph;

/**
 * Burn text into the video (`drawtext`), anchored at a {@see Position} with a
 * margin. `expansion=none` is always set so a literal `%` in the text is
 * never treated as the start of drawtext's `%{...}` expression syntax; the
 * text itself is quoted via {@see FilterGraph::quoteDrawtextValue()}, a
 * different escaping scheme from a path argument. `x`/`y` are ffmpeg
 * expressions built from the built-in `w`/`h`/`text_w`/`text_h` variables, so
 * placement adapts to the actual frame and rendered-text size.
 *
 * @api
 */
final readonly class TextOverlay implements OperationInterface
{
    private const string VALID_COLOR_PATTERN = '/^[A-Za-z0-9#@.]+$/';

    public function __construct(
        private string $text,
        private Position $position = Position::BottomRight,
        private int $margin = 16,
        private int $fontSize = 24,
        private string $fontColor = 'white',
        private ?string $fontFile = null,
    ) {
        if ($text === '') {
            throw new \InvalidArgumentException('Text overlay text cannot be empty');
        }

        if ($margin < 0) {
            throw new \InvalidArgumentException('Margin cannot be negative');
        }

        if ($fontSize <= 0) {
            throw new \InvalidArgumentException('Font size must be positive');
        }

        if (preg_match(self::VALID_COLOR_PATTERN, $fontColor) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid font color "%s"', $fontColor));
        }

        if ($fontFile !== null && str_contains($fontFile, "'")) {
            throw new \InvalidArgumentException('Font file path cannot contain a single quote (ffmpeg cannot embed one in a filter argument)');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $parts = ['drawtext=expansion=none'];

        if ($this->fontFile !== null) {
            $parts[] = sprintf('fontfile=%s', FilterGraph::escapeArgument($this->fontFile));
        }

        $parts[] = sprintf('text=%s', FilterGraph::quoteDrawtextValue($this->text));
        $parts[] = sprintf('x=%s', $this->xExpression());
        $parts[] = sprintf('y=%s', $this->yExpression());
        $parts[] = sprintf('fontsize=%d', $this->fontSize);
        $parts[] = sprintf('fontcolor=%s', $this->fontColor);

        $spec->addVideoFilter(implode(':', $parts));
    }

    private function xExpression(): string
    {
        return match ($this->position) {
            Position::TopLeft, Position::MiddleLeft, Position::BottomLeft => (string) $this->margin,
            Position::TopCenter, Position::Center, Position::BottomCenter => '(w-text_w)/2',
            Position::TopRight, Position::MiddleRight, Position::BottomRight => sprintf('w-text_w-%d', $this->margin),
        };
    }

    private function yExpression(): string
    {
        return match ($this->position) {
            Position::TopLeft, Position::TopCenter, Position::TopRight => (string) $this->margin,
            Position::MiddleLeft, Position::Center, Position::MiddleRight => '(h-text_h)/2',
            Position::BottomLeft, Position::BottomCenter, Position::BottomRight => sprintf('h-text_h-%d', $this->margin),
        };
    }
}
