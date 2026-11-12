# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- `FfprobeMediaInfo` takes an optional `inputOptions` list (fourth constructor argument), placed right before the source — e.g. `['-protocol_whitelist', 'file']`, so an application that restricts protocols for conversion through `Pipeline::from(..., inputOptions:)` can restrict the probe of the same untrusted file too. The default argv is unchanged (#15).
- `composer mutation` now runs Infection with `--with-uncovered`, so code the tests execute without naming it in `#[Covers]` is mutated and counted (overall MSI was 91% when measured this way, now 97% against `minMsi` 95). Added tests for the empty-input guards of `CommandSpec`, the failure and rollback paths of `OutputTransaction` (missing directory, unopenable lock, failed move, backups, relative and Windows-style HLS segment directories, non-array inventories), the cache key of a non-local source, cancellation while the process is writing, stderr being ignored by the progress forwarder, repeated probe sources, the artwork codec order check and blank progress lines. `infection.json5` gained the template's signature-default ignore regex, `testFrameworkOptions` (`--suite=Unit`) and disabled `PublicVisibility`/`ProtectedVisibility`. Behaviour-neutral `src/` changes: `Presets`, `AddArtwork::forAudio()`, `Pipeline::concat()` and `CommandSpec::addInput()` list their parameters one per line so the ignore regex matches their defaults, and `FilterGraph::escapeArgument()` passes its `str_replace()` arrays on one line (a multi-line array literal has no opcode under pcov).

## 1.1.1 — 2026-07-25

- Reject trailing newlines in validated values: anchor regexes with `\z` instead
  of `$` in `OutputTransaction::isManagedArtifact()` (managed-artifact reap
  decision), `TextOverlay::VALID_COLOR_PATTERN` (FFmpeg color token), plus
  `ProgressReportParser::intField()` and `FfprobeMediaInfo::positiveIntField()`
  (the last two are hygiene only — `trim()` / `json_decode()` already strip the
  newline before the regex runs).

## 1.1.0 — 2026-07-25

- Ship an AI agent skill (`resources/skills/rasuvaeff-media-converter/SKILL.md` +
  `extra.skills` in composer.json): projects using the `llm/skills` Composer
  plugin get the skill synced into `.agents/skills/` automatically on install.
- Bump `rasuvaeff/property-testing` dev constraint to `^2.6`.

## 1.0.0 — 2026-07-19

- Initial release: a type-safe, composable wrapper over the `ffmpeg`/`ffprobe`
  subprocess. Media processing is built from small `Operation\*` nodes into a
  `Pipeline` that renders the exact ffmpeg argv (no shell strings); the
  `MediaConverter` engine runs it through `symfony/process` with opt-in
  `rasuvaeff/retry`/`rasuvaeff/bulkhead`, timeouts, cancellation, progress
  reporting, transactional output staging and ffprobe metadata (`ProbesMedia`,
  `CachedProbesMedia`). Ships transcode/remux/trim/scale/crop/overlay/
  watermark/subtitle/loudness/thumbnail/sprite/preview/concat/HLS-DASH
  packaging operations and ready-made `Preset\Presets`.
