<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\Internal\OutputLayout;
use Rasuvaeff\MediaConverter\Internal\OutputMode;

/**
 * Mutable accumulator an {@see \Rasuvaeff\MediaConverter\Operation\OperationInterface}
 * writes into. Operations add inputs, filter nodes, output options and stream
 * maps; {@see \Rasuvaeff\MediaConverter\Internal\ArgBuilder} then materialises
 * the argv from it.
 *
 * Filters are stored as GRAPH NODES (per stream kind), not a pre-joined `-vf`
 * string, so the builder can render a simple chain for a single input today
 * and a `-filter_complex` graph for multi-input overlays later without
 * reworking the operations. Input options (`-headers`, `-cookies`, fast-seek
 * `-ss`) are kept per input, separate from output options — ffmpeg is
 * position-sensitive about the two.
 *
 * @api
 */
final class CommandSpec
{
    /** @var list<array{source: string, options: list<string>}> */
    private array $inputs = [];

    /** @var list<string> */
    private array $probeSources = [];

    /** @var list<string> */
    private array $timelineSources = [];

    /** @var list<string> */
    private array $videoFilters = [];

    /** @var list<string> */
    private array $audioFilters = [];

    /** @var list<string> */
    private array $filterComplexSegments = [];

    /** @var list<string> */
    private array $outputOptions = [];

    /** @var list<string> */
    private array $maps = [];

    private ?string $videoOutput = null;

    private ?string $audioOutput = null;

    private ?string $subtitleOutput = null;

    private bool $videoSelectionExplicit = false;

    private bool $audioSelectionExplicit = false;

    private bool $videoFedByComplexGraph = false;

    private bool $audioFedByComplexGraph = false;

    /** @var list<string> */
    private array $seekOwners = [];

    /** @var list<string> */
    private array $primaryInputOptionOwners = [];

    /** @var list<string> */
    private array $artworkOwners = [];

    private OutputMode $outputMode = OutputMode::Normal;

    /** @var list<string> */
    private array $outputModeOwners = [];

    private bool $streamCopy = false;

    private bool $exclusiveVideoGraph = false;

    /** @var list<string> */
    private array $filterComplexOwners = [];

    private ?OutputLayout $outputLayout = null;

    private ?Duration $timelineFrom = null;

    private ?Duration $timelineTo = null;

    private bool $progressIndeterminate = false;

    /**
     * Register an input source and return its zero-based index (for later
     * `-map` references). Options are emitted BEFORE the `-i` (input-level:
     * `-headers`, `-cookies`, fast-seek `-ss`).
     *
     * @param list<string> $options
     */
    public function addInput(string $source, array $options = [], bool $probe = true, bool $timeline = true): int
    {
        if ($source === '') {
            throw new \InvalidArgumentException('Input source cannot be empty');
        }

        $this->inputs[] = ['source' => $source, 'options' => $options];

        if ($probe) {
            $this->probeSources[] = $source;
        }

        if ($timeline) {
            $this->timelineSources[] = $source;
        }

        return count($this->inputs) - 1;
    }

    /**
     * Append input-level options to the PRIMARY input (index 0) — e.g. the
     * fast-seek `-ss` {@see \Rasuvaeff\MediaConverter\Operation\Trim} emits
     * before `-i`. The primary input must already be registered;
     * {@see Pipeline} always registers it before operations apply. A concat
     * pipeline rejects any owner here — the options would silently apply to
     * the first segment only.
     */
    public function addPrimaryInputOptions(string $owner, string ...$options): void
    {
        if ($owner === '') {
            throw new \InvalidArgumentException('Primary input option owner cannot be empty');
        }

        if ($this->inputs === []) {
            throw new \LogicException('CommandSpec has no primary input');
        }

        $this->primaryInputOptionOwners[] = $owner;

        foreach ($options as $option) {
            $this->inputs[0]['options'][] = $option;
        }
    }

    /**
     * Claim the single cover-art slot. A second
     * {@see \Rasuvaeff\MediaConverter\Operation\AddArtwork} would collide on
     * the same `-disposition:v:N` output stream — {@see Pipeline} rejects a
     * second claim.
     */
    public function claimArtwork(string $owner): void
    {
        if ($owner === '') {
            throw new \InvalidArgumentException('Artwork owner cannot be empty');
        }

        $this->artworkOwners[] = $owner;
    }

    public function addVideoFilter(string $node): void
    {
        if ($node === '') {
            throw new \InvalidArgumentException('Video filter node cannot be empty');
        }

        $this->videoFilters[] = $node;
    }

    public function addAudioFilter(string $node): void
    {
        if ($node === '') {
            throw new \InvalidArgumentException('Audio filter node cannot be empty');
        }

        $this->audioFilters[] = $node;
    }

