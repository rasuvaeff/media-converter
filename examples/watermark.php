<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\Position;
use Rasuvaeff\MediaConverter\Operation\Watermark;
use Rasuvaeff\MediaConverter\Pipeline;

// Overlay a logo in the bottom-right corner, 60% opaque. Watermark needs a
// second input (the image), so it composes a -filter_complex graph — see
// AGENTS.md for why that's incompatible with a plain video filter operation.
$binary = FfmpegBinary::default();

$pipeline = Pipeline::from('input.mp4')
    ->add(new Watermark('logo.png', position: Position::BottomRight, opacity: 0.6));

echo implode(' ', array_map('escapeshellarg', $pipeline->toArgv($binary, 'out.mp4'))), "\n";
