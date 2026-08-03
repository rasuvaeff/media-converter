<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Internal;

use Rasuvaeff\MediaConverter\ConversionFailed;
use Rasuvaeff\MediaConverter\Internal\OutputLayout;
use Rasuvaeff\MediaConverter\Internal\OutputTransaction;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(OutputTransaction::class)]
final class OutputTransactionTest
{
    private string $directory;

    private string $previousWorkingDirectory;

    #[BeforeTest]
    public function createDirectory(): void
    {
        $this->directory = sys_get_temp_dir() . '/media-output-transaction-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        // Run inside the temp dir: OutputTransaction resolves relative HLS
        // patterns via getcwd(), so a mutant redirecting a commit to the CWD
        // must litter this auto-cleaned directory, not the package root.
        $this->previousWorkingDirectory = getcwd() ?: sys_get_temp_dir();
        chdir($this->directory);
    }

    #[AfterTest]
    public function removeDirectory(): void
    {
        chdir($this->previousWorkingDirectory);

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->directory . '/.*') ?: [] as $file) {
            if (basename($file) === '.' || basename($file) === '..') {
                continue;
            }

            is_dir($file) ? @rmdir($file) : @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function commitAtomicallyReplacesAnExistingSingleFile(): void
    {
        $output = $this->directory . '/out.mp4';
        file_put_contents($output, 'old');
        $transaction = new OutputTransaction($output, OutputLayout::single());
        file_put_contents($transaction->stagedOutputPath(), 'new');

        $artifacts = $transaction->commit();

        Assert::same($artifacts, [$output]);
        Assert::same(file_get_contents($output), 'new');
    }

    public function rollbackLeavesAnExistingDestinationUntouched(): void
    {
        $output = $this->directory . '/out.mp4';
        file_put_contents($output, 'old');
        $transaction = new OutputTransaction($output, OutputLayout::single());
        file_put_contents($transaction->stagedOutputPath(), 'partial');

        $transaction->rollback();

        Assert::same(file_get_contents($output), 'old');
    }

    public function destinationLockRejectsConcurrentTransactionsAndIsReleasedAfterCommit(): void
    {
        $output = $this->directory . '/out.mp4';
        $first = new OutputTransaction($output, OutputLayout::single());
        $caught = null;

        try {
            new OutputTransaction($output, OutputLayout::single());
        } catch (ConversionFailed $failure) {
            $caught = $failure;
        }

        Assert::notNull($caught);
        Assert::string($caught->stderrTail())->contains('Cannot lock output destination');
        file_put_contents($first->stagedOutputPath(), 'result');
        $first->commit();

        $next = new OutputTransaction($output, OutputLayout::single());
        $next->rollback();
    }

    public function destinationLockIsReleasedAfterRollback(): void
    {
        $output = $this->directory . '/out.mp4';
        $first = new OutputTransaction($output, OutputLayout::single());
        $lock = $this->directory . '/.out.mp4.media-converter.lock';

        Assert::true(is_file($lock));
        $first->rollback();

        $next = new OutputTransaction($output, OutputLayout::single());
        $next->rollback();
    }

    public function rewritesAndCommitsCustomHlsSegments(): void
    {
        $output = $this->directory . '/playlist.m3u8';
        $pattern = $this->directory . '/segment-%03d.ts';
        $transaction = new OutputTransaction($output, OutputLayout::hls($pattern));
        $argv = $transaction->rewriteArgv(['ffmpeg', '-hls_segment_filename', $pattern, $transaction->stagedOutputPath()]);
        $stagedPattern = $argv[2];
        $stagedSegment = dirname($stagedPattern) . '/segment-000.ts';
        file_put_contents($stagedSegment, 'segment');
        file_put_contents($transaction->stagedOutputPath(), $stagedSegment . "\n");

        $artifacts = $transaction->commit();

        Assert::same($artifacts, [$output, $this->directory . '/segment-000.ts']);
        Assert::same(file_get_contents($output), $this->directory . "/segment-000.ts\n");
    }

    public function defaultHlsUsesGenerationsAndRemovesObsoleteSidecars(): void
    {
        $output = $this->directory . '/playlist.m3u8';
        $first = new OutputTransaction($output, OutputLayout::hls(null));
        $firstArgv = $first->rewriteArgv(['ffmpeg', $first->stagedOutputPath()]);
        $firstIndex = array_search('-hls_segment_filename', $firstArgv, true);

        if (!is_int($firstIndex)) {
            throw new \LogicException('Missing generated HLS pattern');
        }

        $firstPattern = $firstArgv[$firstIndex + 1];
        $firstStagedSegment = sprintf($firstPattern, 0);
        file_put_contents($firstStagedSegment, 'first');
        file_put_contents($first->stagedOutputPath(), basename($firstStagedSegment) . "\n");
        $firstArtifacts = $first->commit();
        $firstSegment = $firstArtifacts[1];

        $second = new OutputTransaction($output, OutputLayout::hls(null));
        $secondArgv = $second->rewriteArgv(['ffmpeg', $second->stagedOutputPath()]);
        $secondIndex = array_search('-hls_segment_filename', $secondArgv, true);

        if (!is_int($secondIndex)) {
            throw new \LogicException('Missing generated HLS pattern');
        }

        $secondPattern = $secondArgv[$secondIndex + 1];
        $secondStagedSegment = sprintf($secondPattern, 0);
        file_put_contents($secondStagedSegment, 'second');
        file_put_contents($second->stagedOutputPath(), basename($secondStagedSegment) . "\n");
        $secondArtifacts = $second->commit();

        Assert::false(is_file($firstSegment));
        Assert::true(is_file($secondArtifacts[1]));
        Assert::same(file_get_contents($output), basename($secondArtifacts[1]) . "\n");
    }

    public function aPlainConversionNeverSweepsAnotherDestinationsManagedSegments(): void
    {
        // A managed-HLS destination "video.m3u8" shares the "video" stem with
        // "video.mp4" — the mp4 commit must not reap the playlist's segments.
        $segment = $this->directory . '/video-' . bin2hex(random_bytes(8)) . '-00001.ts';
        file_put_contents($this->directory . '/video.m3u8', basename($segment) . "\n");
        file_put_contents($segment, 'live segment');

        $output = $this->directory . '/video.mp4';
        $transaction = new OutputTransaction($output, OutputLayout::single());
        file_put_contents($transaction->stagedOutputPath(), 'mp4');
        $transaction->commit();

        Assert::true(is_file($segment));
    }

    public function aManagedHlsCommitDoesNotSweepSameStemDashSegments(): void
    {
        // Same stem, different format: "video.m3u8" must only reap its own
        // .ts generations, never a "video.mpd" destination's .m4s segments.
        $dashSegment = $this->directory . '/video-' . bin2hex(random_bytes(8)) . '-chunk-0-00001.m4s';
        file_put_contents($dashSegment, 'dash chunk');

        $output = $this->directory . '/video.m3u8';
        $transaction = new OutputTransaction($output, OutputLayout::hls(null));
        $argv = $transaction->rewriteArgv(['ffmpeg', $transaction->stagedOutputPath()]);
        $index = array_search('-hls_segment_filename', $argv, true);

        if (!is_int($index)) {
            throw new \LogicException('Missing generated HLS pattern');
        }

        $stagedSegment = sprintf($argv[$index + 1], 0);
        file_put_contents($stagedSegment, 'hls segment');
        file_put_contents($transaction->stagedOutputPath(), basename($stagedSegment) . "\n");
        $transaction->commit();

        Assert::true(is_file($dashSegment));
    }

    public function dashUsesGenerationSpecificSegmentNames(): void
    {
        $output = $this->directory . '/manifest.mpd';
        $transaction = new OutputTransaction($output, OutputLayout::dash());
        $argv = $transaction->rewriteArgv(['ffmpeg', $transaction->stagedOutputPath()]);

        Assert::true(in_array('-init_seg_name', $argv, true));
        Assert::true(in_array('-media_seg_name', $argv, true));
        $initIndex = array_search('-init_seg_name', $argv, true);

        if (!is_int($initIndex)) {
            throw new \LogicException('Missing generated DASH init name');
        }

        Assert::string($argv[$initIndex + 1])->contains('manifest-');

        $transaction->rollback();
    }

    public function dashRewriteInsertsExactSegmentNamesBeforeTheOutput(): void
    {
        $output = $this->directory . '/manifest.mpd';
        $transaction = new OutputTransaction($output, OutputLayout::dash());

        $argv = $transaction->rewriteArgv(['ffmpeg', '-y', $transaction->stagedOutputPath()]);

        Assert::same(count($argv), 7);
        Assert::same($argv[0], 'ffmpeg');
        Assert::same($argv[1], '-y');
        Assert::same($argv[2], '-init_seg_name');
        Assert::same($argv[4], '-media_seg_name');
        Assert::same($argv[6], $transaction->stagedOutputPath());
        Assert::same(preg_match('/^manifest-[a-f0-9]{16}-init-\$RepresentationID\$\.m4s$/', $argv[3]), 1);
        Assert::same(preg_match('/^manifest-[a-f0-9]{16}-chunk-\$RepresentationID\$-\$Number%05d\$\.m4s$/', $argv[5]), 1);

        $transaction->rollback();
    }

    public function rewriteArgvReplacesOnlyTheFirstSegmentFilenameOccurrence(): void
    {
        $output = $this->directory . '/playlist.m3u8';
        $pattern = $this->directory . '/segment-%03d.ts';
        $transaction = new OutputTransaction($output, OutputLayout::hls($pattern));

        $argv = $transaction->rewriteArgv([
            'ffmpeg',
            '-hls_segment_filename', $pattern,
            '-hls_segment_filename', 'untouched-%03d.ts',
            $transaction->stagedOutputPath(),
        ]);

        Assert::string($argv[2])->contains('/.media-converter-');
        Assert::same($argv[4], 'untouched-%03d.ts');

        $transaction->rollback();
    }

    public function rewriteArgvRejectsASegmentFlagWithoutAValue(): void
    {
        $output = $this->directory . '/playlist.m3u8';
        $pattern = $this->directory . '/segment-%03d.ts';
        $transaction = new OutputTransaction($output, OutputLayout::hls($pattern));
        $caught = null;

        try {
            $transaction->rewriteArgv(['ffmpeg', '-hls_segment_filename']);
        } catch (\LogicException $caught) {
        }

        Assert::notNull($caught);

        $transaction->rollback();
    }

    public function rewriteArgvAcceptsThePatternAsTheFinalArgvElement(): void
    {
        $output = $this->directory . '/playlist.m3u8';
        $pattern = $this->directory . '/segment-%03d.ts';
        $transaction = new OutputTransaction($output, OutputLayout::hls($pattern));

        $argv = $transaction->rewriteArgv(['ffmpeg', '-hls_segment_filename', $pattern]);

        Assert::same(count($argv), 3);
        Assert::same($argv[2], dirname($transaction->stagedOutputPath()) . '/segment-%03d.ts');

        $transaction->rollback();
    }

    public function customHlsSegmentsCommitIntoTheirOwnSubdirectory(): void
    {
        // targetFor must honour the pattern's directory, not fall back to the
        // playlist's directory.
        mkdir($this->directory . '/segments');
        $output = $this->directory . '/playlist.m3u8';
        $pattern = $this->directory . '/segments/segment-%03d.ts';
        $transaction = new OutputTransaction($output, OutputLayout::hls($pattern));
        $argv = $transaction->rewriteArgv(['ffmpeg', '-hls_segment_filename', $pattern, $transaction->stagedOutputPath()]);
        $stagedSegment = dirname($argv[2]) . '/segment-000.ts';
        file_put_contents($stagedSegment, 'segment');
        file_put_contents($transaction->stagedOutputPath(), $stagedSegment . "\n");

        $artifacts = $transaction->commit();

        Assert::same($artifacts, [$output, $this->directory . '/segments/segment-000.ts']);
        Assert::same(file_get_contents($this->directory . '/segments/segment-000.ts'), 'segment');
        Assert::same(file_get_contents($output), $this->directory . "/segments/segment-000.ts\n");
        @unlink($this->directory . '/segments/segment-000.ts');
        @rmdir($this->directory . '/segments');
    }

    public function inventoryFileHoldsExactlyThePrettyPrintedSidecarList(): void
    {
        $output = $this->directory . '/playlist.m3u8';
        $transaction = new OutputTransaction($output, OutputLayout::hls(null));
        $argv = $transaction->rewriteArgv(['ffmpeg', $transaction->stagedOutputPath()]);
        $index = array_search('-hls_segment_filename', $argv, true);

        if (!is_int($index)) {
            throw new \LogicException('Missing generated HLS pattern');
        }

        $stagedSegment = sprintf($argv[$index + 1], 0);
        file_put_contents($stagedSegment, 'hls segment');
        file_put_contents($transaction->stagedOutputPath(), basename($stagedSegment) . "\n");
        $artifacts = $transaction->commit();

        // Exact hidden path beside the playlist, exact pretty-printed body,
        // sidecars only (never the playlist itself), trailing newline.
        $inventory = $this->directory . '/.playlist.m3u8.media-converter.json';
        Assert::true(is_file($inventory));
        Assert::same(
            file_get_contents($inventory),
            json_encode([$artifacts[1]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
        );
    }

    public function commitFailsWhenTheInventoryCannotBeReplaced(): void
    {
        $output = $this->directory . '/playlist.m3u8';
        // A directory squatting on the inventory path makes the atomic
        // rename fail — the write alone succeeding must not mask that.
        mkdir($this->directory . '/.playlist.m3u8.media-converter.json');
        $transaction = new OutputTransaction($output, OutputLayout::hls(null));
        $argv = $transaction->rewriteArgv(['ffmpeg', $transaction->stagedOutputPath()]);
        $index = array_search('-hls_segment_filename', $argv, true);

        if (!is_int($index)) {
            throw new \LogicException('Missing generated HLS pattern');
        }

        $stagedSegment = sprintf($argv[$index + 1], 0);
        file_put_contents($stagedSegment, 'hls segment');
        file_put_contents($transaction->stagedOutputPath(), basename($stagedSegment) . "\n");
        $caught = null;

        try {
            $transaction->commit();
        } catch (ConversionFailed $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->stderrTail())->contains('Cannot commit the output artifact inventory');
        $transaction->rollback();
        @rmdir($this->directory . '/.playlist.m3u8.media-converter.json');
    }

    public function managedCommitSweepsEveryOrphanedGenerationOfItsOwnStem(): void
    {
        // Orphans from crashed runs carry the stem + a foreign generation id;
        // none of them is listed in any inventory, so only the directory scan
        // can find them — and it must find ALL of them.
        $orphanA = $this->directory . '/playlist-' . str_repeat('a', 16) . '-00001.ts';
        $orphanB = $this->directory . '/playlist-' . str_repeat('f', 16) . '-00002.ts';
        $foreign = $this->directory . '/other-' . str_repeat('a', 16) . '-00001.ts';
        file_put_contents($orphanA, 'orphan');
        file_put_contents($orphanB, 'orphan');
        file_put_contents($foreign, 'foreign');

        $output = $this->directory . '/playlist.m3u8';
        $transaction = new OutputTransaction($output, OutputLayout::hls(null));
        $argv = $transaction->rewriteArgv(['ffmpeg', $transaction->stagedOutputPath()]);
        $index = array_search('-hls_segment_filename', $argv, true);

        if (!is_int($index)) {
            throw new \LogicException('Missing generated HLS pattern');
        }

        $stagedSegment = sprintf($argv[$index + 1], 0);
        file_put_contents($stagedSegment, 'hls segment');
        file_put_contents($transaction->stagedOutputPath(), basename($stagedSegment) . "\n");
        $transaction->commit();

        Assert::false(is_file($orphanA));
        Assert::false(is_file($orphanB));
        Assert::true(is_file($foreign));
    }

    public function managedSweepMatchesTheStemLiterally(): void
    {
        // The stem is regex-quoted: "clip+1" must reap its own literal
        // generations and never a "clipp1" destination's (which an unquoted
        // "clip+1" pattern would match instead).
        $own = $this->directory . '/clip+1-' . str_repeat('a', 16) . '-00001.ts';
        $lookalike = $this->directory . '/clipp1-' . str_repeat('a', 16) . '-00001.ts';
        file_put_contents($own, 'own orphan');
        file_put_contents($lookalike, 'other destination');

        $output = $this->directory . '/clip+1.m3u8';
        $transaction = new OutputTransaction($output, OutputLayout::hls(null));
        $argv = $transaction->rewriteArgv(['ffmpeg', $transaction->stagedOutputPath()]);
        $index = array_search('-hls_segment_filename', $argv, true);

        if (!is_int($index)) {
            throw new \LogicException('Missing generated HLS pattern');
        }

        $stagedSegment = sprintf($argv[$index + 1], 0);
        file_put_contents($stagedSegment, 'hls segment');
        file_put_contents($transaction->stagedOutputPath(), basename($stagedSegment) . "\n");
        $transaction->commit();

        Assert::false(is_file($own));
        Assert::true(is_file($lookalike));
    }

    public function managedSweepDoesNotMatchTrailingNewlineInExtension(): void
    {
        // PCRE `$` matches before a trailing `\n`; a stale file whose name ends
        // in ".ts\n" must not be reaped as a managed artifact of the matching
        // stem. The pattern is anchored with `\z` to keep it whole-subject.
        //
        // No Windows equivalent: the Win32 file APIs PHP's file_put_contents()
        // goes through reject a trailing control character in a filename, so
        // the fixture itself cannot be created there — this is a POSIX
        // filename-byte-freedom test, not a regex-anchor test on that OS.
        if (\PHP_OS_FAMILY === 'Windows') {
            return;
        }

        $stale = $this->directory . '/playlist-' . str_repeat('a', 16) . '-00001.ts' . "\n";
        file_put_contents($stale, 'stale');

        $output = $this->directory . '/playlist.m3u8';
        $transaction = new OutputTransaction($output, OutputLayout::hls(null));
        $argv = $transaction->rewriteArgv(['ffmpeg', $transaction->stagedOutputPath()]);
        $index = array_search('-hls_segment_filename', $argv, true);

        if (!is_int($index)) {
            throw new \LogicException('Missing generated HLS pattern');
        }

        $stagedSegment = sprintf($argv[$index + 1], 0);
        file_put_contents($stagedSegment, 'hls segment');
        file_put_contents($transaction->stagedOutputPath(), basename($stagedSegment) . "\n");
        $transaction->commit();

        Assert::true(is_file($stale));
        @unlink($stale);
    }
}
