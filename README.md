# rasuvaeff/media-converter

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/media-converter/v)](https://packagist.org/packages/rasuvaeff/media-converter)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/media-converter/downloads)](https://packagist.org/packages/rasuvaeff/media-converter)
[![Build](https://github.com/rasuvaeff/media-converter/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/media-converter/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/media-converter/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/media-converter/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/media-converter/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/media-converter/php)](https://packagist.org/packages/rasuvaeff/media-converter)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[Русская версия](README.ru.md)

A general-purpose, type-safe PHP wrapper over the `ffmpeg` / `ffprobe`
subprocess. Compose media processing from small **operations** — transcode,
trim, scale, crop, extract audio, remux — into a `Pipeline` that renders the
exact ffmpeg command. No shell strings; every path is a separate argv entry.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

## Requirements

- PHP 8.3+
- `ffmpeg` and `ffprobe` binaries installed and executable (to run the built
  commands — composing and inspecting a pipeline needs no binary)
- Runtime dependencies: `symfony/process`, `rasuvaeff/retry`,
  `rasuvaeff/bulkhead`, `rasuvaeff/duration`
- No PHP extensions are required to **use** this package. `ext-bcmath` /
  `ext-intl` appear in `config.platform` only so `composer install` succeeds
  on hosts without them — they are required by `roave/backward-compatibility-check`
  (a dev-tool), not by any runtime code. Install them only if you develop the
  package and want to run `composer bc-check`.
- `CachedProbesMedia` is optional and needs a `psr/simple-cache` implementation
  — the package only *suggests* `psr/simple-cache`, so add it (plus a concrete
  cache such as `symfony/cache`) when you use that decorator.

## Installation

```bash
composer require rasuvaeff/media-converter
```

## Status

The full v1 surface is in place. At a glance:

| Area | What's included |
|---|---|
| Composition | `Pipeline` (immutable), `CommandSpec`, `toArgv()` for building/inspecting without running |
| Transform ops | `Transcode`, `Remux`, `Trim`, `Scale`, `Crop`, `Rotate`, `Pad`, `Fps`, `ExtractAudio` |
| Frame / preview | `Thumbnail`, `SpriteSheet`, `AnimatedPreview`, `TextOverlay` |
| Overlay / audio | `Watermark`, `ReplaceAudio`, `NormalizeLoudness`, `BurnSubtitles`, `ExtractSubtitles`, `SelectStreams` |
| Metadata | `AddMetadata`, `AddArtwork` |
| Packaging | `PackageHls`, `PackageDash`, `Pipeline::concat()` |
| Presets | `Preset\Presets` — ready-made pipelines (HLS/DASH → MP4, WebM, MP3/AAC, thumbnails, social clips) |
| Engine | `MediaConverter` — `symfony/process` execution, opt-in `retry` + `bulkhead`, wall-clock/idle timeouts, transactional staging, cooperative cancellation |
| Probing | `FfprobeMediaInfo`, `CachedProbesMedia` (PSR-16), DRM refusal, progress reporting |

## Usage

A pipeline starts from a source and adds operations; each operation
contributes to the command being assembled.

```php
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\{Crop, Scale, Transcode, Trim};
use Rasuvaeff\MediaConverter\Pipeline;

$binary = FfmpegBinary::default();

$pipeline = Pipeline::from('input.mov')
    ->add(new Trim(Duration::seconds(30), Duration::seconds(90)))
    ->add(new Scale(height: 720))
    ->add(new Crop(720, 720, 280, 0))
    ->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac', videoBitrateKbps: 2_500));

$argv = $pipeline->toArgv($binary, 'clip.mp4');
// ['/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y', '-i', 'input.mov',
//  '-ss', '30', '-to', '90', '-vf', 'scale=-2:720,crop=720:720:280:0',
//  '-c:v', 'libx264', '-c:a', 'aac', '-b:v', '2500k', 'clip.mp4']
```

`Pipeline` is immutable — `add()` returns a new pipeline.

For a ready-to-run example with probing, lifecycle phases, progress and typed
failure handling, use `php examples/convert.php input.mp4 out.mp4`.

### Running a pipeline

`MediaConverter` executes a pipeline. It needs a `RunsProcess` (the subprocess
seam; the package ships `SymfonyProcessRunner`) and optionally a `Bulkhead` (cap
concurrent ffmpeg per worker) and a `Retry` (retry transient upstream
failures — a non-zero exit whose stderr looks like an HTTP 5xx / connection
reset; every deterministic failure is not retried):

```php
use Rasuvaeff\MediaConverter\MediaConverter;
use Rasuvaeff\MediaConverter\SymfonyProcessRunner;
use Rasuvaeff\Retry\Retry;

$converter = new MediaConverter(
    binary: FfmpegBinary::default(),
    runner: new SymfonyProcessRunner(),
    retry: Retry::exponential(maxAttempts: 3),   // optional
);

$result = $converter->run($pipeline, 'clip.mp4'); // ConversionResult, or throws ConversionFailed
```

Retry wraps the bulkhead, so a concurrency slot is released during backoff. A
`Rasuvaeff\Bulkhead\BulkheadFullException` propagates as itself. Tests inject
their own `RunsProcess`; `RunsProcess::run()` returns a `ProcessOutcome`
(`exitCode`, `stderrTail`, `timedOut`).

ffmpeg writes into a private staging directory beside the destination. On
failure, including exhausted retry or a callback exception, staging is removed
and any existing destination stays untouched. On success the staged files are
committed; this also makes `source === outputPath` safe. Concurrent conversions
targeting the same destination are serialized with a destination lock.

### Progress and metadata (`FfprobeMediaInfo`)

Pass a `ProbesMedia` to get progress reporting and an up-front DRM check —
both opt-in, both no-ops without it:

```php
use Rasuvaeff\MediaConverter\FfprobeMediaInfo;
use Rasuvaeff\MediaConverter\Progress\ProgressEvent;

$converter = new MediaConverter(
    binary: FfmpegBinary::default(),
    runner: new SymfonyProcessRunner(),
    prober: new FfprobeMediaInfo(FfmpegBinary::default(), new SymfonyProcessRunner()),
);

$result = $converter->run($pipeline, 'clip.mp4', onProgress: function (ProgressEvent $event): void {
    if ($event->isDeterminate()) {
        printf("%.0f%%\n", $event->fraction() * 100);
    }
});
```

With a prober, every `run()` probes each media input once, before ffmpeg and
outside retry. Encrypted input is refused with `ConversionFailureReason::Drm`;
ffprobe output mentioning decryption maps to the same reason even when probing
exits non-zero. All local inputs count toward `inputBytes()`, while only
timeline inputs determine progress duration (a watermark image is neither
probed nor part of the timeline). Trim and animated previews adjust that
duration; single-frame outputs and `ReplaceAudio(shortest: true)` stay
indeterminate. Without a prober — or when the duration is unknown — progress stays indeterminate
(`ProgressEvent::fraction()` is null); `$onProgress` itself is a no-op unless
you pass one, and only then does the argv gain `-progress pipe:1 -nostats`.
Progress events also expose `phase()` with `probing`, `running`, `committing` or
`completed`. `ConversionResult::command()` returns the exact ffmpeg argv used.

For cooperative cancellation, pass a `CancellationToken` as the `$token`
argument of `run()`. The engine checks it between phases and before each ffmpeg
output chunk, throwing `ConversionCancelled` the moment it is set and rolling
back any staged output:

```php
use Rasuvaeff\MediaConverter\CancellationToken;

$token = new CancellationToken();
// cancel from a signal handler, request-abort, or deadline:
$converter->run($pipeline, 'clip.mp4', token: $token);
```

Long-lived workers probing the same files repeatedly can wrap any
`ProbesMedia` in the PSR-16 `CachedProbesMedia` decorator (requires a
`psr/simple-cache` implementation, e.g. `symfony/cache`):

```php
use Rasuvaeff\MediaConverter\CachedProbesMedia;

$prober = new CachedProbesMedia(
    inner: new FfprobeMediaInfo(FfmpegBinary::default(), new SymfonyProcessRunner()),
    cache: $psr16Cache,
    ttlSeconds: 86_400, // default; null = backend default
);
```

The cache key hashes `(path, filesize, filemtime)`, so a changed local file
gets a new key automatically and the TTL only guards against inode reuse. For
URLs size/mtime are unavailable — the key degrades to the path alone and the
TTL governs freshness entirely. A garbage cache value is treated as a miss,
never an error.

### Operations

Every operation implements `Operation\OperationInterface`. Filter operations
are applied in the order you add them (they are **not** commutative:
`scale,crop` differs from `crop,scale`).

| Operation | Effect |
|---|---|
| `Transcode(?videoCodec, ?audioCodec, ?videoBitrateKbps, ?audioBitrateKbps)` | Re-encode; codec names are ffmpeg encoder names (`libx264`, `aac`, …) |
| `Remux()` | Stream-copy (`-c copy`) into a new container, lossless |
| `Trim(Duration $from, ?Duration $to, fastSeek = false)` | Keep the `[from, to)` slice (accurate output-side seek by default; input-side fast-seek on demand) |
| `Scale(?width, ?height)` | Resize; a null dimension keeps the aspect ratio (`-2`) |
| `Crop(width, height, x = 0, y = 0)` | Crop a rectangle |
| `Rotate(90\|180\|270)` | Rotate by a right angle |
| `Pad(width, height, x = 0, y = 0, color = 'black')` | Pad to a canvas |
| `Fps(int)` | Force a constant frame rate |
| `ExtractAudio(codec = 'libmp3lame', ?bitrateKbps = 192)` | Drop video, encode audio |
| `Thumbnail(Duration $at, ?width)` | A single still frame (`-frames:v 1`, no audio) |
| `SpriteSheet(rows, cols, Duration $interval, ?tileWidth)` | A `cols x rows` contact sheet, sampling one frame every `$interval` |
| `AnimatedPreview(Duration $from, Duration $to, fps = 10, width = 320)` | A palette-optimised animated GIF preview of `[from, to)` |
| `TextOverlay(text, Position $position = BottomRight, margin = 16, fontSize = 24, fontColor = 'white', ?fontFile)` | Burn text into the video (`drawtext`) |
| `Watermark(image, Position $position = BottomRight, margin = 16, ?opacity)` | Overlay an image (a second input) onto the video |
| `ReplaceAudio(audioSource, shortest = true)` | Replace the audio with a track from a second input |
| `NormalizeLoudness(integratedLufs = -16, truePeakDbtp = -1.5, loudnessRangeLu = 11)` | EBU R128 loudness normalisation (`loudnorm`) |
| `BurnSubtitles(srtOrAss)` | Burn `.srt`/`.ass` subtitles into the video |
| `ExtractSubtitles(streamIndex = 0)` | Extract one subtitle stream (pipeline output is the subtitle file) |
| `SelectStreams(?videoIndex, ?audioIndex, ?subtitleIndex, optional = false)` | Select concrete streams from the primary input |
| `PackageHls(segmentSeconds = 6, ?segmentFilenamePattern)` | Package as an HLS VOD playlist + `.ts` segments |
| `PackageDash(segmentSeconds = 5)` | Package as a DASH manifest + `.m4s` segments |
| `AddMetadata(array $tags)` | Write container metadata tags (`-metadata key=value`) |
| `AddArtwork::forAudio(image, id3v2 = true)` / `AddArtwork::forVideo(image)` | Embed cover art as an `attached_pic` stream |

Incompatible combinations are rejected **before** ffmpeg runs: composing
`Remux()` with any filter or codec operation, or combining `AnimatedPreview`
or `Watermark` with another video filter operation, throws `ConversionFailed`
with `ConversionFailureReason::IncompatibleOperations`.

Terminal operations declare their output kind. `ExtractAudio` cannot be
combined with a video-producing operation such as `Watermark`; thumbnail,
animated-preview and subtitle extraction combinations are rejected before
ffmpeg rather than producing a mislabeled file.

`AnimatedPreview`'s palette graph (`split`/`palettegen`/`paletteuse`) is
single-input/single-output so it renders through the normal filter chain — no
`-filter_complex` needed — but it is a complete, self-contained graph and
does its own trim/fps/scale, so it cannot be combined with `Scale`, `Crop`,
or another filter operation (a separate `Trim` is fine, since `Trim` adds no
filter).

`Watermark` needs a second input (the overlay image), so it composes a
`-filter_complex` graph instead — ffmpeg does not allow combining `-vf` and
`-filter_complex` for the same output stream, so `Watermark` cannot be
combined with a plain video filter operation either (a plain audio filter,
e.g. `NormalizeLoudness`, composes fine). Only one `Watermark` per pipeline
is supported. Output streams occupy semantic video/audio slots, so
`Watermark` followed by `ReplaceAudio` correctly maps the watermarked video
and replacement audio instead of retaining stale maps. An explicit
`SelectStreams(videoIndex: …)` cannot be combined with `Watermark` or
`Pipeline::concat()` — the video stream comes out of the filter graph there,
not out of a selectable input — and is rejected in either add-order.

Only one operation may seek the output timeline: combining `Trim` with
`Thumbnail` (both emit `-ss`) is rejected instead of letting the later `-ss`
silently win and produce a frame outside the trimmed range. A separate `Trim`
with `AnimatedPreview` stays accepted — the preview's own `from`/`to` fully
override the trim, which is harmless (and redundant).

`Trim` seeks output-side by default (`-ss`/`-to` after `-i`): frame-accurate,
but ffmpeg decodes everything up to the cut point. With
`Trim(..., fastSeek: true)` the `-ss` moves before `-i` and ffmpeg jumps via
the container index instead — on a long input this is seconds instead of
minutes. The end bound is then emitted as a duration (`-t to-from`), because
input seeking resets output timestamps; the resulting `[from, to)` slice is
the same. When transcoding, modern ffmpeg still decodes from the preceding
keyframe and discards up to the requested point, so the cut stays accurate;
with `Remux` (stream copy) either mode lands on keyframes. Fast-seek is
rejected on a `Pipeline::concat()` pipeline — the input option would apply to
the first segment only.

`TextOverlay`'s `fontFile` and `BurnSubtitles`' subtitle path cannot contain
a single quote — ffmpeg has no way to embed a literal `'` in a filter
argument, so both reject such a path in the constructor rather than produce
a broken command.

`TextOverlay` without an explicit `fontFile` lets `drawtext` fall back to the
`Sans` fontconfig family. That resolves only if the host has a discoverable
font installed — a minimal box (e.g. a bare Alpine ffmpeg) ships none and
ffmpeg fails at runtime with *"Cannot find a valid font for the family Sans"*.
For a predictable result pass a real `fontFile` (or install a font package such
as `ttf-dejavu` and point at `…/DejaVuSans.ttf`). See `examples/text-overlay.php`.

`PackageHls` and `PackageDash` only set the output format/segmenting options —
they compose with `Remux` (lossless repackaging) or `Transcode` (re-encode while
segmenting), same as any codec-only operation. The manifest and every generated
sidecar are staged and committed together and exposed by
`ConversionResult::outputArtifacts()`. Default sidecar names include a unique
generation id. Sidecars are committed before the manifest, and a hidden
inventory removes obsolete sidecars from the previous managed generation.
Custom HLS segment patterns keep their consumer-selected names and are not
managed by that inventory; their manifest is still published last.

### Metadata and cover art

`AddMetadata` writes container-level tags (`-metadata key=value`) and needs no
re-encode, so it composes with `Remux` — retagging is a stream copy. Keys are
whitelisted (`title`, `artist`, `album`, `album_artist`, `composer`, `genre`,
`track`, `disc`, `year`, `date`, `comment`, `description`, `language`,
`copyright`, `publisher`, `encoder`); an unknown key throws instead of being
silently written as a nonsense tag. Values are plain argv elements — `=`,
unicode and newlines need no escaping.

`AddArtwork` embeds a JPEG/PNG as an `attached_pic` stream. The target
container decides the stream layout and an operation cannot see the output
path, so the caller states it: `AddArtwork::forAudio($image)` for audio-only
targets (MP3 by default; pass `id3v2: false` for M4A/FLAC, whose muxers
reject the MP3-specific `-id3v2_version`) and `AddArtwork::forVideo($image)`
for video targets (MP4/MKV: main video stays `v:0`, the cover becomes `v:1`).
The cover stream is stream-copied, never re-encoded (a JPEG is already an
MJPEG stream, a PNG stays PNG), so it composes with `Remux`. When combined
with a video `Transcode`, add the artwork **after** it — ffmpeg applies the
last matching codec option per stream. Only one `AddArtwork` per pipeline;
combining it with `ExtractAudio` is rejected (its `-vn` would discard the
cover) — extract audio *with* art via `Transcode(audioCodec: ...)` +
`forAudio()` instead:

```php
use Rasuvaeff\MediaConverter\Operation\{AddArtwork, AddMetadata, Remux, Transcode};

// Retag + re-cover an MP3, no re-encode:
$pipeline = Pipeline::from('song.mp3')
    ->add(new Remux())
    ->add(new AddMetadata(['title' => 'My Song', 'artist' => 'Me', 'track' => 7]))
    ->add(AddArtwork::forAudio('cover.jpg'));

// Extract MP3 from a video AND embed a cover:
$pipeline = Pipeline::from('input.mp4')
    ->add(new Transcode(audioCodec: 'libmp3lame', audioBitrateKbps: 192))
    ->add(AddArtwork::forAudio('cover.jpg'));
```

### Concatenation

`Pipeline::concat(list<string> $segments, bool $hasAudio = true)` joins
several segments into one output, re-encoding via ffmpeg's `concat` FILTER
(`-filter_complex … concat=n=…:v=1:a=…`). It is a separate pipeline
constructor, not an operation — every operation decorates ONE pre-existing
primary source, but concat composes several segments INTO the source itself:

```php
$pipeline = Pipeline::concat(['part1.mp4', 'part2.mp4', 'part3.mp4'])
    ->add(new Transcode(videoCodec: 'libx264'));
```

`$hasAudio` must match every segment (a mismatch fails loudly at ffmpeg
runtime, not silently). Further operations still compose: a codec-only one
like `Transcode` runs fine on the concatenated result, but a plain video OR
audio filter operation is rejected — concat feeds both streams through
`-filter_complex`, which ffmpeg cannot combine with `-vf`/`-af`, same rule
as `Watermark`. `Concat` cannot be combined with `Watermark` either
(both own `-filter_complex`); the pipeline rejects this before ffmpeg runs.
An operation that explicitly claims the audio output (`ReplaceAudio`, or
`SelectStreams(audioIndex: …)`) drops the segments' own audio pads (`a=0`),
so the claimed track is the only audio stream in the output.
`source()` keeps returning the first segment for compatibility, while
`sources()` returns all segments. An injected prober checks every segment for
DRM and sums their durations; if any duration is unknown, progress remains
indeterminate.

This is the `concat` FILTER (re-encodes), not the lossless `concat` DEMUXER
(`-f concat -i list.txt -c copy`, stream-copy but needs a temp list file with
its own cleanup lifecycle) — deferred; `.m3u8`/`.mpd` playlists don't need
either: ffmpeg reads them natively, so `Pipeline::from('playlist.m3u8')
->add(new Remux())` is the HLS/DASH-heritage remux path.

### `Position`

Used by `TextOverlay` (and future overlay operations) to anchor content in the
frame: `TopLeft`, `TopCenter`, `TopRight`, `MiddleLeft`, `Center`,
`MiddleRight`, `BottomLeft`, `BottomCenter`, `BottomRight`.

### `FfmpegBinary`

| Method | Description |
|---|---|
| `new FfmpegBinary(ffmpegPath:, ffprobePath:)` | Custom paths (defaults `/usr/bin/ffmpeg`, `/usr/bin/ffprobe`) |
| `FfmpegBinary::default()` | Standard Unix paths |
| `ffmpegPath()` / `ffprobePath()` | Accessors |
| `assertExecutable()` | Checks both binaries |
| `assertFfmpegExecutable()` / `assertFfprobeExecutable()` | Checks only the required binary and throws its specific reason with exit 127 |

### `ConversionResult` / `MediaInfo`

`ConversionResult` (from the engine): `outputPath()`, `outputArtifacts()`,
`elapsed()` (a `Duration`), `inputBytes()`, `outputBytes()`,
`outputMebibytes()`, `command()`. Byte counts include every concat input and
every committed package artifact.

`MediaInfo` (from `ProbesMedia::probe()`, e.g. `FfprobeMediaInfo`): `duration()`
(a `Duration`), `width()`, `height()`, `videoCodec()`, `audioCodec()`,
`bitrate()`, `hasVideo()`, `hasAudio()`, `isEncrypted()`. `isEncrypted()` is a
heuristic: true when ffprobe's output mentions "decrypt" — there is no
dedicated encrypted-stream flag.

### `ConversionFailed`

Extends `\RuntimeException`; `->reason` is a `ConversionFailureReason` enum:

| Reason | When |
|---|---|
| `Timeout` | Wall-clock / idle timeout exceeded |
| `NonZeroExit` | ffmpeg exited non-zero (the only potentially transient reason) |
| `NoInput` | The source could not be opened |
| `Drm` | The source is DRM-protected (encrypted) |
| `ProbeFailed` | ffprobe could not read the source |
| `IncompatibleOperations` | The pipeline's operations conflict (checked before running) |
| `OutputFailed` | Output staging or commit failed |
| `FfmpegNotExecutable` / `FfprobeNotExecutable` | Preflight failed or the subprocess exited with code 127 |

Also exposes `->exitCode` and `stderrTail()` (never empty — the single reader
for the captured stderr).

### Exception hierarchy

Every reachable failure implements the `MediaConverterException` marker
interface, so one `catch` handles both cases:

```php
use Rasuvaeff\MediaConverter\MediaConverterException;

try {
    $converter->run($pipeline, 'clip.mp4', token: $token);
} catch (MediaConverterException $e) {
    // ConversionFailed (subprocess/validation) or ConversionCancelled (token)
}
```

`ConversionFailed` and `ConversionCancelled` both extend `\RuntimeException` and
implement the marker. Internal `\LogicException`s guard impossible states
(programming bugs) and deliberately do **not** implement it — they are meant to
surface, not be swallowed by a domain `catch`.

### `ProgressEvent`

Emitted by the engine through a `callable(ProgressEvent): void`. A completion
`fraction()` is known only when the total duration is (from ffprobe);
otherwise the sample is indeterminate — check `isDeterminate()` before reading
`fraction()`. Also carries `outTime()` (a `Duration`), `frame()`, `fps()`,
`speed()`.

### `Preset\Presets`

Ready-made `Pipeline`s for frequent tasks — the original download-helper use
case (HLS/DASH → MP4) lives here, as an ordinary composition of the core
operations, not baked into the core itself:

| Preset | Pipeline |
|---|---|
| `hlsToMp4(source)` | `Remux()` + the `aac_adtstoasc` bitstream filter (fixes ADTS AAC audio for MP4) |
| `hlsToMp4Transcode(source)` | `Transcode(videoCodec: 'libx264', audioCodec: 'aac')` |
| `dashToMp4(source)` | `Remux()` (DASH's fMP4 segments need no bitstream filter) |
| `webM(source, videoBitrateKbps = 2500, audioBitrateKbps = 128)` | `Transcode(videoCodec: 'libvpx-vp9', audioCodec: 'libopus', …)` — VP9 compresses better than VP8 but encodes slower |
| `mp3(source, bitrateKbps = 192)` | `ExtractAudio('libmp3lame', bitrateKbps)` |
| `aac(source, bitrateKbps = 128)` | `ExtractAudio('aac', bitrateKbps)` |
| `webThumbnail(source, Duration $at, width = 320)` | `Thumbnail($at, $width)` |
| `socialClip(source, Duration $from, Duration $to, maxWidth = 720)` | `Trim` + `Scale(width: $maxWidth)` + `Transcode(videoCodec: 'libx264', audioCodec: 'aac')` |

```php
use Rasuvaeff\MediaConverter\Preset\Presets;

$pipeline = Presets::hlsToMp4('https://example.test/stream.m3u8');
$result = $converter->run($pipeline, 'out.mp4');
```

## Security

- Every path and URL is a separate argv entry (`symfony/process` array form),
  never interpolated into a shell string.
- `ffmpegPath` / `ffprobePath` are stored verbatim; call `assertExecutable()`
  before running if they originate from user input.
- This library does not bypass DRM — encrypted sources fail with
  `ConversionFailureReason::Drm`.

## Examples

See [`examples/`](examples/) — most compose a pipeline and print its ffmpeg
argv (no binary required); `cancellation.php` and `cached-probes.php` drive the
engine with in-memory stand-ins, and only `convert.php` runs a real conversion:

- `transcode.php` — downscale + transcode
- `hls-to-mp4.php` — remux an HLS stream (`Preset\Presets::hlsToMp4()`)
- `extract-audio.php` — extract an MP3 track
- `trim-and-crop.php` — cut a slice (accurate and fast-seek) and crop a square
- `thumbnail.php` — a single resized still frame
- `watermark.php` — overlay a logo image
- `text-overlay.php` — burn a caption with `drawtext` (note the font requirement)
- `concat.php` — join loose segment files (`Pipeline::concat()`)
- `metadata.php` — write container tags during a remux
- `artwork.php` — embed cover art (MP3 and MP4 variants)
- `normalize-loudness.php` — EBU R128 loudness normalisation
- `animated-preview.php` — a palette-optimised animated GIF preview
- `package-hls.php` — package as an HLS VOD playlist + segments
- `presets.php` — `Preset\Presets`: thumbnail, social clip, MP3/AAC extraction, WebM
- `cancellation.php` — cooperative cancellation with `CancellationToken`
- `cached-probes.php` — cache ffprobe results with `CachedProbesMedia`
- `convert.php` — a real end-to-end conversion (needs ffmpeg)

## Development

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

See `AGENTS.md` for the full list of commands and conventions.

## License

BSD-3-Clause, see [`LICENSE.md`](LICENSE.md).
