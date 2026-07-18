<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\Crop;
use Rasuvaeff\MediaConverter\Operation\Trim;
use Rasuvaeff\MediaConverter\Pipeline;

// Cut the 30s–90s slice and crop a centred 720×720 square — a "social clip".
$binary = FfmpegBinary::default();

$pipeline = Pipeline::from('input.mp4')
    ->add(new Trim(Duration::seconds(30), Duration::seconds(90)))
    ->add(new Crop(720, 720, 280, 0));

echo implode(' ', array_map('escapeshellarg', $pipeline->toArgv($binary, 'clip.mp4'))), "\n";

// Same slice with fast-seek: -ss moves before -i, so ffmpeg jumps via the
// container index instead of decoding the skipped 30 seconds.
$fast = Pipeline::from('input.mp4')
    ->add(new Trim(Duration::seconds(30), Duration::seconds(90), fastSeek: true))
    ->add(new Crop(720, 720, 280, 0));

echo implode(' ', array_map('escapeshellarg', $fast->toArgv($binary, 'clip-fast.mp4'))), "\n";
