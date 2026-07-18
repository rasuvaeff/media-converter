<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Preset\Presets;

// Preset\Presets wraps frequent tasks as ready-made Pipelines — no manual
// operation composition needed for the common cases.
$binary = FfmpegBinary::default();

$webThumbnail = Presets::webThumbnail('input.mov', Duration::seconds(5));
echo implode(' ', array_map('escapeshellarg', $webThumbnail->toArgv($binary, 'thumb.jpg'))), "\n";

$socialClip = Presets::socialClip('input.mov', Duration::seconds(10), Duration::seconds(25));
echo implode(' ', array_map('escapeshellarg', $socialClip->toArgv($binary, 'clip.mp4'))), "\n";

$mp3 = Presets::mp3('input.mov');
echo implode(' ', array_map('escapeshellarg', $mp3->toArgv($binary, 'audio.mp3'))), "\n";

$aac = Presets::aac('input.mov');
echo implode(' ', array_map('escapeshellarg', $aac->toArgv($binary, 'audio.m4a'))), "\n";

$webm = Presets::webM('input.mov');
echo implode(' ', array_map('escapeshellarg', $webm->toArgv($binary, 'video.webm'))), "\n";
