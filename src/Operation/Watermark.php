<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Overlay `$image` (added as a second `-i`) onto the primary video, anchored
 * at a {@see Position} with a margin, with an optional opacity. Ffmpeg's
 * `overlay` filter needs a second input, so this composes a
 * `-filter_complex` graph (`[1:v]format=rgba,colorchannelmixer=aa=…[wm];
 * [0:v][wm]overlay=x:y[wmout]`) rather than a plain `-vf` node — verified
 * against a real ffmpeg build that `-vf` and `-filter_complex` cannot target
 * the same output stream together ("Simple and complex filtering cannot be
 * used together for the same stream"), so {@see \Rasuvaeff\MediaConverter\Pipeline}
 * rejects combining `Watermark` with a plain video filter operation. Maps the
 * composed video output plus the primary source's audio, if any (`0:a?`).
 *
 * Only one `Watermark` per pipeline is supported in v1 — a second one would
 * collide on the same pad labels.
 *
 * @api
 */
final readonly class Watermark implements OperationInterface
{
    public function __construct(
        private string $image,
        private Position $position = Position::BottomRight,
        private int $margin = 16,
        private ?float $opacity = null,
    ) {
        if ($image === '') {
            throw new \InvalidArgumentException('Watermark image cannot be empty');
        }

        if ($margin < 0) {
            throw new \InvalidArgumentException('Margin cannot be negative');
        }

        if ($opacity !== null && ($opacity <= 0.0 || $opacity > 1.0)) {
            throw new \InvalidArgumentException('Opacity must be between 0 (exclusive) and 1 (inclusive)');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->claimFilterComplex(self::class);
        $imageInputIndex = $spec->addInput($this->image, probe: false, timeline: false);
        $overlaySource = sprintf('%d:v', $imageInputIndex);

        if ($this->opacity !== null) {
            $spec->addFilterComplexSegment(sprintf(
                '[%s]format=rgba,colorchannelmixer=aa=%s[wm]',
                $overlaySource,
                $this->opacity,
            ));
            $overlaySource = 'wm';
        }

        $spec->addFilterComplexSegment(sprintf(
            '[0:v][%s]overlay=%s:%s[wmout]',
            $overlaySource,
            $this->xExpression(),
            $this->yExpression(),
        ));
        $spec->setGraphVideoOutput('[wmout]');
        $spec->setDefaultAudioOutput('0:a?');
    }

    private function xExpression(): string
    {
        return match ($this->position) {
            Position::TopLeft, Position::MiddleLeft, Position::BottomLeft => (string) $this->margin,
            Position::TopCenter, Position::Center, Position::BottomCenter => '(main_w-overlay_w)/2',
            Position::TopRight, Position::MiddleRight, Position::BottomRight => sprintf('main_w-overlay_w-%d', $this->margin),
        };
    }

    private function yExpression(): string
    {
        return match ($this->position) {
            Position::TopLeft, Position::TopCenter, Position::TopRight => (string) $this->margin,
            Position::MiddleLeft, Position::Center, Position::MiddleRight => '(main_h-overlay_h)/2',
            Position::BottomLeft, Position::BottomCenter, Position::BottomRight => sprintf('main_h-overlay_h-%d', $this->margin),
        };
    }
}
