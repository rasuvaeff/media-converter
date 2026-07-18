<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\ExtractAudio;
use Rasuvaeff\MediaConverter\Pipeline;

// Drop the video stream and encode the audio to MP3 at 192 kbit/s.
$binary = FfmpegBinary::default();

$pipeline = Pipeline::from('input.mkv')
    ->add(new ExtractAudio(codec: 'libmp3lame', bitrateKbps: 192));

echo implode(' ', array_map('escapeshellarg', $pipeline->toArgv($binary, 'audio.mp3'))), "\n";
