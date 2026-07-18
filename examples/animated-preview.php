<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\AnimatedPreview;
use Rasuvaeff\MediaConverter\Pipeline;

// A palette-optimised animated GIF preview of the first 3 seconds — a single
// ffmpeg pass (split/palettegen/paletteuse), no -filter_complex needed.
$binary = FfmpegBinary::default();

$pipeline = Pipeline::from('input.mp4')
    ->add(new AnimatedPreview(Duration::seconds(0), Duration::seconds(3), fps: 10, width: 320));

echo implode(' ', array_map('escapeshellarg', $pipeline->toArgv($binary, 'preview.gif'))), "\n";
