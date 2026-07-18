<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\AddMetadata;
use Rasuvaeff\MediaConverter\Operation\Remux;
use Rasuvaeff\MediaConverter\Pipeline;

// AddMetadata writes container-level tags (-metadata key=value). It needs no
// re-encode, so it composes with Remux — retagging is a stream copy.
$pipeline = Pipeline::from('song.mp3')
    ->add(new Remux())
    ->add(new AddMetadata([
        'title' => 'My Song',
        'artist' => 'Me',
        'album' => 'Demo Album',
        'track' => 7,
    ]));

echo implode(' ', array_map('escapeshellarg', $pipeline->toArgv(FfmpegBinary::default(), 'tagged.mp3'))), "\n";
