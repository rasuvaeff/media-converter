<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\Thumbnail;
use Rasuvaeff\MediaConverter\Pipeline;

// A single still frame at 5s, resized to 320px wide.
$binary = FfmpegBinary::default();

$pipeline = Pipeline::from('input.mp4')
    ->add(new Thumbnail(Duration::seconds(5), width: 320));

echo implode(' ', array_map('escapeshellarg', $pipeline->toArgv($binary, 'thumb.jpg'))), "\n";
