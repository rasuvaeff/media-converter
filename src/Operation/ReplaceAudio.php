<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Replace the primary source's audio with a track from `$audioSource`
 * (added as a second `-i`): the output video comes from input 0, the audio
 * from the new input. `$shortest` (default true) trims the output to the
 * shorter of the two streams, avoiding trailing silence or a frozen frame
 * when their durations differ.
 *
 * @api
 */
final readonly class ReplaceAudio implements OperationInterface
{
    public function __construct(
        private string $audioSource,
        private bool $shortest = true,
    ) {
        if ($audioSource === '') {
            throw new \InvalidArgumentException('Audio source cannot be empty');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $audioInputIndex = $spec->addInput($this->audioSource, timeline: false);

        $spec->setDefaultVideoOutput('0:v');
        $spec->setAudioOutput(sprintf('%d:a', $audioInputIndex));

        if ($this->shortest) {
            $spec->addOutputOption('-shortest');
            $spec->markProgressIndeterminate();
        }
    }
}
