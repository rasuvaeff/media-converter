<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Preset\Presets;

// Remux an HLS stream into MP4 without re-encoding: fast and lossless when the
// segments already carry MP4-compatible codecs (H.264/AAC). Presets::hlsToMp4()
// also fixes the AAC bitstream (ADTS -> ASC) that MP4 expects — a manual
// Pipeline::from(...)->add(new Remux()) would need that too.
$binary = FfmpegBinary::default();

$pipeline = Presets::hlsToMp4('https://example.test/stream.m3u8');

// No engine call here — print the ffmpeg argv the pipeline would run.
echo implode(' ', array_map('escapeshellarg', $pipeline->toArgv($binary, 'out.mp4'))), "\n";
