<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

use Rasuvaeff\Duration\Duration;

/**
 * Outcome of a successful {@see ConvertsMedia::run()} call.
 *
 * @api
 */
final readonly class ConversionResult
{
    /**
     * @param list<string> $outputArtifacts
     * @param list<string> $command
     */
    public function __construct(
        private string $outputPath,
        private Duration $elapsed,
        private int $inputBytes,
        private int $outputBytes,
        private array $outputArtifacts = [],
        private array $command = [],
    ) {
        if ($outputPath === '') {
            throw new \InvalidArgumentException('Output path cannot be empty');
        }

        if ($inputBytes < 0) {
            throw new \InvalidArgumentException('Input bytes cannot be negative');
        }

        if ($outputBytes < 0) {
            throw new \InvalidArgumentException('Output bytes cannot be negative');
        }

        foreach ($outputArtifacts as $artifact) {
            if ($artifact === '') {
                throw new \InvalidArgumentException('Output artifact path cannot be empty');
            }
        }
    }

    public function outputPath(): string
    {
        return $this->outputPath;
    }

    public function elapsed(): Duration
    {
        return $this->elapsed;
    }

    public function inputBytes(): int
    {
        return $this->inputBytes;
    }

    public function outputBytes(): int
    {
        return $this->outputBytes;
    }

    public function outputMebibytes(): float
    {
        return (float) $this->outputBytes / 1024.0 / 1024.0;
    }

    /**
     * Every committed file. For single-file conversions this contains only
     * {@see outputPath()}; package operations also include their sidecars.
     *
     * @return non-empty-list<string>
     */
    public function outputArtifacts(): array
    {
        return $this->outputArtifacts !== [] ? $this->outputArtifacts : [$this->outputPath];
    }

    /**
     * The exact ffmpeg argv used for the conversion, excluding progress flags
     * added only when a progress callback was supplied.
     *
     * @return list<string>
     */
    public function command(): array
    {
        return $this->command;
    }
}
