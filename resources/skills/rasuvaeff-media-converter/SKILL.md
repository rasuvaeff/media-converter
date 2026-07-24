---
name: rasuvaeff-media-converter
description: >-
  Run ffmpeg/ffprobe safely from PHP with rasuvaeff/media-converter — compose
  Operation nodes (Transcode, Remux, Trim, Scale, Watermark, Thumbnail,
  PackageHls, …) into an immutable Pipeline that renders the exact ffmpeg argv,
  executed by MediaConverter with retry, bulkhead, timeouts, progress events
  and cancellation. Use when writing, reviewing or debugging media conversion,
  ffmpeg command building, probing or HLS/DASH handling in a project that has
  this package installed.
---

# rasuvaeff/media-converter

Type-safe wrapper over the `ffmpeg`/`ffprobe` subprocess. Operations compose
into an immutable `Pipeline`; `MediaConverter` runs the rendered argv.
Namespace `Rasuvaeff\MediaConverter\`.

## Safety rules — verify these on every change

1. **Never build a shell string.** The pipeline renders an exact argv
   (`symfony/process` array form); every path, URL and option is a separate
   argv entry. Never concatenate user input into a command — compose
   operations and let `toArgv()` / `MediaConverter::run()` do the rendering.

   ```php
   $conv->run(Pipeline::from($userPath)->add(new Remux()), $out); // correct
   shell_exec("ffmpeg -i {$userPath} out.mp4");                   // injection
   ```

2. **Incompatible compositions fail BEFORE ffmpeg — don't fight it.**
   Stream-copy (`Remux`) + any filter/codec op, `Watermark` + concat,
   `Watermark`/`AnimatedPreview`/concat + another video filter, or two seek
   owners (`Trim` + `Thumbnail`) throw `ConversionFailed` with reason
   `IncompatibleOperations`. Filters are applied in insertion order and are
   NOT commutative (`scale,crop` != `crop,scale`) — never reorder them.

3. **A literal single quote (`'`) cannot survive an ffmpeg filter path.**
   `TextOverlay(fontFile:)` and `BurnSubtitles($srtOrAss)` reject such paths
   in the constructor — do not "fix" this by escaping; every scheme is
   silently dropped by ffmpeg's filtergraph parser (verified empirically).

4. **DRM is never bypassed.** With a prober configured, an encrypted source
   fails with reason `Drm` before ffmpeg starts. Never work around it.

5. **Only transient failures retry.** `ConversionFailureReason::NonZeroExit`
   is the sole potentially-transient reason (`isPotentiallyTransient()`);
   retry policies must match on it — retrying deterministic failures
   (bad input, incompatible ops, DRM) just repeats the error.

6. **Progress can be indeterminate.** `ProgressEvent::fraction()` is nullable
   (unknown total duration) — check `isDeterminate()` first, never fabricate
   a percentage. Cancellation is cooperative: pass a `CancellationToken` to
   `run()` and call `$token->cancel()`; staging is rolled back.

## Canonical usage

```php
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\{FfmpegBinary, MediaConverter, Pipeline, SymfonyProcessRunner};
use Rasuvaeff\MediaConverter\Operation\{Scale, Transcode, Trim};

$pipeline = Pipeline::from('input.mov')
    ->add(new Trim(Duration::seconds(30), Duration::seconds(90)))
    ->add(new Scale(height: 720))
    ->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac', videoBitrateKbps: 2500));

$conv = new MediaConverter(FfmpegBinary::default(), new SymfonyProcessRunner());
$result = $conv->run($pipeline, 'out.mp4'); // ConversionResult | ConversionFailed
```

Composing/inspecting a pipeline needs no ffmpeg binary; only `run()` does.
For frequent tasks prefer `Preset\Presets` (`hlsToMp4()`, `webMp4()`, `mp3()`,
`socialClip()`, …) over hand-rolled compositions.

## Full API

The complete reference — every operation's argv shape, `Pipeline::concat()`
semantics, probing/caching, progress and failure contracts — ships with the
package: read `vendor/rasuvaeff/media-converter/llms.txt` before guessing a
method or operation name.