    /**
     * Append a `-filter_complex` graph segment (e.g. `[1:v]format=rgba[wm]`
     * or `[0:v][wm]overlay=x:y[out]`) — for multi-input compositions like
     * {@see \Rasuvaeff\MediaConverter\Operation\Watermark} where a plain
     * `-vf` chain cannot reference a second input. Segments are joined with
     * `;` (a filter_complex graph, not a single chain) preserving insertion
     * order. ffmpeg rejects `-vf`/`-af` together with `-filter_complex` on
     * the same stream — {@see Pipeline} rejects combining this with a plain
     * video filter operation before the subprocess starts.
     */
    public function addFilterComplexSegment(string $segment): void
    {
        if ($segment === '') {
            throw new \InvalidArgumentException('Filter-complex segment cannot be empty');
        }

        $this->filterComplexSegments[] = $segment;
    }

    public function claimFilterComplex(string $owner): void
    {
        if ($owner === '') {
            throw new \InvalidArgumentException('Filter-complex owner cannot be empty');
        }

        $this->filterComplexOwners[] = $owner;
    }

    public function markHlsOutput(?string $segmentPattern): void
    {
        $this->setOutputLayout(OutputLayout::hls($segmentPattern));
    }

    public function markDashOutput(): void
    {
        $this->setOutputLayout(OutputLayout::dash());
    }

    public function addOutputOption(string ...$args): void
    {
        foreach ($args as $arg) {
            $this->outputOptions[] = $arg;
        }
    }

    public function addMap(string $spec): void
    {
        $this->maps[] = $spec;
    }

    /**
     * Explicitly select the video output stream (an input selector like
     * `0:v:1`). Incompatible with an operation that produces the video stream
     * through the complex filter graph — {@see Pipeline} rejects that
     * combination in either add-order.
     */
    public function setVideoOutput(string $map): void
    {
        if ($map === '') {
            throw new \InvalidArgumentException('Video output map cannot be empty');
        }

        $this->videoOutput = $map;
        $this->videoSelectionExplicit = true;
    }

    /**
     * Explicitly select the audio output stream (an input selector like
     * `2:a`). A concat pipeline then drops the segments' own audio pads
     * (`a=0`) instead of leaving a dangling graph label.
     */
    public function setAudioOutput(string $map): void
    {
        if ($map === '') {
            throw new \InvalidArgumentException('Audio output map cannot be empty');
        }

        $this->audioOutput = $map;
        $this->audioSelectionExplicit = true;
    }

    /**
     * Route the video output from a complex-graph label (`[wmout]`, `[outv]`).
     * Overwrites a soft default but conflicts with an explicit selection —
     * {@see Pipeline} rejects the combination in either add-order.
     */
    public function setGraphVideoOutput(string $label): void
    {
        if ($label === '') {
            throw new \InvalidArgumentException('Video output map cannot be empty');
        }

        $this->videoOutput = $label;
        $this->videoFedByComplexGraph = true;
    }

    /** Route the audio output from a complex-graph label (`[outa]`). */
    public function setGraphAudioOutput(string $label): void
    {
        if ($label === '') {
            throw new \InvalidArgumentException('Audio output map cannot be empty');
        }

        $this->audioOutput = $label;
        $this->audioFedByComplexGraph = true;
    }

    public function setDefaultVideoOutput(string $map): void
    {
        if ($map === '') {
            throw new \InvalidArgumentException('Video output map cannot be empty');
        }

        // A soft default: fills the slot without claiming an explicit
        // selection, so a graph label may still take the slot over.
        if ($this->videoOutput === null) {
            $this->videoOutput = $map;
        }
    }

    public function setDefaultAudioOutput(string $map): void
    {
        if ($map === '') {
            throw new \InvalidArgumentException('Audio output map cannot be empty');
        }

        if ($this->audioOutput === null) {
            $this->audioOutput = $map;
        }
    }

    /**
     * Claim the output-side seek (`-ss`). Two partial claims (e.g. Trim and
     * Thumbnail) would silently last-write-wins in ffmpeg — {@see Pipeline}
     * rejects a second claim.
     */
    public function claimOutputSeek(string $owner): void
    {
        if ($owner === '') {
            throw new \InvalidArgumentException('Output seek owner cannot be empty');
        }

        $this->seekOwners[] = $owner;
    }

    public function setSubtitleOutput(string $map): void
    {
        if ($map === '') {
            throw new \InvalidArgumentException('Subtitle output map cannot be empty');
        }

        $this->subtitleOutput = $map;
    }

    public function requestAudioOnly(string $owner): void
    {
        $this->setOutputMode(OutputMode::AudioOnly, $owner);
    }

    public function requestVideoOnly(string $owner): void
    {
        $this->setOutputMode(OutputMode::VideoOnly, $owner);
    }

    public function requestSubtitleOnly(string $owner): void
    {
        $this->setOutputMode(OutputMode::SubtitleOnly, $owner);
    }

    public function trimTimeline(Duration $from, ?Duration $to): void
    {
        $this->timelineFrom = $from;
        $this->timelineTo = $to;
    }

