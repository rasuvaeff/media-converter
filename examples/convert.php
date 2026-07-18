<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\MediaConverter\ConversionFailed;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\FfprobeMediaInfo;
use Rasuvaeff\MediaConverter\MediaConverter;
use Rasuvaeff\MediaConverter\Pipeline;
use Rasuvaeff\MediaConverter\Progress\ProgressEvent;
use Rasuvaeff\MediaConverter\SymfonyProcessRunner;

$source = $argv[1] ?? (getenv('MEDIA_SOURCE') ?: null);
$output = $argv[2] ?? (getenv('MEDIA_OUTPUT') ?: null);

if ($source === null || $output === null) {
    fwrite(STDERR, "Usage: php examples/convert.php <source> <output>\n");
    fwrite(STDERR, "   or: MEDIA_SOURCE=in.mp4 MEDIA_OUTPUT=out.mp4 php examples/convert.php\n");
    fwrite(STDERR, "Optional: FFMPEG_BIN, FFPROBE_BIN to override binary paths.\n");
    exit(2);
}
$binary = new FfmpegBinary(
    ffmpegPath: getenv('FFMPEG_BIN') ?: '/usr/bin/ffmpeg',
    ffprobePath: getenv('FFPROBE_BIN') ?: '/usr/bin/ffprobe',
);
$runner = new SymfonyProcessRunner();
$converter = new MediaConverter(
    binary: $binary,
    runner: $runner,
    prober: new FfprobeMediaInfo($binary, $runner),
);

try {
    $result = $converter->run(
        Pipeline::from($source),
        $output,
        onProgress: static function (ProgressEvent $event): void {
            if ($event->isDeterminate()) {
                printf("%s %.0f%%\n", $event->phase()->value, $event->fraction() * 100);
            }
        },
    );
    printf("Wrote %s (%d bytes)\n", $result->outputPath(), $result->outputBytes());
} catch (ConversionFailed $failure) {
    fwrite(STDERR, sprintf("Conversion failed: %s (%s)\n", $failure->reason->value, $failure->stderrTail()));
    exit(1);
}
