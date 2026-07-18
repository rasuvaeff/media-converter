<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Internal\FilterGraph;

/**
 * Burn `.srt`/`.ass` subtitles into the video (`subtitles=filename=…`), path
 * escaped via {@see FilterGraph::escapeArgument()}.
 *
 * @api
 */
final readonly class BurnSubtitles implements OperationInterface
{
    public function __construct(
        private string $srtOrAss,
    ) {
        if ($srtOrAss === '') {
            throw new \InvalidArgumentException('Subtitle path cannot be empty');
        }

        if (str_contains($srtOrAss, "'")) {
            throw new \InvalidArgumentException('Subtitle path cannot contain a single quote (ffmpeg cannot embed one in a filter argument)');
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        $spec->addVideoFilter(sprintf('subtitles=filename=%s', FilterGraph::escapeArgument($this->srtOrAss)));
    }
}
