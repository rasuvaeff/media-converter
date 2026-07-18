<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\NormalizeLoudness;
use Rasuvaeff\MediaConverter\Operation\Transcode;
use Rasuvaeff\MediaConverter\Pipeline;

// EBU R128 loudness normalisation to the streaming-platform default
// (-16 LUFS). loudnorm is an audio filter, so it re-encodes — pair it with
// a codec so the video stream isn't left ambiguous.
$binary = FfmpegBinary::default();

$pipeline = Pipeline::from('input.mp4')
    ->add(new NormalizeLoudness())
    ->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac'));

echo implode(' ', array_map('escapeshellarg', $pipeline->toArgv($binary, 'out.mp4'))), "\n";
