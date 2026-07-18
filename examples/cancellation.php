<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\CancellationToken;
use Rasuvaeff\MediaConverter\ConversionCancelled;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\MediaConverter;
use Rasuvaeff\MediaConverter\Pipeline;
use Rasuvaeff\MediaConverter\ProcessOutcome;
use Rasuvaeff\MediaConverter\Progress\ProgressEvent;
use Rasuvaeff\MediaConverter\RunsProcess;

// Cooperative cancellation: pass a CancellationToken as the `$token` argument
// of MediaConverter::run(). The engine checks it between phases and before
// each ffmpeg output chunk, throwing ConversionCancelled the moment it is set.
//
// To stay ffmpeg-free this example injects a fake runner that streams a few
// progress reports; the callback cancels once the reported time passes 2s, and
// the run aborts on the next chunk. A real deployment cancels from a signal
// handler, a request-abort, or a wall-clock deadline instead.
$runner = new class implements RunsProcess {
    #[\Override]
    public function run(array $argv, Duration $timeout, Duration $idleTimeout, callable $onOutput): ProcessOutcome
    {
        foreach ([1, 2, 3, 4, 5] as $second) {
            $onOutput('out', sprintf("out_time_us=%d\nprogress=continue\n", $second * 1_000_000));
        }

        return new ProcessOutcome(exitCode: 0, stderrTail: '');
    }
};

$converter = new MediaConverter(binary: FfmpegBinary::default(), runner: $runner);
$token = new CancellationToken();

try {
    $converter->run(
        Pipeline::from('input.mp4'),
        'out.mp4',
        onProgress: static function (ProgressEvent $event) use ($token): void {
            printf("progress: %.0fs\n", $event->outTime()->toSeconds());

            if ($event->outTime()->toSeconds() >= 2.0) {
                $token->cancel();
            }
        },
        token: $token,
    );
    echo "completed\n";
} catch (ConversionCancelled $cancelled) {
    echo "cancelled: {$cancelled->getMessage()}\n";
}
