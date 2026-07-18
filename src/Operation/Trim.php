<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Internal\Timestamp;

/**
 * Keep only the `[from, to)` slice of the timeline.
 *
 * By default this is an OUTPUT-side `-ss`/`-to` (accurate seek): ffmpeg
 * decodes to the cut point, so the cut is frame-accurate at the cost of
 * seeking through the input. With `$fastSeek` the `-ss` moves BEFORE `-i`
 * (input seek): ffmpeg jumps near-instantly via the container index instead
 * of decoding the skipped part — on a long input this is seconds instead of
 * minutes. Input seeking resets output timestamps to zero, so the end bound
 * is emitted as a duration (`-t to-from`), preserving the same `[from, to)`
 * slice. When transcoding, modern ffmpeg decodes from the preceding keyframe
 * and discards up to the requested point, so the cut stays frame-accurate;
 * combined with {@see Remux} (stream copy) either mode lands on keyframes.
 * Fast-seek is rejected on a {@see \Rasuvaeff\MediaConverter\Pipeline::concat()}
 * pipeline — the input option would apply to the first segment only.
 *
 * Trim adds no filter, so it composes with {@see Remux}.
 *
 * @api
 */
final readonly class Trim implements OperationInterface
{
    public function __construct(
        private Duration $from,
        private ?Duration $to = null,
        private bool $fastSeek = false,
    ) {
        if ($from->toMicros() < 0) {
            throw new \InvalidArgumentException('Trim start cannot be negative');
        }

        if ($to instanceof Duration && !$to->isGreaterThan($from)) {
            throw new \InvalidArgumentException('Trim end must be after the start');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->claimOutputSeek(self::class);
        $spec->trimTimeline($this->from, $this->to);

        if ($this->fastSeek) {
            $spec->addPrimaryInputOptions(self::class, '-ss', Timestamp::format($this->from));

            if ($this->to instanceof Duration) {
                $spec->addOutputOption('-t', Timestamp::format($this->to->minus($this->from)));
            }

            return;
        }

        $spec->addOutputOption('-ss', Timestamp::format($this->from));

        if ($this->to instanceof Duration) {
            $spec->addOutputOption('-to', Timestamp::format($this->to));
        }
    }
}
