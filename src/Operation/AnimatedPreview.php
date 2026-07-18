<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Internal\Timestamp;

/**
 * A `[$from, $to)` animated GIF preview, palette-optimised in a single ffmpeg
 * pass: `fps,scale,split[s0][s1];[s0]palettegen[p];[s1][p]paletteuse`. This
 * graph is single-input/single-output (branches internally, no separate `-i`
 * or `-map`), so it renders through the normal `-vf` chain and does not need
 * a `-filter_complex` invocation — verified against a real ffmpeg build.
 *
 * The graph is a complete, self-contained pipeline over the source frames;
 * combining it with another video filter operation is rejected by
 * {@see \Rasuvaeff\MediaConverter\Pipeline} ({@see CommandSpec::markExclusiveVideoGraph()}).
 * Trim the animated range with the constructor's own `$from`/`$to` — a
 * separate {@see Trim} operation is unnecessary (and, since Trim adds no
 * filter, harmless but redundant).
 *
 * @api
 */
final readonly class AnimatedPreview implements OperationInterface
{
    public function __construct(
        private Duration $from,
        private Duration $to,
        private int $fps = 10,
        private int $width = 320,
    ) {
        if ($from->toMicros() < 0) {
            throw new \InvalidArgumentException('AnimatedPreview start cannot be negative');
        }

        if (!$to->isGreaterThan($from)) {
            throw new \InvalidArgumentException('AnimatedPreview end must be after the start');
        }

        if ($fps <= 0) {
            throw new \InvalidArgumentException('AnimatedPreview fps must be positive');
        }

        if ($width <= 0) {
            throw new \InvalidArgumentException('AnimatedPreview width must be positive');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->requestVideoOnly(self::class);
        $spec->trimTimeline($this->from, $this->to);
        $spec->addOutputOption('-ss', Timestamp::format($this->from));
        $spec->addOutputOption('-to', Timestamp::format($this->to));
        $spec->addVideoFilter(sprintf(
            'fps=%d,scale=%d:-2,split[s0][s1];[s0]palettegen[p];[s1][p]paletteuse',
            $this->fps,
            $this->width,
        ));
        $spec->markExclusiveVideoGraph();
    }
}
