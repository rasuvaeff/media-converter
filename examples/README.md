# Examples

Runnable scripts demonstrating `rasuvaeff/media-converter`.

Most scripts compose a `Pipeline` of operations (or a `Preset`) and print the
ffmpeg argv the pipeline would run — side-effect-free, no ffmpeg process is
spawned and no file is written, so they run anywhere. Two scripts
(`cancellation.php`, `cached-probes.php`) drive the engine with in-memory
stand-ins to show runtime behaviour, still without a real ffmpeg. Only
`convert.php` runs a real conversion.

| Script | Shows | Needs server? |
|---|---|---|
| `hls-to-mp4.php` | Remux an HLS stream into MP4 (`Preset\Presets::hlsToMp4()`) | no |
| `extract-audio.php` | Drop video, encode MP3 audio (`ExtractAudio`) | no |
| `transcode.php` | Downscale + transcode to H.264/AAC (`Scale` + `Transcode`) | no |
| `trim-and-crop.php` | Cut a slice — accurate and fast-seek — and crop a square (`Trim` + `Crop`) | no |
| `thumbnail.php` | A single resized still frame (`Thumbnail`) | no |
| `watermark.php` | Overlay a logo image (`Watermark`, `-filter_complex`) | no |
| `text-overlay.php` | Burn a caption with `drawtext` (`TextOverlay`, note the font requirement) | no |
| `concat.php` | Join loose segment files into one output (`Pipeline::concat()`) | no |
| `metadata.php` | Write container tags during a remux (`AddMetadata`) | no |
| `artwork.php` | Embed cover art, MP3 and MP4 variants (`AddArtwork`) | no |
| `normalize-loudness.php` | EBU R128 loudness normalisation (`NormalizeLoudness`) | no |
| `animated-preview.php` | A palette-optimised animated GIF preview (`AnimatedPreview`) | no |
| `package-hls.php` | Package as an HLS VOD playlist + segments (`PackageHls`) | no |
| `presets.php` | `Preset\Presets`: `webThumbnail()`, `socialClip()`, `mp3()`, `aac()`, `webM()` | no |
| `cancellation.php` | Cooperative cancellation via `CancellationToken` (fake runner) | no |
| `cached-probes.php` | Cache ffprobe results with `CachedProbesMedia` (in-memory PSR-16) | no |
| `convert.php` | Run a real conversion with probing, phases, progress and typed failures | yes |

## Running

```bash
php examples/transcode.php
php examples/convert.php input.mp4 out.mp4
```

Only `convert.php` reads environment variables — `MEDIA_SOURCE`,
`MEDIA_OUTPUT`, `FFMPEG_BIN` and `FFPROBE_BIN` (it exits with usage if no
source/output is given). Every other script uses `FfmpegBinary::default()`, so
the printed argv shows the default `ffmpeg`/`ffprobe` names:

```bash
FFMPEG_BIN=/opt/ffmpeg/bin/ffmpeg MEDIA_SOURCE=in.mp4 MEDIA_OUTPUT=out.mp4 php examples/convert.php
```

`cached-probes.php` needs a `psr/simple-cache` implementation on the autoloader
(`composer require psr/simple-cache`); the package itself only suggests it.
