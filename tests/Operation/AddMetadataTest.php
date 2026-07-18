<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\Operation\AddMetadata;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(AddMetadata::class)]
final class AddMetadataTest
{
    public function emitsAMetadataOptionPerTagInInsertionOrder(): void
    {
        $spec = new CommandSpec();
        (new AddMetadata(['title' => 'My Song', 'artist' => 'Me']))->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-metadata', 'title=My Song', '-metadata', 'artist=Me']);
        Assert::false($spec->hasFilters());
    }

    public function coercesAnIntegerValueToItsDecimalString(): void
    {
        $spec = new CommandSpec();
        (new AddMetadata(['track' => 7]))->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-metadata', 'track=7']);
    }

    public function passesSpecialCharactersInValuesVerbatim(): void
    {
        // Values are plain argv elements, not filtergraph syntax: '=', quotes,
        // unicode and newlines need no escaping.
        $spec = new CommandSpec();
        (new AddMetadata(['comment' => "a=b\nаб'c"]))->applyTo($spec);

        Assert::same($spec->outputOptions(), ['-metadata', "comment=a=b\nаб'c"]);
    }

    public function acceptsEveryWhitelistedKey(): void
    {
        $keys = [
            'title', 'artist', 'album', 'album_artist', 'composer', 'genre',
            'track', 'disc', 'year', 'date', 'comment', 'description',
            'language', 'copyright', 'publisher', 'encoder',
        ];
        $spec = new CommandSpec();
        (new AddMetadata(array_fill_keys($keys, 'x')))->applyTo($spec);

        Assert::same(count($spec->outputOptions()), 2 * count($keys));
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsEmptyTags(): void
    {
        new AddMetadata([]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsANonWhitelistedKey(): void
    {
        new AddMetadata(['tilte' => 'typo']);
    }
}
