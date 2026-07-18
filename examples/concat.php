<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\Transcode;
use Rasuvaeff\MediaConverter\Pipeline;

// Join several loose segment files into one output. This is the concat FILTER
// (re-encodes) — use it for clips that are NOT already a playlist/manifest.
// Pass hasAudio: false for video-only segments. A codec-only Transcode still
// composes; a plain video filter does not (concat owns the complex graph).
$binary = FfmpegBinary::default();

$pipeline = Pipeline::concat(['intro.mp4', 'body.mp4', 'outro.mp4'], hasAudio: true)
    ->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac'));

echo implode(' ', array_map('escapeshellarg', $pipeline->toArgv($binary, 'out.mp4'))), "\n";
