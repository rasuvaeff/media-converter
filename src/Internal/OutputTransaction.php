<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Internal;

use Rasuvaeff\MediaConverter\ConversionFailed;
use Rasuvaeff\MediaConverter\ConversionFailureReason;

/**
 * Isolates every ffmpeg attempt in a staging directory beside the destination.
 * Existing destination files remain untouched until a successful commit.
 *
 * @internal
 */
final class OutputTransaction
{
    private readonly string $stagingDirectory;

    private readonly string $stagedOutputPath;

    private readonly string $generationId;

    private ?string $customHlsTargetDirectory = null;

    private ?string $customHlsPlaylistPrefix = null;

    /** @var list<string> */
    private array $committedArtifacts = [];

    /** @var array<string, string> target => backup */
    private array $backups = [];

    /** @var resource|null */
    private mixed $lockHandle = null;

    public function __construct(
        private readonly string $outputPath,
        private readonly OutputLayout $layout,
    ) {
        if ($outputPath === '') {
            throw new \InvalidArgumentException('Output path cannot be empty');
        }

        $outputDirectory = dirname($outputPath);

        if (!is_dir($outputDirectory)) {
            throw $this->failure(sprintf('Output directory does not exist: %s', $outputDirectory));
        }

        $lockPath = $outputDirectory . '/.' . basename($outputPath) . '.media-converter.lock';
        $lockHandle = @fopen($lockPath, 'c');

        if ($lockHandle === false) {
            throw $this->failure(sprintf('Cannot lock output destination: %s', $outputPath));
        }

        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);

