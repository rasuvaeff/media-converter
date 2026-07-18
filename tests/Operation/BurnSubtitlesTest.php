<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\BurnSubtitles;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(BurnSubtitles::class)]
final class BurnSubtitlesTest
{
    public function emitsTheSubtitlesFilterWithTheFilenameKey(): void
    {
        $spec = new CommandSpec();
        (new BurnSubtitles('/subs/track.srt'))->applyTo($spec);

        Assert::same($spec->videoFilters(), ['subtitles=filename=/subs/track.srt']);
    }

    public function doubleEscapesAColonInThePath(): void
    {
        $spec = new CommandSpec();
        (new BurnSubtitles('C:/subs/track.srt'))->applyTo($spec);

        // See FilterGraph::escapeArgument's docblock: a single escape survives
        // the filtergraph parser but not the subtitles filter's own internal
        // colon-delimited option parsing.
        Assert::same($spec->videoFilters(), ['subtitles=filename=C\\\\:/subs/track.srt']);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptyPath(): void
    {
        new BurnSubtitles('');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAPathContainingASingleQuote(): void
    {
        new BurnSubtitles("/subs/it's.srt");
    }
}
