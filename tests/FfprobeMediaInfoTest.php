<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests;

use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\ConversionFailed;
use Rasuvaeff\MediaConverter\ConversionFailureReason;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\FfprobeMediaInfo;
use Rasuvaeff\MediaConverter\ProcessOutcome;
use Rasuvaeff\MediaConverter\Tests\Support\ScriptedRunner;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(FfprobeMediaInfo::class)]
final class FfprobeMediaInfoTest
{
    private const string SAMPLE_JSON = '{"streams":[{"codec_type":"video","codec_name":"h264","width":1920,"height":1080},{"codec_type":"audio","codec_name":"aac"}],"format":{"duration":"12.500000","bit_rate":"4500000"}}';

    public function parsesDurationDimensionsCodecsAndBitrate(): void
    {
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', self::SAMPLE_JSON]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('in.mp4');

        Assert::same($info->duration()->toMillis(), 12_500);
        Assert::same($info->width(), 1920);
        Assert::same($info->height(), 1080);
        Assert::same($info->videoCodec(), 'h264');
        Assert::same($info->audioCodec(), 'aac');
        Assert::same($info->bitrate(), 4_500_000);
        Assert::false($info->isEncrypted());
    }

    public function invokesFfprobeWithJsonOutputAndTheSource(): void
    {
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', self::SAMPLE_JSON]]);
        (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('https://cdn/video.mp4');

        Assert::same($runner->calls[0], [
            '/usr/bin/ffprobe', '-v', 'error', '-print_format', 'json',
            '-show_format', '-show_streams', 'https://cdn/video.mp4',
        ]);
    }

    public function usesTheDefaultTimeoutWhenNoneIsGiven(): void
    {
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', self::SAMPLE_JSON]]);
        (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::same($runner->timeouts[0][0]->toSeconds(), 30.0);
    }

    public function usesTheInjectedTimeoutWhenGiven(): void
    {
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', self::SAMPLE_JSON]]);
        (new FfprobeMediaInfo(FfmpegBinary::default(), $runner, Duration::seconds(5)))->probe('x.mp4');

        Assert::same($runner->timeouts[0][0]->toSeconds(), 5.0);
    }

    public function concatenatesStdoutAcrossMultipleChunks(): void
    {
        $half = (int) (strlen(self::SAMPLE_JSON) / 2);
        $runner = new ScriptedRunner(
            [new ProcessOutcome(0, '')],
            emit: [
                ['out', substr(self::SAMPLE_JSON, 0, $half)],
                ['out', substr(self::SAMPLE_JSON, $half)],
            ],
        );

        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::same($info->videoCodec(), 'h264');
    }

    public function audioOnlySourceHasNoVideoDimensions(): void
    {
        $json = '{"streams":[{"codec_type":"audio","codec_name":"mp3"}],"format":{"duration":"200.000000"}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('song.mp3');

        Assert::null($info->width());
        Assert::null($info->height());
        Assert::null($info->videoCodec());
        Assert::same($info->audioCodec(), 'mp3');
    }

    public function zeroBitrateIsReportedAsUnknown(): void
    {
        $json = '{"streams":[],"format":{"duration":"1.0","bit_rate":"0"}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::null($info->bitrate());
    }

    public function missingDurationDefaultsToZero(): void
    {
        $json = '{"streams":[],"format":{}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::true($info->duration()->isZero());
    }

    public function durationAsARawJsonIntegerIsAccepted(): void
    {
        $json = '{"streams":[],"format":{"duration":12}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::same($info->duration()->toSeconds(), 12.0);
    }

    public function durationAsARawJsonFloatIsAccepted(): void
    {
        $json = '{"streams":[],"format":{"duration":12.5}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::same($info->duration()->toSeconds(), 12.5);
    }

    public function integerBitrateAndStringDurationRemainTyped(): void
    {
        $json = '{"streams":[],"format":{"duration":"12.5","bit_rate":4500}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::same($info->duration()->toSeconds(), 12.5);
        Assert::same($info->bitrate(), 4500);
    }

    public function nonStringNumericFieldsAreRejectedInsteadOfBeingCoerced(): void
    {
        $json = '{"streams":[],"format":{"duration":true,"bit_rate":4500.5}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::true($info->duration()->isZero());
        Assert::null($info->bitrate());
    }

    public function durationWithATrailingNonNumericTailIsTreatedAsUnknown(): void
    {
        // is_numeric("12abc") is false, but (float) "12abc" truncates to 12.0 —
        // a naive OR-composed check would accept it. Must stay zero.
        $json = '{"streams":[],"format":{"duration":"12abc"}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::true($info->duration()->isZero());
    }

    public function widthOfZeroIsReportedAsUnknown(): void
    {
        $json = '{"streams":[{"codec_type":"video","codec_name":"h264","width":0,"height":1080}],"format":{}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::null($info->width());
    }

    public function nonStringCodecNameIsReportedAsUnknown(): void
    {
        $json = '{"streams":[{"codec_type":"video","codec_name":123}],"format":{}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::null($info->videoCodec());
    }

    public function bitrateWithATrailingNonDigitTailIsRejected(): void
    {
        $json = '{"streams":[],"format":{"bit_rate":"4500x"}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::null($info->bitrate());
    }

    public function bitrateWithALeadingNonDigitIsRejected(): void
    {
        $json = '{"streams":[],"format":{"bit_rate":"x4500"}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::null($info->bitrate());
    }

    public function bitrateWithDigitsInterruptedByAMidStringLetterIsRejected(): void
    {
        // "45x00" has trailing digits (would satisfy an end-anchored-only
        // regex) but is not a clean digit string; the (int) cast of a
        // leading-digit-then-letter value ("45x00" -> 45) is what would
        // otherwise mask a caret-less regex from a leading-garbage test alone.
        $json = '{"streams":[],"format":{"bit_rate":"45x00"}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::null($info->bitrate());
    }

    public function detectsEncryptionFromDecryptionMentionsInStderr(): void
    {
        $json = '{"streams":[{"codec_type":"video","codec_name":"h264"}],"format":{"duration":"1.0"}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, 'Failed to open codec: requires decryption key')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::true($info->isEncrypted());
    }

    public function detectsEncryptionFromDecryptionMentionsInStdoutAlone(): void
    {
        // stderr is empty here — the check must look at BOTH streams, not just one.
        $json = '{"streams":[{"codec_type":"video","codec_name":"h264","tags":{"note":"DECRYPT required"}}],"format":{"duration":"1.0"}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::true($info->isEncrypted());
    }

    public function attachedCoverArtIsNotAVideoStream(): void
    {
        // An MP3 with embedded cover art: ffprobe reports the JPEG as a video
        // stream with disposition.attached_pic=1 — not a real video track.
        $json = '{"streams":[{"codec_type":"video","codec_name":"mjpeg","width":600,"height":600,"disposition":{"attached_pic":1}},{"codec_type":"audio","codec_name":"mp3"}],"format":{"duration":"180.0"}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('song.mp3');

        Assert::false($info->hasVideo());
        Assert::null($info->videoCodec());
        Assert::null($info->width());
        Assert::same($info->audioCodec(), 'mp3');
    }

    public function aRealVideoStreamAfterCoverArtIsStillPicked(): void
    {
        $json = '{"streams":[{"codec_type":"video","codec_name":"mjpeg","disposition":{"attached_pic":1}},{"codec_type":"video","codec_name":"h264","width":1920,"height":1080,"disposition":{"attached_pic":0}}],"format":{"duration":"10.0"}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::same($info->videoCodec(), 'h264');
        Assert::same($info->width(), 1920);
    }

    public function dispositionWithoutAttachedPicIsARealVideoStream(): void
    {
        // ffprobe emits a full disposition object on real video tracks; an
        // absent attached_pic flag must default to "not cover art".
        $json = '{"streams":[{"codec_type":"video","codec_name":"h264","width":640,"height":360,"disposition":{"default":1}}],"format":{"duration":"1.0"}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::same($info->videoCodec(), 'h264');
        Assert::same($info->width(), 640);
    }

    public function negativeFormatDurationIsClampedToZero(): void
    {
        // A corrupt container can report a small negative duration; the probe
        // must not escape as a bare InvalidArgumentException from Duration.
        $json = '{"streams":[],"format":{"duration":"-0.020000"}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::same($info->duration()->toMicros(), 0);
    }

    public function sourcePathContainingDecryptDoesNotTripTheDrmHeuristic(): void
    {
        // -show_format echoes the probed path back as format.filename.
        $json = '{"streams":[{"codec_type":"video","codec_name":"h264"}],"format":{"filename":"/videos/decrypted/movie.mp4","duration":"1.0"}}';
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', $json]]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('/videos/decrypted/movie.mp4');

        Assert::false($info->isEncrypted());
    }

    public function decryptionDetectionIsCaseInsensitive(): void
    {
        $runner = new ScriptedRunner([new ProcessOutcome(0, 'requires DECRYPTION')], emit: [['out', '{"streams":[],"format":{}}']]);
        $info = (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');

        Assert::true($info->isEncrypted());
    }

    public function nonZeroExitThrowsProbeFailed(): void
    {
        $runner = new ScriptedRunner([new ProcessOutcome(1, 'Invalid data found when processing input')]);
        $caught = null;

        try {
            (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('missing.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::ProbeFailed);
    }

    public function decryptionFailureMapsToDrmEvenOnNonZeroExit(): void
    {
        $runner = new ScriptedRunner([new ProcessOutcome(1, 'Unable to decrypt protected stream')]);
        $caught = null;

        try {
            (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('encrypted.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::Drm);
    }

    public function upperCaseDecryptOnStderrStillMapsToDrmOnFailure(): void
    {
        // The failure-path scan must stay case-insensitive, matching the
        // successful-probe heuristic.
        $runner = new ScriptedRunner([new ProcessOutcome(1, 'Unable to DECRYPT protected stream')]);
        $caught = null;

        try {
            (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('encrypted.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::Drm);
    }

    public function decryptMentionOnlyInStdoutMapsToDrmOnFailure(): void
    {
        // The failure path runs BEFORE json_decode, on the raw stdout captured
        // so far — a decrypt diagnostic in a stream tag with a clean stderr
        // must still classify as DRM.
        $runner = new ScriptedRunner(
            [new ProcessOutcome(1, '')],
            emit: [['out', '{"streams":[{"codec_type":"video","tags":{"comment":"decrypt required"}}]}']],
        );
        $caught = null;

        try {
            (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('encrypted.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::Drm);
    }

    public function drmScanOnFailureConcatenatesStdoutBeforeStderr(): void
    {
        // A failing ffprobe can be cut off mid-write: the diagnostic starts at
        // the end of the captured stdout and continues on stderr. Scanning in
        // emission order (stdout then stderr) still sees "decrypt"; the
        // reversed concatenation would not.
        $runner = new ScriptedRunner(
            [new ProcessOutcome(1, 'rypt: cannot open session')],
            emit: [['out', '{"error":"Unable to dec']],
        );
        $caught = null;

        try {
            (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('encrypted.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::Drm);
    }

    public function exitCode127MapsToFfprobeNotExecutable(): void
    {
        $runner = new ScriptedRunner([new ProcessOutcome(127, 'not found')]);
        $caught = null;

        try {
            (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::FfprobeNotExecutable);
    }

    public function timeoutMapsToTheTimeoutReason(): void
    {
        $runner = new ScriptedRunner([new ProcessOutcome(124, '', timedOut: true)]);
        $caught = null;

        try {
            (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('slow.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::Timeout);
    }

    public function invalidJsonThrowsProbeFailedWithTheJsonExceptionChained(): void
    {
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', 'not json {']]);
        $caught = null;

        try {
            (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::ProbeFailed);
        // Distinguishes this path from the non-object-JSON fallback below: only
        // a genuine JSON parse failure chains a JsonException.
        Assert::instanceOf($caught->getPrevious(), \JsonException::class);
        Assert::string($caught->stderrTail())->contains('invalid JSON');
    }

    public function nonObjectJsonThrowsProbeFailed(): void
    {
        $runner = new ScriptedRunner([new ProcessOutcome(0, '')], emit: [['out', '42']]);
        $caught = null;

        try {
            (new FfprobeMediaInfo(FfmpegBinary::default(), $runner))->probe('x.mp4');
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->reason, ConversionFailureReason::ProbeFailed);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnEmptySource(): void
    {
        (new FfprobeMediaInfo(FfmpegBinary::default(), new ScriptedRunner([])))->probe('');
    }
}