            throw $this->failure(sprintf('Cannot lock output destination: %s', $outputPath));
        }

        $this->lockHandle = $lockHandle;

        $this->configureCustomHlsTarget();
        $this->generationId = bin2hex(random_bytes(8));
        $this->stagingDirectory = $outputDirectory . '/.media-converter-' . $this->generationId;

        if (!mkdir($this->stagingDirectory, 0o700)) {
            throw $this->failure(sprintf('Cannot create output staging directory: %s', $this->stagingDirectory));
        }

        $this->stagedOutputPath = $this->stagingDirectory . '/' . basename($outputPath);
    }

    public function stagedOutputPath(): string
    {
        return $this->stagedOutputPath;
    }

    /**
     * @param list<string> $argv
     *
     * @return list<string>
     */
    public function rewriteArgv(array $argv): array
    {
        if ($this->layout->isDash()) {
            return $this->rewriteDashArgv($argv);
        }

        if (!$this->layout->isHls()) {
            return $argv;
        }

        $pattern = $this->layout->hlsSegmentPattern();

        if ($pattern === null) {
            $extensionless = pathinfo($this->outputPath, PATHINFO_FILENAME);

            return $this->insertBeforeOutput($argv, [
                '-hls_segment_filename',
                sprintf('%s/%s-%s-%%05d.ts', $this->stagingDirectory, $extensionless, $this->generationId),
            ]);
        }

        $index = null;

        foreach ($argv as $position => $argument) {
            if ($argument === '-hls_segment_filename') {
                $index = $position;

                break;
            }
        }

        if ($index === null) {
            throw new \LogicException('HLS segment pattern is absent from rendered argv');
        }

        if (!isset($argv[$index + 1])) {
            throw new \LogicException('HLS segment pattern is absent from rendered argv');
        }

        $argv[$index + 1] = $this->stagingDirectory . '/' . basename($pattern);

        return array_values($argv);
    }

    public function reset(): void
    {
        foreach ($this->stagedFiles() as $file) {
            @unlink($file);
        }
    }

    /**
     * @return non-empty-list<string>
     */
    public function commit(): array
    {
        try {
            $this->rewriteCustomHlsPlaylist();
            $files = $this->stagedFiles();

            if (!in_array($this->stagedOutputPath, $files, true)) {
                throw $this->failure('ffmpeg reported success but did not create the output file');
            }

            /** @var array<string, string> $targets staged => final */
            $targets = [];

            foreach ($files as $file) {
                $targets[$file] = $this->targetFor($file);
            }

            $this->backupExistingTargets($targets);

            uksort(
                $targets,
                fn(string $left, string $right): int => ($left === $this->stagedOutputPath ? 1 : 0) <=> ($right === $this->stagedOutputPath ? 1 : 0),
            );

            $oldArtifacts = $this->readPreviousArtifacts();

            foreach ($targets as $file => $target) {

                if (!@rename($file, $target)) {
                    throw $this->failure(sprintf('Cannot move output artifact to its destination: %s', $target));
                }

                $this->committedArtifacts[] = $target;
            }

            $this->commitInventory();
            $this->removeObsoleteArtifacts($oldArtifacts);
            $this->discardBackups();
            @rmdir($this->stagingDirectory);
            usort(
                $this->committedArtifacts,
                fn(string $left, string $right): int => ($left === $this->outputPath ? -1 : 0) <=> ($right === $this->outputPath ? -1 : 0),
            );

            if ($this->committedArtifacts === []) {
                throw new \LogicException('Committed output artifact list cannot be empty');
            }

            return $this->committedArtifacts;
        } finally {
            $this->releaseLock();
        }
    }

    public function rollback(): void
    {
        try {
            foreach ($this->committedArtifacts as $artifact) {
                @unlink($artifact);
            }

            foreach ($this->backups as $target => $backup) {
                if (is_file($target)) {
                    @unlink($backup);

                    continue;
                }

                @rename($backup, $target);
            }

            $this->committedArtifacts = [];
            $this->backups = [];
            $this->reset();
            @rmdir($this->stagingDirectory . '/backups');
            @rmdir($this->stagingDirectory);
        } finally {
            $this->releaseLock();
        }
    }

    private function configureCustomHlsTarget(): void
    {
        $pattern = $this->layout->hlsSegmentPattern();

        if (!$this->layout->isHls() || $pattern === null) {
            return;
        }

        $directory = dirname($pattern);

        if ($directory === '.') {
            $this->customHlsTargetDirectory = getcwd() ?: '.';
            $this->customHlsPlaylistPrefix = '';

            return;
        }

        if (!self::isAbsolutePath($directory)) {
            $directory = (getcwd() ?: '.') . '/' . $directory;
        }

        if (!is_dir($directory)) {
            throw $this->failure(sprintf('HLS segment directory does not exist: %s', $directory));
        }

        $this->customHlsTargetDirectory = $directory;
        $this->customHlsPlaylistPrefix = rtrim(dirname($pattern), '/') . '/';
    }

    /**
     * `dirname()` returns a native-separator path on every platform, so a
     * leading `/` alone (POSIX) is not enough: Windows absolute paths start
     * with a drive letter (`C:\` or `C:/`) or a UNC/rooted `\`.
     */
    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (bool) preg_match('#^[A-Za-z]:[/\\\\]#', $path);
    }

    private function rewriteCustomHlsPlaylist(): void
    {
        if ($this->customHlsTargetDirectory === null || !is_file($this->stagedOutputPath)) {
            return;
        }

        $playlist = file_get_contents($this->stagedOutputPath);

        if ($playlist === false) {
            throw $this->failure('Cannot read the staged HLS playlist');
        }

        $rewritten = str_replace(
            $this->stagingDirectory . '/',
            $this->customHlsPlaylistPrefix ?? '',
            $playlist,
        );

        if (file_put_contents($this->stagedOutputPath, $rewritten) === false) {
            throw $this->failure('Cannot rewrite the staged HLS playlist');
        }
    }

    private function targetFor(string $stagedFile): string
    {
        if ($stagedFile === $this->stagedOutputPath) {
            return $this->outputPath;
        }

        $directory = $this->customHlsTargetDirectory ?? dirname($this->outputPath);

        return $directory . '/' . basename($stagedFile);
    }

    /**
     * @param list<string> $argv
     *
     * @return list<string>
     */
    private function rewriteDashArgv(array $argv): array
    {
        $stem = pathinfo($this->outputPath, PATHINFO_FILENAME) . '-' . $this->generationId;

        return $this->insertBeforeOutput($argv, [
            '-init_seg_name', $stem . '-init-$RepresentationID$.m4s',
            '-media_seg_name', $stem . '-chunk-$RepresentationID$-$Number%05d$.m4s',
        ]);
    }

    /**
     * @param list<string> $argv
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    private function insertBeforeOutput(array $argv, array $arguments): array
    {
        array_splice($argv, -1, 0, $arguments);

        return $argv;
    }

    /** @return list<string> */
    private function readPreviousArtifacts(): array
    {
        if (!$this->usesManagedGeneration()) {
            return [];
        }

        $contents = @file_get_contents($this->inventoryPath());

        if ($contents === false) {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return array_reduce(
            $decoded,
            /**
             * @param list<string> $artifacts
             *
             * @return list<string>
             */
            static function (array $artifacts, mixed $artifact): array {
                if (is_string($artifact)) {
                    $artifacts[] = $artifact;
                }

                return $artifacts;
            },
            [],
        );
    }

    private function commitInventory(): void
    {
        if (!$this->usesManagedGeneration()) {
            return;
        }

        $sidecars = array_values(array_filter(
            $this->committedArtifacts,
            fn(string $artifact): bool => $artifact !== $this->outputPath,
        ));
        $json = json_encode($sidecars, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $temporary = $this->stagingDirectory . '/inventory.json';

        if (file_put_contents($temporary, $json . "\n") === false || !@rename($temporary, $this->inventoryPath())) {
            throw $this->failure('Cannot commit the output artifact inventory');
        }
    }

    /** @param list<string> $oldArtifacts */
    private function removeObsoleteArtifacts(array $oldArtifacts): void
    {
        // Only a managed-generation layout may sweep: a plain (or custom-
        // pattern) conversion sharing the directory and filename stem with a
        // managed HLS/DASH destination must never delete its live segments.
        if (!$this->usesManagedGeneration()) {
            return;
        }

        $matches = glob(dirname($this->outputPath) . '/*');
        $directoryArtifacts = $matches === false ? [] : $matches;
        $candidates = array_values(array_unique([...$oldArtifacts, ...$directoryArtifacts]));

        foreach (array_diff($candidates, $this->committedArtifacts) as $artifact) {
            if ($this->isManagedArtifact($artifact)) {
                @unlink($artifact);
            }
        }
    }

    private function inventoryPath(): string
    {
        return dirname($this->outputPath) . '/.' . basename($this->outputPath) . '.media-converter.json';
    }

    private function usesManagedGeneration(): bool
    {
        return $this->layout->isDash()
            || ($this->layout->isHls() && $this->layout->hlsSegmentPattern() === null);
    }

    private function isManagedArtifact(string $artifact): bool
    {
        if (dirname($artifact) !== dirname($this->outputPath)) {
            return false;
        }

        $stem = preg_quote(pathinfo($this->outputPath, PATHINFO_FILENAME), '/');
        // Extension is layout-specific: an HLS destination must not reap a
        // same-stem DASH destination's .m4s segments, and vice versa.
        $extension = $this->layout->isDash() ? 'm4s' : 'ts';

        return preg_match(sprintf('/^%s-[a-f0-9]{16}-.+\.%s\z/', $stem, $extension), basename($artifact)) === 1;
    }

    /** @param array<string, string> $targets */
    private function backupExistingTargets(array $targets): void
    {
        $backupDirectory = $this->stagingDirectory . '/backups';

        foreach (array_values($targets) as $index => $target) {
            if (!is_file($target)) {
                continue;
            }

            if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0o700)) {
                throw $this->failure('Cannot create output backup directory');
            }

            $backup = $backupDirectory . '/' . $index;

            if (!@copy($target, $backup)) {
                throw $this->failure(sprintf('Cannot protect existing output artifact: %s', $target));
            }

            $this->backups[$target] = $backup;
        }
    }

    private function discardBackups(): void
    {
        foreach ($this->backups as $backup) {
            @unlink($backup);
        }

        $this->backups = [];
        @rmdir($this->stagingDirectory . '/backups');
    }

    /**
     * @return list<string>
     */
    private function stagedFiles(): array
    {
        $matches = glob($this->stagingDirectory . '/*');
        $files = $matches === false ? [] : $matches;
        $files = array_values(array_filter($files, is_file(...)));
        sort($files);

        return $files;
    }

    private function failure(string $message): ConversionFailed
    {
        return new ConversionFailed(
            reason: ConversionFailureReason::OutputFailed,
            exitCode: 0,
            stderr: $message,
        );
    }

    public function __destruct()
    {
        $this->releaseLock();
    }

    private function releaseLock(): void
    {
        if (is_resource($this->lockHandle)) {
            $lockHandle = $this->lockHandle;
            $this->lockHandle = null;
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}
