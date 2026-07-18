<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\AddArtwork;
use Rasuvaeff\MediaConverter\Operation\Remux;
use Rasuvaeff\MediaConverter\Operation\Transcode;
use Rasuvaeff\MediaConverter\Pipeline;

$binary = FfmpegBinary::default();

// The target container decides the stream layout, so the caller states it:
// forAudio() for MP3/M4A/FLAC targets, forVideo() for MP4/MKV targets.

// MP3 with a cover, no re-encode of the audio (Remux).
$mp3 = Pipeline::from('song.mp3')
    ->add(new Remux())
    ->add(AddArtwork::forAudio('cover.jpg'));
echo implode(' ', array_map('escapeshellarg', $mp3->toArgv($binary, 'with-cover.mp3'))), "\n";

// Extract MP3 from a video AND embed a cover: Transcode(audioCodec: ...) picks
// the encoder; ExtractAudio would emit -vn, which discards the cover stream,
// so that combination is rejected fail-fast.
$fromVideo = Pipeline::from('input.mp4')
    ->add(new Transcode(audioCodec: 'libmp3lame', audioBitrateKbps: 192))
    ->add(AddArtwork::forAudio('cover.jpg'));
echo implode(' ', array_map('escapeshellarg', $fromVideo->toArgv($binary, 'audio-with-cover.mp3'))), "\n";

// MP4 poster frame: main video stays v:0, the cover becomes v:1. When a video
// Transcode is present, add the artwork AFTER it — ffmpeg applies the last
// matching codec option per stream, and the cover must stay stream-copied.
$mp4 = Pipeline::from('input.mp4')
    ->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac'))
    ->add(AddArtwork::forVideo('poster.png'));
echo implode(' ', array_map('escapeshellarg', $mp4->toArgv($binary, 'with-poster.mp4'))), "\n";
