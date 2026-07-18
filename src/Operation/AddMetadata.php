<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Operation;

use Rasuvaeff\MediaConverter\CommandSpec;

/**
 * Add container-level metadata tags via `-metadata key=value` (MP3, MP4/M4A,
 * FLAC, Ogg, MKV, …). Keys are whitelisted — a typo like `tilte` would
 * otherwise be silently written as an unknown tag. Values pass through argv
 * verbatim (no filtergraph involved), so `=`, unicode and newlines are safe.
 * Does NOT embed cover art — see {@see AddArtwork}.
 *
 * Composes with {@see Remux}: writing tags needs no re-encode.
 *
 * @api
 */
final readonly class AddMetadata implements OperationInterface
{
    private const array WHITELISTED_KEYS = [
        'title', 'artist', 'album', 'album_artist', 'composer', 'genre',
        'track', 'disc', 'year', 'date', 'comment', 'description',
        'language', 'copyright', 'publisher', 'encoder',
    ];

    /**
     * @param non-empty-array<string, string|int> $tags map of whitelisted metadata keys to values
     */
    public function __construct(
        private array $tags,
    ) {
        if ($tags === []) {
            throw new \InvalidArgumentException('Metadata tags cannot be empty');
        }

        foreach (array_keys($tags) as $key) {
            if (!in_array($key, self::WHITELISTED_KEYS, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown metadata key "%s"', $key));
            }
        }
    }

    #[\Override]
    public function applyTo(CommandSpec $spec): void
    {
        foreach ($this->tags as $key => $value) {
            $spec->addOutputOption('-metadata', sprintf('%s=%s', $key, $value));
        }
    }
}
