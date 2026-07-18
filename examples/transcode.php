<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\Scale;
use Rasuvaeff\MediaConverter\Operation\Transcode;
use Rasuvaeff\MediaConverter\Pipeline;

// Downscale to 720p and transcode to H.264/AAC — composed from two operations.
$binary = FfmpegBinary::default();

$pipeline = Pipeline::from('input.mov')
    ->add(new Scale(height: 720))
    ->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac', videoBitrateKbps: 2_500));

echo implode(' ', array_map('escapeshellarg', $pipeline->toArgv($binary, 'out.mp4'))), "\n";
