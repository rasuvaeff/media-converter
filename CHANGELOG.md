# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
