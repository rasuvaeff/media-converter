<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Internal;

use Rasuvaeff\MediaConverter\CommandSpec;
use Rasuvaeff\MediaConverter\FfmpegBinary;

/**
 * Materialises a {@see CommandSpec} into the ffmpeg argv, in the order ffmpeg
 * requires: global options, then each input (input-level options BEFORE its
 * `-i`), then stream-copy / filter_complex / filters, stream maps, output
 * options, and finally the output path.
 *
 * Pure and deterministic — the same spec always yields the same argv (there
 * is no ordering ambiguity to resolve), which is what the property test pins.
 *
 * @internal
 */
final class ArgBuilder
{
    /**
     * @return list<string>
     */
    public static function build(FfmpegBinary $binary, CommandSpec $spec, string $outputPath): array
    {
        if ($spec->inputs() === []) {
            throw new \LogicException('CommandSpec has no input');
        }

        if ($outputPath === '') {
            throw new \InvalidArgumentException('Output path cannot be empty');
        }

        $argv = [$binary->ffmpegPath(), '-hide_banner', '-nostdin', '-y'];

        foreach ($spec->inputs() as $input) {
            foreach ($input['options'] as $option) {
                $argv[] = $option;
            }
            $argv[] = '-i';
            $argv[] = $input['source'];
        }

        if ($spec->isStreamCopy()) {
            $argv[] = '-c';
            $argv[] = 'copy';
        }

        if ($spec->filterComplexSegments() !== []) {
            $argv[] = '-filter_complex';
            $argv[] = implode(';', $spec->filterComplexSegments());
        }

        if ($spec->videoFilters() !== []) {
            $argv[] = '-vf';
            $argv[] = FilterGraph::chain($spec->videoFilters());
        }

        if ($spec->audioFilters() !== []) {
            $argv[] = '-af';
            $argv[] = FilterGraph::chain($spec->audioFilters());
        }

        foreach ([$spec->videoOutput(), $spec->audioOutput(), $spec->subtitleOutput(), ...$spec->maps()] as $map) {
            if ($map === null) {
                continue;
            }

            $argv[] = '-map';
            $argv[] = $map;
        }

        foreach ($spec->outputOptions() as $option) {
            $argv[] = $option;
        }

        $argv[] = $outputPath;

        return $argv;
    }
}
