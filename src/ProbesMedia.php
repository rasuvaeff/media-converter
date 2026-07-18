<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

/**
 * Probes a source via ffprobe and returns its metadata.
 *
 * @api
 */
interface ProbesMedia
{
    /**
     * @param string $source URL or local path readable by ffprobe; must not be empty.
     *
     * @throws ConversionFailed if ffprobe cannot read the source.
     */
    public function probe(string $source): MediaInfo;
}
