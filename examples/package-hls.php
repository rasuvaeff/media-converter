<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\PackageHls;
use Rasuvaeff\MediaConverter\Operation\Transcode;
use Rasuvaeff\MediaConverter\Pipeline;

// Package as an HLS VOD playlist (1s segments) while transcoding to H.264 —
// PackageHls always sets -hls_playlist_type vod so every segment is kept.
$binary = FfmpegBinary::default();

$pipeline = Pipeline::from('input.mp4')
    ->add(new Transcode(videoCodec: 'libx264'))
    ->add(new PackageHls(segmentSeconds: 4, segmentFilenamePattern: 'segment_%03d.ts'));

echo implode(' ', array_map('escapeshellarg', $pipeline->toArgv($binary, 'playlist.m3u8'))), "\n";
