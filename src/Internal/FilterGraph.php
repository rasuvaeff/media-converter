<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Internal;

/**
 * Assembles filter-node lists into an ffmpeg filter chain and owns the
 * escaping of values embedded in filter arguments.
 *
 * Node ordering is significant and preserved verbatim: `scale,crop` is NOT
 * the same graph as `crop,scale` (crop-then-scale scales the cropped region;
 * scale-then-crop crops the scaled image), so this never reorders nodes.
 *
 * @internal
 */
final class FilterGraph
{
    /**
     * Join filter nodes into a single chain (comma-separated), preserving
     * insertion order.
     *
     * @param list<string> $nodes
     */
    public static function chain(array $nodes): string
    {
        return implode(',', $nodes);
    }

    /**
     * Escape a value used inside a filter argument (e.g. a subtitle or font
     * path in `subtitles=filename=…` / `drawtext=fontfile=…`).
     *
     * The colon is DOUBLE-escaped (`\\:`, not `\:`) — verified against a real
     * ffmpeg build. A value passes through (at least) two parsing levels: the
     * filtergraph string first splits the argument list on unescaped `:`,
     * consuming ONE layer of backslash; some filters (`subtitles`) then split
     * their OWN value on `:` again (`filename=…:original_size=…`) to support
     * multiple sub-options in one filter spec. A single-escaped colon
     * (`\:`) survives the first split but is bare by the second, so a colon
     * in a `subtitles=filename=` path gets misread as a sub-option separator
     * ("Unable to parse option value … as image size"). Double-escaping
     * leaves one literal backslash for the second level to consume. This was
     * also verified NOT to break `drawtext=fontfile=`, which does not
     * re-split on `:` — double-escaping is safe for every current caller.
     *
     * A literal single quote CANNOT be embedded via this function — verified
     * against a real ffmpeg build that an unescaped, backslash-escaped, or
     * quote-wrapped-and-reopened (`'\''`) single quote is silently dropped by
     * the filtergraph parser in every combination tried, both alone and
     * combined with a colon. Callers must reject a value containing `'` in
     * their own constructor rather than relying on this function.
     */
    public static function escapeArgument(string $value): string
    {
        // First the backslash (so later-added backslashes are not re-escaped),
        // then the argument-level and graph-level metacharacters.
        $escaped = str_replace('\\', '\\\\', $value);

        return str_replace(
            ["'", ':', '[', ']', ',', ';'],
            ["\\'", '\\\\:', '\\[', '\\]', '\\,', '\\;'],
            $escaped,
        );
    }

    /**
     * Quote a `drawtext` `text=` value. This is a DIFFERENT escaping scheme
     * from {@see escapeArgument}: drawtext text is free-form user text, not a
     * path, so it is wrapped in single quotes — inside which ffmpeg's
     * filtergraph parser takes everything literally (`:`, `,`, `[`, `]`, `%`
     * need no escaping) except the quote character itself, which must be
     * closed, escaped, and reopened (`'\''`). {@see \Rasuvaeff\MediaConverter\Operation\TextOverlay}
     * additionally sets `expansion=none` on the filter so a literal `%` is
     * never treated as the start of drawtext's `%{...}` expansion syntax —
     * verified against a real ffmpeg build: without `expansion=none`, `%`
     * (escaped or not) inside a quoted value still fails to parse
     * ("Stray % near ...").
     */
    public static function quoteDrawtextValue(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
