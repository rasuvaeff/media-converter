<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Internal;

use Rasuvaeff\Duration\Duration;

/**
 * Parses ffmpeg's `-progress pipe:1` machine-readable `key=value` stream into
 * {@see ProgressSample}s. Buffers partial lines across {@see feed()} calls —
 * output chunks from the subprocess do not align with report boundaries.
 *
 * Each report is a block of `key=value` lines terminated by a `progress=`
 * line (`continue` or `end`); either value ends the report and resets the
 * accumulator for the next one.
 *
 * A fresh instance must be used per subprocess attempt — a retried run must
 * not carry over a previous attempt's partial buffer.
 *
 * @internal
 */
final class ProgressReportParser
{
    private string $buffer = '';

    /** @var array<string, string> */
    private array $fields = [];

    /**
     * @return list<ProgressSample>
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $samples = [];

        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $newline), "\r");
            $this->buffer = substr($this->buffer, $newline + 1);

            if ($line === '') {
                continue;
            }

            $equals = strpos($line, '=');

            if ($equals === false) {
                continue;
            }

            $key = substr($line, 0, $equals);
            $this->fields[$key] = trim(substr($line, $equals + 1));

            if ($key === 'progress') {
                $samples[] = $this->buildSample();
                $this->fields = [];
            }
        }

        return $samples;
    }

    private function buildSample(): ProgressSample
    {
        // ffmpeg emits NEGATIVE out_time values in real reports: an INT64_MIN
        // sentinel before the first timestamp is muxed, and small negatives
        // for inputs with a negative start_time (e.g. MP4 audio priming).
        // Clamp instead of letting the non-negative Duration reject the report.
        $outTimeMicros = $this->intField('out_time_us') ?? ($this->intField('out_time_ms') ?? 0);
        $frame = $this->intField('frame');

        return new ProgressSample(
            // ffmpeg's historical out_time_ms name is misleading: its value is
            // microseconds, identical to out_time_us on versions emitting both.
            outTime: Duration::micros(max(0, $outTimeMicros)),
            frame: $frame !== null && $frame >= 0 ? $frame : null,
            fps: $this->floatField('fps'),
            speed: $this->speedField(),
        );
    }

    private function intField(string $key): ?int
    {
        $value = $this->fields[$key] ?? null;

        return $value !== null && preg_match('/^-?\d+\z/', $value) === 1 ? (int) $value : null;
    }

    private function floatField(string $key): ?float
    {
        $value = $this->fields[$key] ?? null;

        return $value !== null && is_numeric($value) ? (float) $value : null;
    }

    /**
     * `speed` is rendered as e.g. `"2.1x"` or `"N/A"` — strip the trailing
     * `x` before parsing.
     */
    private function speedField(): ?float
    {
        $value = $this->fields['speed'] ?? null;

        if ($value === null) {
            return null;
        }

        $numeric = rtrim($value, 'x');

        return is_numeric($numeric) ? (float) $numeric : null;
    }
}
