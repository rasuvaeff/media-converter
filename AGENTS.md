# AGENTS.md — media-converter

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/media-converter` is a general-purpose, type-safe wrapper over the
`ffmpeg`/`ffprobe` subprocess. Namespace `Rasuvaeff\MediaConverter`. Media
processing is composed from small **operations** (`Operation\*`) into a
`Pipeline`; the pipeline renders the exact ffmpeg argv, and `MediaConverter`
runs it through `symfony/process` with opt-in `rasuvaeff/retry`,
`rasuvaeff/bulkhead`, timeouts, progress reporting and ffprobe metadata. The
download use case (HLS/DASH → MP4) is one composition, not the package's
purpose.

Public API: `Pipeline`, `CommandSpec`, `FfmpegBinary`, `ConvertsMedia`,
`ProbesMedia`, `MediaConverter`, `FfprobeMediaInfo`, `CachedProbesMedia`,
`RunsProcess`,
`SymfonyProcessRunner`, `CancellationToken`, `ConversionCancelled`,
`ProcessOutcome`, `ConversionResult`, `MediaInfo`, `ConversionFailed`,
`ConversionFailureReason`, `Progress\{ProgressEvent, ConversionPhase}`,
  `Operation\{OperationInterface, Transcode, Remux, Trim, Scale, Crop, Rotate,
Pad, Fps, ExtractAudio, Thumbnail, SpriteSheet, AnimatedPreview, TextOverlay,
Position, Watermark, ReplaceAudio, NormalizeLoudness, BurnSubtitles,
ExtractSubtitles, SelectStreams, PackageHls, PackageDash, AddMetadata,
AddArtwork}`, `Preset\Presets`. Internal
  (`@internal`): `Internal\{ArgBuilder, FilterGraph, Timestamp,
  ProgressReportParser, ProgressSample,
  ProgressFraction, OutputLayout, OutputMode, OutputTransaction}`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Subprocess safety: never shell-interpolate.** Every path, URL and argv
   entry is a separate element (`symfony/process` array form). User input is
   never concatenated into a command string. Use the relevant `FfmpegBinary`
   pre-flight (`assertExecutable()`, `assertFfmpegExecutable()`, or
   `assertFfprobeExecutable()`) when paths come from config or user input.
4. **Preserve the public contract.** Update `README.md`, `README.ru.md`,
   `llms.txt`, and tests with any API change. The bilingual README is
   mandatory — any edit to `README.md` lands in `README.ru.md` in the same
   commit.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Path-repo deps (`rasuvaeff/*`) resolve from Packagist; after editing
`composer.json` run `composer update` (network required). Or with Make:
`make build`, `make cs-fix`, `make psalm`, `make test`, `make mutation`.

`composer test`/`composer build` run the `Unit` suite only — `tests/Integration/`
is explicitly excluded (`testo.php`'s `Unit` `FinderConfig` excludes it) because
it needs a REAL `ffmpeg`/`ffprobe`, absent from the bare `composer:2` image.
Run it where they're installed:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c \
  'apk add --no-cache ffmpeg ttf-dejavu && vendor/bin/testo --suite=Integration'
```

`ttf-dejavu` (or any fontconfig-discoverable font) is required — `TextOverlay`
without an explicit `fontFile` defaults to the "Sans" family, and a bare
`ffmpeg` apk install carries no fonts at all (`drawtext` then fails: "Cannot
find a valid font for the family Sans", verified). Without ffmpeg/ffprobe
installed, every Integration test still passes — each returns immediately
(0 assertions) rather than failing, so the suite is green-but-empty, not a
false negative.

## Invariants & gotchas

- **Operation model.** An operation implements `OperationInterface::applyTo(
  CommandSpec)` and contributes inputs / filter nodes / output options / maps.
  `Pipeline::toArgv()` applies every operation to a fresh `CommandSpec`,
  validates, and calls `ArgBuilder`. Adding an operation is a **minor** bump.
- **Stream slots carry ownership, not just a value.** `setVideoOutput`/
  `setAudioOutput` mark an EXPLICIT selection (`SelectStreams`,
  `ReplaceAudio`); `setGraphVideoOutput`/`setGraphAudioOutput` mark the slot
  as fed by the complex graph (`Watermark`, concat); `setDefault*Output` is a
  soft default that never claims. `Pipeline::validate()` rejects
  graph-fed + explicit-selection for video in EITHER add-order, and rejects a
  plain audio filter when audio is graph-fed (concat). Concat's graph string
  is built AFTER operations apply, so an explicit audio claim flips it to
  `a=0` (video-only pads) instead of leaving `[outa]` dangling. Similarly,
  `claimOutputSeek()` (Trim, Thumbnail) allows only one output-side `-ss`
  owner — `AnimatedPreview` deliberately does NOT claim (its own `-ss`/`-to`
  fully override a Trim's; documented harmless-but-redundant).
- **`Internal\Timestamp` uses `%.6F`, not `%.6f`.** The lowercase specifier
  is `LC_NUMERIC`-sensitive: under a comma-decimal locale (typical web app)
  it renders `-ss 1,5`, which ffmpeg rejects. Never "simplify" it back.
- **Untrusted subprocess output is sanitised before validating VOs.** ffmpeg
  really emits negative `out_time_us` (INT64_MIN sentinel before the first
  muxed timestamp; small negatives for negative-start_time inputs) and a
  corrupt container can report a negative `format.duration` — both are
  clamped at the parse boundary, because `Duration`/`ProgressEvent` throw on
  negatives and a bare `InvalidArgumentException` would break the
  `ConversionFailed` contract mid-conversion.
- **Filters are graph nodes, not a pre-joined string.** `CommandSpec` stores
  video/audio filter NODES; `ArgBuilder` renders them (`-vf`/`-af` for a single
  input today; a `-filter_complex` graph for multi-input overlays is the M6
  extension point). Never pre-join filters into one string in an operation.
- **Filters are NOT commutative.** `scale,crop` != `crop,scale`. `FilterGraph`
  and `ArgBuilder` preserve insertion order verbatim; the property test pins
  order-preservation (do not "test commutativity" — it is false).
- **Fail-fast validation.** `Pipeline` rejects incompatible compositions
  (stream-copy + filter/codec) with `ConversionFailed` /
  `ConversionFailureReason::IncompatibleOperations` BEFORE any subprocess.
- **One complex-video-graph owner.** Concat and `Watermark` claim ownership in
  `CommandSpec`; combining them or adding two watermarks is rejected before
  ffmpeg runs. Do not add a complex-graph operation without claiming ownership.
- **Input vs output options.** `CommandSpec::addInput($source, $options)` keeps
  input-level options (`-headers`, `-cookies`, fast-seek `-ss`) separate from
  output options — ffmpeg is position-sensitive. `Trim` uses OUTPUT-side
  `-ss`/`-to` (accurate seek) by default; `Trim(..., fastSeek: true)` emits
  the `-ss` via `CommandSpec::addPrimaryInputOptions()` (input-side, before
  `-i`) and the end bound as `-t to-from` — input seeking resets output
  timestamps, so `-to` would be wrong there. Fast-seek still claims the
  single output-seek slot (one seek owner per pipeline), and a concat
  pipeline rejects ANY primary-input-option owner: the option would silently
  apply to the first segment only (`Pipeline::validate()` checks
  `primaryInputOptionOwners()` against `concatSegments`).
- **`AddMetadata` keys are whitelisted.** `-metadata` values are plain argv
  (no filtergraph escaping needed — `=`, unicode, newlines all safe), but a
  typo'd KEY (`tilte`) would be silently written as a nonsense tag, so keys
  outside the whitelist throw in the constructor. Extending the whitelist is
  a deliberate API decision, not a bug fix.
- **`AddArtwork` states its target via `forAudio()`/`forVideo()`.** The
  attached-pic stream layout depends on the output container, and an
  operation cannot see the output path — so the caller declares it: audio
  target ⇒ cover is `v:0` + explicit `setAudioOutput('0:a')`; video target ⇒
  soft-default video/audio maps and cover is `v:1` (index holds under
  `Watermark` too: the graph-fed `[wmout]` is still output `v:0`). The cover
  stream is stream-COPIED (`-c:v:N copy`) — JPEG is already MJPEG, PNG stays
  PNG, both valid `attached_pic` codecs — which also lets it compose with
  `Remux`: the stream-copy guard in `Pipeline::validate()` explicitly allows
  a `-c:*` option whose VALUE is `copy`, and (pinned by test) still rejects a
  later re-encoding option after the copy pair — do not turn that `continue`
  into a `break`. With a video `Transcode` the artwork must be added AFTER it
  (ffmpeg applies the last matching codec option per stream). One artwork per
  pipeline (`claimArtwork()`); `ExtractAudio` + artwork is rejected — `-vn`
  discards the mapped cover (the terminal-mode validation counts raw
  `maps()` as a conflicting target for AudioOnly/VideoOnly/SubtitleOnly).
  `-id3v2_version 3` is MP3-only: M4A/FLAC muxers reject the option, hence
  the `id3v2` flag on `forAudio()`.
- **`CachedProbesMedia` keys are dot-separated.** PSR-16 reserves
  `{}()/\@:` in keys, so the prefix is `rasuvaeff.media-converter.probe.`
  (dots, not colons). The key hashes `(path, filesize, filemtime)` — a
  changed local file gets a new key automatically; a URL degrades to a
  path-only key (TTL governs). A cached value of the wrong type is a miss,
  never an error. `psr/simple-cache` is pinned to `^3.0` on purpose: v1/v2
  interfaces are untyped, and a typed test double fatals under them in the
  prefer-lowest CI job.
- **`ConversionFailureReason`** is a stable enum contract: new cases minor,
  removing/renaming major. Only `NonZeroExit->isPotentiallyTransient()` is
  true — retry policies match on it (plus stderr inspection in the engine).
- **`ProgressEvent::fraction()` is nullable.** A percentage is reported only
  when the total duration is known (from ffprobe). A stream-copy remux or live
  input yields INDETERMINATE samples (`fraction === null`) — never fabricate a
  percentage. Check `isDeterminate()` first.
- **DRM is never bypassed.** `FfprobeMediaInfo` reports successful encrypted
  probes through `MediaInfo::isEncrypted()` and maps non-zero decrypt
  diagnostics to `ConversionFailureReason::Drm`. With a `$prober`, every media
  input is probed once before ffmpeg and outside retry; `MediaConverter::run()`
  refuses encrypted results before ffmpeg starts. No prober means no DRM check.
- **Progress is opt-in and wired only when `$onProgress` is given.**
  `MediaConverter` then (a) splices `-progress pipe:1 -nostats` into the argv
  right after argv[0] — `-nostats` matters: it keeps ffmpeg's default
  per-second human stats OFF stderr, so the stderr tail stays clean for error
  classification — and (b) builds a FRESH `Internal\ProgressReportParser` per
  subprocess attempt inside the retried closure, so a retried run never
  carries over a previous attempt's partial buffer. The parser only reads
  `'out'` chunks — `-progress pipe:1` means stdout. Fraction comes from
  `Internal\ProgressFraction::compute(outTime, ?totalDuration)`: null (not
  0.0) when total is unknown/non-positive, else clamped to `[0.0, 1.0]`
  (`out_time` can exceed the probed total near the end of a run). The
  monotonicity/clamp property test targets THIS function directly, not the
  line parser.
- **`AnimatedPreview` is a self-contained filter graph, not a composable
  node.** Its `split[s0][s1];[s0]palettegen[p];[s1][p]paletteuse` palette
  technique is single-input/single-output (branches internally, one output),
  so it renders through the normal `-vf` chain — no `-filter_complex` needed
  (verified against a real ffmpeg build). It calls
  `CommandSpec::markExclusiveVideoGraph()`; `Pipeline::validate()` rejects the
  combination with ANY other video filter operation (`hasExclusiveVideoGraph()
  && count(videoFilters()) > 1`), checked post-hoc on the final `CommandSpec`
  so it catches either add-order. A separate `Trim` composes fine (it adds no
  filter) — `AnimatedPreview` has its own `$from`/`$to` for exactly this
  reason.
- **`drawtext` text needs a DIFFERENT escaping scheme from a path argument.**
  `FilterGraph::escapeArgument()` (backslash-escaping for an unquoted value,
  e.g. `fontfile=`) does NOT work for `text=` — verified against a real
  ffmpeg build. `TextOverlay` instead: (1) sets `expansion=none` on the
  filter so a literal `%` is never treated as the start of drawtext's
  `%{...}` syntax (with `expansion=normal`, `%`/`\%`/`%%` all fail to parse
  even quoted — "Stray % near ..."); (2) wraps `text=` in single quotes via
  `FilterGraph::quoteDrawtextValue()`, inside which `:`, `,`, `[`, `]` need NO
  escaping (verified) and only a literal `'` is escaped, by closing, escaping,
  and reopening the quote (`'\''`). `x`/`y` are ffmpeg eval-expressions built
  from `w`/`h`/`text_w`/`text_h`, evaluated regardless of `expansion=none`
  (verified) — not user text, so no quoting needed there.
- **`SpriteSheet`'s sampling rate is an exact rational, not a decimal.**
  `fps=1_000_000/$interval->toMicros()`, mirroring `ProgressFraction`'s
  microsecond-precision rule — no interval is lossy to ffmpeg's `fps` filter
  regardless of how many microseconds it holds. `tile=colsxrows` (verified:
  ffmpeg's `layout` option is `WIDTHxHEIGHT` in tile-count terms, i.e.
  columns×rows, NOT rows×cols) — `SpriteSheet`'s constructor takes
  `(rows, cols, ...)` (reads naturally) but emits `tile=%d(cols)x%d(rows)`.
  `-frames:v 1` caps the output to exactly one sheet — without it `tile`
  starts a second sheet once the first grid fills.
- **`Watermark` uses `CommandSpec::addFilterComplexSegment()` /
  `-filter_complex`, not `-vf`.** Overlaying a second input (the watermark
  image) needs a graph the plain `-vf` chain can't express (a second `-i`
  referenced by pad label). Verified against a real ffmpeg build: `-vf` and
  `-filter_complex` cannot target the same output stream ("Simple and
  complex filtering cannot be used together for the same stream"), so
  `Pipeline::validate()` rejects `filterComplexSegments() !== [] &&
  videoFilters() !== []` — a plain video filter operation is incompatible
  with `Watermark`, but a plain AUDIO filter (e.g. `NormalizeLoudness`)
  composes fine (also verified — the constraint is video-stream-specific).
  `CommandSpec::hasFilters()` counts `filterComplexSegments` too, so
  stream-copy (`Remux`) + `Watermark` is rejected by the existing check. Only
  ONE `Watermark` per pipeline is supported — a second would collide on the
  same fixed pad labels (`[wm]`/`[wmout]`) and is rejected through complex
  graph ownership.
- **`PackageHls` always sets `-hls_playlist_type vod`.** Verified against a
  real ffmpeg build: without it, only the last `-hls_list_size` (default 5)
  segments stay in the playlist — a live-streaming rolling window, wrong for
  VOD packaging. With `vod`, every segment is kept regardless of count and
  the playlist gets `#EXT-X-ENDLIST`. `PackageDash` needs no equivalent flag
  — ffmpeg's own `-window_size` default (`0` = unlimited) is already
  VOD-correct. Both compose with `Remux` (verified: stream-copy + HLS
  segmenting works) or `Transcode` — they only touch output-format options,
  orthogonal to codec choice.
- **Package publication is manifest-last.** Default HLS/DASH sidecars have
  generation-specific names and a hidden inventory removes only obsolete
  managed sidecars. Custom HLS patterns keep consumer-selected names and are
  never trusted as inventory cleanup targets. The obsolete-artifact sweep is
  LAYOUT-SCOPED: it runs only for managed generations and only reaps the
  layout's own extension (HLS → `.ts`, DASH → `.m4s`) — the stem-glob would
  otherwise let `video.mp4` (or `video.mpd`) delete a same-directory managed
  `video.m3u8` destination's LIVE segments, which the per-basename lock
  cannot prevent.
- **DRM heuristic masks the filename echo.** `-show_format` echoes the
  probed path back as `format.filename` in stdout; `FfprobeMediaInfo` masks
  that field before the `/decrypt/i` scan so `/videos/decrypted/movie.mp4`
  is not refused as DRM. Stream tags and stderr keep matching (pinned by
  tests). Cover-art streams (`disposition.attached_pic=1`) are skipped when
  picking the video stream — an MP3 with embedded art has no video track.
- **`Pipeline::concat()` is a separate named constructor, not an
  `OperationInterface`.** Every other operation DECORATES one pre-existing
  primary source (`toArgv()` calls `addInput($this->source)` before any
  operation runs); concat composes several segments INTO the source itself,
  which an `OperationInterface::applyTo(CommandSpec)` can't express without
  either a dangling extra `-i` (the original primary source never used) or
  `CommandSpec` growing a `removeInput()` for one caller's benefit. Instead
  `Pipeline::concat(list<string> $segments, bool $hasAudio)` stores the
  segments, registers the inputs BEFORE the `->add()`-ed operations run (so
  `ReplaceAudio`'s extra `-i` gets the next index), and builds
  `[0:v][0:a][1:v][1:a]…concat=n=<count>:v=1:a=<0|1>[outv][outa]` AFTER they
  ran — the graph reacts to an explicit audio claim by dropping the segments'
  audio pads (`a=0`, see the stream-slot-ownership invariant). A codec-only
  operation like `Transcode` still composes; a plain video filter is rejected
  by the EXISTING `filterComplexSegments() !== [] && videoFilters() !== []`
  check, a plain audio filter by the graph-fed-audio check. This is the
  `concat` FILTER (re-encodes, verified against a real ffmpeg build with
  both audio+video and video-only segments), NOT the lossless `concat`
  DEMUXER (`-f concat -i list.txt -c copy`) — the demuxer needs a real temp
  list file with its own creation/cleanup lifecycle (`toArgv()` is called
  more than once in normal usage — inspection, then `MediaConverter::run()`
  — and is expected to have no filesystem side effects), which is real
  surgery deliberately deferred; see the plan's M7 entry. `Pipeline::source()`
  returns `segments[0]` for compatibility; `sources()` returns every segment.
  `MediaConverter` probes and sizes every segment, sums known durations, and
  keeps progress indeterminate if any duration is unknown. Concat and
  `Watermark` both claim the complex graph and cannot be combined.
- **`.m3u8`/`.mpd` playlists need no Concat/packaging support at all** —
  verified against a real ffmpeg build: `ffmpeg -i playlist.m3u8 -c copy
  out.mp4` reads the playlist and its segments natively. The HLS/DASH-
  heritage remux case is `Pipeline::from('playlist.m3u8')->add(new Remux())`;
  `Concat` is only for loose segment files with no playlist/manifest.
- **`Preset\Presets` is where download-specific plumbing lives, not the core
  operations.** `hlsToMp4()` adds `-bsf:a aac_adtstoasc` via a small
  anonymous `OperationInterface` defined PRIVATELY inside `Presets` — it is
  NOT a public `Operation\*` class, on purpose: the plan explicitly scopes
  bitstream-filter-for-HLS-compat as "download compatibility lives in
  Preset, not core." Verified against a real ffmpeg build that the filter is
  harmless when the audio is already in ASC form (no error, no-op) — safe to
  apply unconditionally rather than trying to detect when it's needed.
  `dashToMp4()` skips it (DASH's fMP4 segments are already ASC). If a second
  preset ever needs a bitstream filter for a DIFFERENT purpose, promote the
  anonymous class to a real `Operation\BitstreamFilter(string $stream,
  string $filter)` at that point — don't add it speculatively now for one
  caller.
- **A literal single quote (`'`) cannot be embedded in ANY ffmpeg filter
  argument via `-vf`/`-filter_complex` string syntax.** Verified against a
  real ffmpeg build (6.1.1): unescaped, backslash-escaped (`\'`), and
  quote-wrapped-and-reopened (`'\''`, the working `drawtext text=` trick) are
  ALL silently dropped by the filtergraph parser when embedding a quote in a
  path value (`subtitles=filename=`, `drawtext=fontfile=`) — every
  combination tried failed the same way (the file basename lost its
  apostrophe). `TextOverlay.$fontFile` and `BurnSubtitles.$srtOrAss` both
  reject a value containing `'` in the CONSTRUCTOR (fail fast, matches
  "validate in constructor") rather than let `FilterGraph::escapeArgument()`
  silently produce a broken command. `escapeArgument()`'s own `\'` replacement
  is kept as a documented no-op/best-effort — do not treat it as working.
- **`FilterGraph::escapeArgument()`'s colon is DOUBLE-escaped (`\\:`), not
  single (`\:`).** Also verified against a real ffmpeg build, via the
  `subtitles` filter specifically: a value passes through (at least) two
  parse levels — the filtergraph string first splits the argument list on
  unescaped `:` (consuming one backslash layer), then `subtitles` re-splits
  ITS OWN value on `:` again to support `filename=…:original_size=…`-style
  multiple sub-options. A single-escaped colon survives the first split but
  is bare by the second, misreading `subtitles=filename=C:/x.srt` as
  `original_size=/x.srt` ("Unable to parse option value … as image size").
  Double-escaping was also verified NOT to break `drawtext=fontfile=`, which
  does not re-split on `:` — safe for every current caller. If a THIRD
  colon-splitting filter is ever wrapped, re-verify against real ffmpeg
  rather than assume the fix generalises.
- **testo/infection mutant-to-test mapping follows `#[Covers]`, not actual
  runtime coverage.** A test in `FooTest` (`#[Covers(Foo::class)]`) that
  happens to execute `Bar.php` at runtime (e.g. an integration-style
  assertion through `Pipeline::toArgv()`) will NOT be considered by infection
  when mutating `Bar.php` — only tests in a class covering `Bar` are.
  Discovered chasing `Pipeline.php`'s exclusive-video-graph mutants: tests
  living in `AnimatedPreviewTest` (`#[Covers(AnimatedPreview::class)]`) that
  exercised `Pipeline::validate()` left those `Pipeline.php` mutants escaped;
  moving the same assertions into `PipelineTest` (`#[Covers(Pipeline::class)]`)
  killed them. Put cross-cutting Pipeline-behavior tests in `PipelineTest`,
  not in the operation's own test class — same root cause (coverage bucketing
  is attribute-driven, not execution-driven) as the monorepo-wide
  `#[CoversNothing]`-gives-zero-mutants gotcha.
- **Engine resilience: retry wraps bulkhead.** `MediaConverter` runs
  `retry(bulkhead(execute))` so a concurrency slot is freed during backoff.
  The engine is authoritative about WHAT retries — it applies
  `->retryIf(isTransientFailure)->stopIf(not transient)` to the injected
  `Retry` (whose default retries all exceptions), so only a transient failure
  (`NonZeroExit` + stderr ~ HTTP 5xx / connection reset) is ever repeated. A
  `BulkheadFullException` propagates as itself — never wrap it in
  `ConversionFailed`. Retry exhaustion is unwrapped back to `ConversionFailed`
  with `RetryExhausted` chained as the previous exception.
- **Output is transactional.** `Internal\OutputTransaction` stages the main
  output and HLS/DASH sidecars beside the destination. Failure removes staging
  and preserves existing files; success commits all artifacts. Result byte
  counts cover the entire artifact list, not only the manifest.
- **`RunsProcess` keeps a STDERR-only tail** and forwards chunks with an
  `'out'`/`'err'` discriminator — do not pollute the tail with stdout, so
  transient-detection stays reliable when progress is routed to stdout.
- **PHP 8.3 syntax.** The matrix includes 8.3, so `new Foo()->bar()` (no
  parens) is a parse error — write `(new Foo())->bar()`.
- **CI runs a Windows job too** (`build.yml`, single PHP version, Unit suite
  only). `OutputTransaction::isAbsolutePath()` exists because `dirname()`
  returns a native-separator path — a bare `str_starts_with($p, '/')` check
  misclassifies every Windows absolute path (`C:\...`) as relative, corrupting
  the custom-HLS-target directory. Don't revert it back to the POSIX-only
  check. Two tests (`OutputTransactionTest::managedSweepDoesNotMatchTrailingNewlineInExtension`,
  `SymfonyProcessRunnerTest::killsATermIgnoringProcessWithoutAGracePeriodWhenTheCallbackThrows`)
  early-return on `PHP_OS_FAMILY === 'Windows'` — the first needs a filename
  ending in `\n`, which Win32 file APIs reject; the second needs a SIGTERM the
  child ignores, and Windows process termination (`taskkill`/`TerminateProcess`)
  has no equivalent "ignorable" signal. Subprocess-behavior tests spawn
  `['php', '-r', '...']`, never `/bin/sh` — the latter doesn't exist on
  `windows-latest`.
- **`ProbesMedia::probe()`'s `$source` param is plain `string`, NOT
  `@param non-empty-string`.** Psalm treats an interface's docblock param type
  as binding on implementations, so a `non-empty-string` contract would make
  `FfprobeMediaInfo`'s own `$source === ''` runtime guard a provably-dead
  branch (`TypeDoesNotContainType`) AND force every caller passing a plain
  `string` (e.g. `Pipeline::source()`) into an unverifiable coercion. Guard
  emptiness at runtime instead, matching `Pipeline::from()` — do not add
  `non-empty-string` back.
- **Rector vs psalm on `FfprobeMediaInfo`'s `@var mixed $value` annotations**
  (in `stringField()`/`positiveIntField()`/`floatField()`, extracting a field
  from ffprobe's decoded JSON): rector proposes removing them from two of the
  three (leaves `stringField()` alone, inconsistently). Removing them
  reintroduces psalm's `MixedAssignment` error. This is the documented
  repo-wide rector/psalm conflict — keep the annotations, skip that specific
  rector hunk (never run bare `rector:fix`).
- **Accepted equivalent mutants (`FfprobeMediaInfo`/`ProgressReportParser`/
  `MediaConverter`/`ConversionResult`/`SymfonyProcessRunner`).** These infection mutants are covered but deliberately
  left unkilled — do not chase them with more tests:
  - `FfprobeMediaInfo::probe()` — swapping `$stdout . $outcome->stderrTail`
    to `$outcome->stderrTail . $stdout` in the `/decrypt/i` check (`Concat`).
    The `encrypted:` check only runs after `json_decode($stdout, ...)`
    succeeds, so `$stdout` is always well-formed, syntactically-closed JSON —
    it can never end in a bare unquoted letter sequence, only in `}`/`]`/`"`.
    No string can make "decrypt" span the stdout/stderr boundary in one
    concatenation order but not the other; the mutant is provably equivalent
    given this constraint.
  - `positiveIntField()`/`intField()` (`ProgressReportParser`) — removing the
    leading `^` from `/^\d+$/` / `/^-?\d+$/` (`PregMatchRemoveCaret`) is
    killed by trailing-garbage tests (`"45x00"`), but a pure
    leading-garbage-only test (`"x4500"`) does NOT kill it: PHP's `(int)`
    cast reads from the string start and stops at the first non-digit, so
    `(int) "x4500" === 0` regardless of the caret. Always pair leading- and
    trailing-garbage cases; leading-garbage alone is masked by the cast.
  - `floatField()` — `(float) $value` → `$value` for an already-`int`/`float`
    `$value` (`CastFloat`). Under `strict_types=1` an `int` return value is
    still silently widened to `float` by the declared `?float` return type
    (strict_types does not gate return-type coercion), so the cast removal
    produces an identical observable result for the int branch.
  - `ProgressReportParser::feed()` — `rtrim(substr(...), "\r")` → bare
    `substr(...)` (`UnwrapRtrim`). The trimmed `\r` would otherwise land at
    the end of the still-buffered field value, but `trim()` inside the
    `feed()`/field-assignment path downstream absorbs it before it can affect
    any parsed field — no observable difference.
  - `MediaConverter::fileSize()` — the `!is_file($path) ? return 0` early
    return (`ReturnRemoval`) and the `$size === false ? 0 : $size` ternary's
    `0` literal (`Decrement`/`IncrementInteger`) only diverge from the
    covering tests' fixed temp-file fixtures on filesystem races or
    permission errors that the test suite cannot deterministically trigger;
    accepted as environment-dependent, not test-gap.
  - `ConversionResult::outputMebibytes()` — `(float) $this->outputBytes` →
    bare `$this->outputBytes` (`CastFloat`). Same reasoning as
    `floatField()` above: the declared `float` return type silently widens
    an `int` under `strict_types=1` regardless of the explicit cast.
  - `OutputTransaction` (18 mutants, grouped) — (a) the whole
    `readPreviousArtifacts()` inventory-read path (`return []` fall-throughs,
    reduce/filter unwraps, dropping `$oldArtifacts` from the sweep
    candidates): the directory glob in `removeObsoleteArtifacts()` is a
    strict superset of the inventory on every reachable filesystem state, so
    inventory-read mutants change nothing observable — the inventory's real
    consumer is future orphan-cleanup semantics, kept by design; (b)
    identity rewrites (`array_values` on an already-list argv / on a
    filter that only ever drops the LAST key; `array_unique`/spread over
    idempotent unlinks; `array_splice(…, -1, -1)` ≡ length 0); (c)
    root/permission-dependent paths (`/inventory.json` temp file, backup
    path variants used consistently for copy+restore, `mkdir` mode 447/449
    vs 0o700) — tests run as root in the `composer:2` container where these
    are indistinguishable; (d) `rtrim(dirname($pattern), '/')` differs only
    for a segment pattern at the filesystem root — not portably testable.
  - `CachedProbesMedia::cacheKey()` — the two `FalseValue` mutants flipping
    the `is_file(...) ? ... : false` sentinels to `true` (missing-file
    branch). The sentinel only feeds the `=== false ? '-' : ...` formatting,
    so for a missing file the fingerprint is a constant either way ('-' vs
    '1') and the key stays stable-per-path — no observable difference exists;
    any constant sentinel is equivalent. The MEANINGFUL mutants (ternary
    swaps that freeze size/mtime for existing files) ARE killed, by the
    changed-size and touched-mtime tests.
  - `SymfonyProcessRunner::run()`'s `catch (ProcessTimedOutException)`
    branch — `$process->getExitCode() ?? self::TIMEOUT_EXIT_CODE` → the
    operands swapped (`Coalesce`). Since `TIMEOUT_EXIT_CODE` (`124`) is a
    non-null class constant, the swapped form ALWAYS evaluates to `124`
    regardless of `getExitCode()`, differing from the original only if
    `getExitCode()` returns non-null after a caught timeout — a genuine
    process-exited-right-as-it-was-killed race the test suite cannot
    deterministically trigger (verified: `killsAndFlagsAProcessThatExceeds
    TheWallClockTimeout`/`...IdleTimeout` both reliably observe a null exit
    code on this host and in CI); accepted as environment/race-dependent.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types, named arguments, trailing commas in multi-line constructs.
- `examples/` is part of the public contract: keep scripts runnable (they
  print the composed argv, no ffmpeg needed) and update `examples/README.md`
  when usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` references a 40-char commit
  SHA with a `# vN` comment; `permissions: { contents: read }`,
  `persist-credentials: false` on every checkout. Verify with
  `zizmor --persona=auditor .github/`. `mbstring` is required in every job
  (property-testing).

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit),
  `llms.txt`, and `examples/` if usage changed; update `CHANGELOG.md` when
  releasing.
- Re-run `composer build`; if the change affects the public API or release
  process, also run `make release-check`. Paste the output.
- If the change touches an existing operation's argv shape or adds a new one,
  also run the `Integration` suite (real ffmpeg — see Commands) and update
  `tests/Integration/RealFfmpegIntegrationTest.php` to cover it.