    public function markProgressIndeterminate(): void
    {
        $this->progressIndeterminate = true;
    }

    /**
     * Request `-c copy` (stream-copy remux). Incompatible with any filter —
     * {@see Pipeline} rejects the combination before the subprocess starts.
     */
    public function requestStreamCopy(): void
    {
        $this->streamCopy = true;
    }

    /**
     * Mark that the last-added video filter is a complete, self-contained
     * graph (e.g. {@see \Rasuvaeff\MediaConverter\Operation\AnimatedPreview}'s
     * `split`/`palettegen`/`paletteuse` chain) that cannot be combined with
     * another video filter node — {@see Pipeline} rejects that combination
     * before the subprocess starts.
     */
    public function markExclusiveVideoGraph(): void
    {
        $this->exclusiveVideoGraph = true;
    }

    /**
     * @return list<array{source: string, options: list<string>}>
     */
    public function inputs(): array
    {
        return $this->inputs;
    }

    /** @return list<string> */
    public function inputSources(): array
    {
        return array_column($this->inputs, 'source');
    }

    /** @return list<string> */
    public function probeSources(): array
    {
        return $this->probeSources;
    }

    /** @return list<string> */
    public function timelineSources(): array
    {
        return $this->timelineSources;
    }

    /**
     * @return list<string>
     */
    public function videoFilters(): array
    {
        return $this->videoFilters;
    }

    /**
     * @return list<string>
     */
    public function audioFilters(): array
    {
        return $this->audioFilters;
    }

    /**
     * @return list<string>
     */
    public function filterComplexSegments(): array
    {
        return $this->filterComplexSegments;
    }

    /**
     * @return list<string>
     */
    public function outputOptions(): array
    {
        return $this->outputOptions;
    }

    /**
     * @return list<string>
     */
    public function maps(): array
    {
        return $this->maps;
    }

    public function videoOutput(): ?string
    {
        return $this->videoOutput;
    }

    public function audioOutput(): ?string
    {
        return $this->audioOutput;
    }

    public function subtitleOutput(): ?string
    {
        return $this->subtitleOutput;
    }

    public function videoSelectionExplicit(): bool
    {
        return $this->videoSelectionExplicit;
    }

    public function audioSelectionExplicit(): bool
    {
        return $this->audioSelectionExplicit;
    }

    public function videoFedByComplexGraph(): bool
    {
        return $this->videoFedByComplexGraph;
    }

    public function audioFedByComplexGraph(): bool
    {
        return $this->audioFedByComplexGraph;
    }

    /** @return list<string> */
    public function seekOwners(): array
    {
        return $this->seekOwners;
    }

    /** @return list<string> */
    public function primaryInputOptionOwners(): array
    {
        return $this->primaryInputOptionOwners;
    }

    /** @return list<string> */
    public function artworkOwners(): array
    {
        return $this->artworkOwners;
    }

    /** @internal */
    public function outputMode(): OutputMode
    {
        return $this->outputMode;
    }

    /** @internal */
    public function outputModeOwner(): ?string
    {
        return $this->outputModeOwners[0] ?? null;
    }

    /** @return list<string> */
    public function outputModeOwners(): array
    {
        return $this->outputModeOwners;
    }

    public function outputDuration(Duration $inputDuration): ?Duration
    {
        if ($this->progressIndeterminate) {
            return null;
        }

        if (!$this->timelineFrom instanceof Duration) {
            return $inputDuration;
        }

        if ($this->timelineTo instanceof Duration) {
            return $this->timelineTo->minus($this->timelineFrom);
        }

        return $inputDuration->minus($this->timelineFrom);
    }

    public function isStreamCopy(): bool
    {
        return $this->streamCopy;
    }

    public function hasFilters(): bool
    {
        return $this->videoFilters !== [] || $this->audioFilters !== [] || $this->filterComplexSegments !== [];
    }

    public function hasExclusiveVideoGraph(): bool
    {
        return $this->exclusiveVideoGraph;
    }

    /**
     * @return list<string>
     */
    public function filterComplexOwners(): array
    {
        return $this->filterComplexOwners;
    }

    public function outputLayout(): OutputLayout
    {
        return $this->outputLayout ?? OutputLayout::single();
    }

    private function setOutputLayout(OutputLayout $layout): void
    {
        // Part of the fail-fast contract: incompatible compositions surface
        // as ConversionFailed, even when detected during applyTo().
        if ($this->outputLayout instanceof OutputLayout) {
            throw new ConversionFailed(
                reason: ConversionFailureReason::IncompatibleOperations,
                exitCode: 0,
                stderr: 'Only one package output operation is allowed per pipeline',
            );
        }

        $this->outputLayout = $layout;
    }

    private function setOutputMode(OutputMode $mode, string $owner): void
    {
        if ($owner === '') {
            throw new \InvalidArgumentException('Output mode owner cannot be empty');
        }

        $this->outputMode = $mode;
        $this->outputModeOwners[] = $owner;
    }
}
