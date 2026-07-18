<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\Position;
use Rasuvaeff\MediaConverter\Operation\TextOverlay;
use Rasuvaeff\MediaConverter\Pipeline;

// Burn a caption into the video via drawtext. IMPORTANT: without an explicit
// $fontFile, drawtext falls back to the "Sans" fontconfig family, which fails
// at runtime on a minimal box that ships no fonts ("Cannot find a valid font
// for the family Sans"). Pass a real font file for a predictable result — on
// Alpine/CI install `ttf-dejavu` and point at
// /usr/share/fonts/ttf-dejavu/DejaVuSans.ttf.
$binary = FfmpegBinary::default();

$pipeline = Pipeline::from('input.mp4')
    ->add(new TextOverlay(
        text: 'Recorded 2026 — 100% live',
        position: Position::BottomRight,
        fontSize: 28,
        fontColor: 'white',
        fontFile: '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    ));

echo implode(' ', array_map('escapeshellarg', $pipeline->toArgv($binary, 'out.mp4'))), "\n";
