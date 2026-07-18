<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

use Rasuvaeff\MediaConverter\Internal\ArgBuilder;
use Rasuvaeff\MediaConverter\Internal\OutputLayout;
use Rasuvaeff\MediaConverter\Internal\OutputMode;
use Rasuvaeff\MediaConverter\Operation\OperationInterface;

/**
 * An ordered composition of {@see OperationInterface}s over one primary
 * source. Immutable: {@see add()} returns a new pipeline. {@see toArgv()}
 * applies every operation to a fresh {@see CommandSpec}, validates the
 * composition, and materialises the ffmpeg argv.
 *
 * Validation is fail-fast and happens BEFORE any subprocess: an incompatible
 * combination (stream-copy together with a filter or a codec option; a
 * self-contained filter graph or a multi-input `-filter_complex` operation
 * together with another video filter operation) throws {@see ConversionFailed}
 * with {@see ConversionFailureReason::IncompatibleOperations}.
 *
 * @api
 */
final readonly class Pipeline
{
    /**
     * @param list<string>              $inputOptions   input-level options for the primary source (`-headers`, `-cookies`, `-ss`)
     * @param list<OperationInterface>  $operations
     * @param list<string>              $concatSegments empty for a normal single-source pipeline; 2+ segments for {@see concat()}
     */
    private function __construct(
        private string $source,
        private array $inputOptions,
        private array $operations,
        private array $concatSegments = [],
        private bool $concatHasAudio = true,
    ) {
        if ($source === '') {
            throw new \InvalidArgumentException('Pipeline source cannot be empty');
        }
    }

    /**
     * Start a pipeline from a primary source (URL or local path).
     *
     * @param list<string> $inputOptions input-level ffmpeg options emitted before `-i`
     */
    public static function from(string $source, array $inputOptions = []): self
    {
        return new self($source, $inputOptions, []);
    }

    /**
     * Start a pipeline that concatenates `$segments` via the ffmpeg `concat`
     * FILTER (`-filter_complex … concat=n=…:v=1:a=…[outv][outa]`) — a
     * re-encode, not the lossless `concat` demuxer (which needs a temp list
     * file and its own cleanup lifecycle; deferred, see the plan). Concat is
     * not an {@see OperationInterface}: every operation decorates ONE
     * pre-existing primary source, but concat composes several segments INTO
     * the source, so it gets its own pipeline constructor instead of forcing
     * a decorator to add/remove inputs behind {@see toArgv()}'s back.
     *
     * `$hasAudio` must match every segment (the concat filter fails loudly
     * at ffmpeg runtime, not silently, if a segment lacks the audio stream
     * this claims). Further operations can still be `add()`-ed to run on the
     * concatenated result — a plain video OR audio filter operation is
     * rejected (concat feeds both streams through `-filter_complex`, which
     * ffmpeg cannot combine with `-vf`/`-af`), a codec-only one like
     * `Transcode` composes fine. An operation that explicitly claims the
     * audio output (e.g. `ReplaceAudio`) drops the segments' audio pads
     * (`a=0`) so the replacement track is the only audio stream.
     *
     * @param list<string> $segments 2 or more segment paths/URLs, in order
     */
    public static function concat(array $segments, bool $hasAudio = true): self
    {
        if (count($segments) < 2) {
            throw new \InvalidArgumentException('Concat needs at least two segments');
        }

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new \InvalidArgumentException('Concat segment cannot be empty');
            }
        }

        return new self($segments[0], [], [], $segments, $hasAudio);
    }

    public function add(OperationInterface $operation): self
    {
        return new self($this->source, $this->inputOptions, [...$this->operations, $operation], $this->concatSegments, $this->concatHasAudio);
    }

    /**
     * The primary source (URL or local path) this pipeline reads from. For a
     * {@see concat()} pipeline, this remains the first segment for backward
     * compatibility; use {@see sources()} to inspect every consumed segment.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * Every primary source consumed by this pipeline. A normal pipeline has
     * one source; concat exposes every segment in order.
     *
     * @return non-empty-list<string>
     */
    public function sources(): array
    {
        return $this->concatSegments !== [] ? $this->concatSegments : [$this->source];
    }

    /**
     * Build the ffmpeg argv for this pipeline writing to $outputPath.
     *
     * @return list<string>
     *
     * @throws ConversionFailed when the composed operations are incompatible
     */
    public function toArgv(FfmpegBinary $binary, string $outputPath): array
    {
        $spec = $this->commandSpec();

        return ArgBuilder::build($binary, $spec, $outputPath);
    }

    /** @internal */
    public function outputLayout(): OutputLayout
    {
        return $this->commandSpec()->outputLayout();
    }

    private function addConcatInputs(CommandSpec $spec): void
    {
        $spec->claimFilterComplex('concat');

        foreach ($this->concatSegments as $segment) {
            $spec->addInput($segment);
        }
    }

    /**
     * Built AFTER the operations have applied, so the graph can react to what
     * they did: when an operation explicitly claimed the audio output (e.g.
     * {@see \Rasuvaeff\MediaConverter\Operation\ReplaceAudio} mapping its own
     * input), the segments' audio pads are dropped (`a=0`) — emitting `a=1`
     * would leave the `[outa]` label unconnected, which ffmpeg rejects.
     */
    private function buildConcatFilterComplex(CommandSpec $spec): void
    {
        $hasAudio = $this->concatHasAudio && !$spec->audioSelectionExplicit();
        $pads = '';

        foreach (array_keys($this->concatSegments) as $index) {
            $pads .= $hasAudio ? sprintf('[%d:v][%d:a]', $index, $index) : sprintf('[%d:v]', $index);
        }

        $outputPads = $hasAudio ? '[outv][outa]' : '[outv]';

        $spec->addFilterComplexSegment(sprintf('%sconcat=n=%d:v=1:a=%d%s', $pads, count($this->concatSegments), $hasAudio ? 1 : 0, $outputPads));
        $spec->setGraphVideoOutput('[outv]');

        if ($hasAudio) {
            $spec->setGraphAudioOutput('[outa]');
        }
    }

    /**
     * @throws ConversionFailed
     */
    private function validate(CommandSpec $spec): void
    {
        $this->validateOutputMode($spec);

        if (count($spec->filterComplexOwners()) > 1) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::IncompatibleOperations,
                exitCode: 0,
                stderr: sprintf('Only one operation may own the complex video graph; got: %s', implode(', ', $spec->filterComplexOwners())),
            );
        }

        if ($spec->hasExclusiveVideoGraph() && count($spec->videoFilters()) > 1) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::IncompatibleOperations,
                exitCode: 0,
                stderr: 'A self-contained filter graph operation (e.g. AnimatedPreview) is incompatible with another video filter operation',
            );
        }

        if ($spec->filterComplexSegments() !== [] && $spec->videoFilters() !== []) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::IncompatibleOperations,
                exitCode: 0,
                stderr: 'A multi-input operation (e.g. Watermark) is incompatible with a plain video filter operation; ffmpeg cannot combine -vf and -filter_complex for the same stream. Use an audio-only filter (e.g. NormalizeLoudness) instead, or pre-compose the video graph manually',
            );
        }

        if ($spec->audioFedByComplexGraph() && $spec->audioFilters() !== []) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::IncompatibleOperations,
                exitCode: 0,
                stderr: 'A concat pipeline routes audio through the complex filter graph and is incompatible with a plain audio filter operation; ffmpeg cannot combine -af and -filter_complex for the same stream. Apply the audio filter upstream (re-encode each segment first), or drop ReplaceAudio so concat\'s audio pads drop (a=0)',
            );
        }

        if ($spec->videoFedByComplexGraph() && $spec->videoSelectionExplicit()) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::IncompatibleOperations,
                exitCode: 0,
                stderr: 'Explicit video stream selection is incompatible with an operation that produces the video stream through the complex filter graph (e.g. Watermark, concat)',
            );
        }

        if (count($spec->artworkOwners()) > 1) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::IncompatibleOperations,
                exitCode: 0,
                stderr: sprintf('Only one operation may attach cover art; got: %s', implode(', ', $spec->artworkOwners())),
            );
        }

        $this->validateArtworkStreamCopyOrder($spec);

        if ($this->concatSegments !== [] && $spec->primaryInputOptionOwners() !== []) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::IncompatibleOperations,
                exitCode: 0,
                stderr: sprintf('A concat pipeline is incompatible with an operation adding primary-input options (they would apply to the first segment only); got: %s', implode(', ', $spec->primaryInputOptionOwners())),
            );
        }

        if (count($spec->seekOwners()) > 1) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::IncompatibleOperations,
                exitCode: 0,
                stderr: sprintf('Only one operation may seek the output timeline; got: %s', implode(', ', $spec->seekOwners())),
            );
        }

        if (!$spec->isStreamCopy()) {
            return;
        }

        if ($spec->hasFilters()) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::IncompatibleOperations,
                exitCode: 0,
                stderr: 'Stream copy (remux) is incompatible with a filter operation; drop the filter or transcode instead',
            );
        }

        $options = $spec->outputOptions();

        foreach ($options as $index => $option) {
            // A codec/bitrate option means an operation is re-encoding, which
            // contradicts stream copy — except an explicit per-stream `copy`
            // (e.g. AddArtwork's `-c:v:1 copy`), which IS stream copy.
            if (str_starts_with($option, '-c:') && ($options[$index + 1] ?? null) === 'copy') {
                continue;
            }

            if (str_starts_with($option, '-c:') || str_starts_with($option, '-b:') || $option === '-codec') {
                throw new ConversionFailed(
                    reason: ConversionFailureReason::IncompatibleOperations,
                    exitCode: 0,
                    stderr: sprintf('Stream copy (remux) is incompatible with the re-encoding option "%s"; use a transcode operation instead', $option),
                );
            }
        }
    }

    /**
     * ffmpeg applies the LAST matching codec option per stream: a generic
     * `-c:v` (from a video Transcode) overrides the per-stream `-c:v:N copy`
     * that AddArtwork emits, discarding the cover. AddArtwork must therefore
     * be `add()`-ed AFTER the transcode — detect the order from the option
     * index and reject the wrong one before ffmpeg runs.
     */
    private function validateArtworkStreamCopyOrder(CommandSpec $spec): void
    {
        if ($spec->artworkOwners() === []) {
            return;
        }

        $options = $spec->outputOptions();
        $videoCodecIndex = null;
        $artworkCopyIndex = null;

        foreach ($options as $index => $option) {
            if ($option === '-c:v') {
                $videoCodecIndex = $index;
            } elseif (
                str_starts_with($option, '-c:v:')
                && ($options[$index + 1] ?? null) === 'copy'
            ) {
                $artworkCopyIndex = $index;
            }
        }

        if ($videoCodecIndex !== null && $artworkCopyIndex !== null && $videoCodecIndex > $artworkCopyIndex) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::IncompatibleOperations,
                exitCode: 0,
                stderr: 'AddArtwork must be added AFTER a video Transcode — ffmpeg applies the last matching -c:v option per stream, so a later -c:v overrides the cover stream\'s -c:v:N copy',
            );
        }
    }

    private function validateOutputMode(CommandSpec $spec): void
    {
        if (count($spec->outputModeOwners()) > 1) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::IncompatibleOperations,
                exitCode: 0,
                stderr: sprintf('Only one terminal output operation is allowed; got: %s', implode(', ', $spec->outputModeOwners())),
            );
        }

        $videoOptions = array_intersect($spec->outputOptions(), ['-c:v', '-b:v', '-frames:v']);
        $audioOptions = array_intersect($spec->outputOptions(), ['-c:a', '-b:a']);
        // Raw maps (only AddArtwork emits them) route an extra stream into the
        // output, contradicting every single-stream-kind terminal mode: `-vn`
        // (ExtractAudio) discards the mapped cover, `-frames:v 1` outputs
        // cannot carry it either.
        $incompatible = match ($spec->outputMode()) {
            OutputMode::Normal => false,
            OutputMode::AudioOnly => $spec->videoFilters() !== [] || $spec->filterComplexSegments() !== [] || $spec->videoOutput() !== null || $spec->subtitleOutput() !== null || $videoOptions !== [] || $spec->maps() !== [],
            OutputMode::VideoOnly => $spec->audioFilters() !== [] || $spec->audioOutput() !== null || $spec->subtitleOutput() !== null || $audioOptions !== [] || $spec->maps() !== [],
            OutputMode::SubtitleOnly => $spec->videoFilters() !== [] || $spec->audioFilters() !== [] || $spec->filterComplexSegments() !== [] || $spec->videoOutput() !== null || $spec->audioOutput() !== null || $videoOptions !== [] || $audioOptions !== [] || $spec->maps() !== [],
        };

        if ($incompatible) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::IncompatibleOperations,
                exitCode: 0,
                stderr: sprintf('Terminal output operation %s conflicts with operations targeting another stream kind', $spec->outputModeOwner() ?? 'unknown'),
            );
        }
    }

    /** @internal */
    public function commandSpec(): CommandSpec
    {
        $spec = new CommandSpec();

        if ($this->concatSegments !== []) {
            $this->addConcatInputs($spec);
        } else {
            $spec->addInput($this->source, $this->inputOptions);
        }

        foreach ($this->operations as $operation) {
            $operation->applyTo($spec);
        }

        if ($this->concatSegments !== []) {
            $this->buildConcatFilterComplex($spec);
        }

        $this->validate($spec);

        return $spec;
    }
}
