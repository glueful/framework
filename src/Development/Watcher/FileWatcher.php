<?php

declare(strict_types=1);

namespace Glueful\Development\Watcher;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * File system watcher for development auto-reload
 *
 * Watches specified directories for file changes and triggers
 * callbacks when modifications are detected. Uses polling strategy
 * for cross-platform compatibility.
 *
 * @example
 * $watcher = new FileWatcher(['src', 'config']);
 * $watcher->setOutput($output);
 * $watcher->watch(function (array $changes) {
 *     // Handle file changes
 * });
 *
 * @package Glueful\Development\Watcher
 */
class FileWatcher
{
    /**
     * Directories to watch
     *
     * @var array<string>
     */
    private array $directories = [];

    /**
     * File extensions to watch
     *
     * @var array<string>
     */
    private array $extensions = ['php', 'env'];

    /**
     * Patterns to ignore
     *
     * @var array<string>
     */
    private array $ignore = [
        'vendor/*',
        'node_modules/*',
        'storage/*',
        '.git/*',
        '*.log',
        '*.cache',
    ];

    /**
     * File modification times cache
     *
     * @var array<string, string>
     */
    private array $fileHashes = [];

    /**
     * Poll interval in microseconds
     */
    private int $interval = 500000; // 500ms

    /**
     * Console output
     */
    private ?OutputInterface $output = null;

    /**
     * Whether watcher is running
     */
    private bool $running = false;

    /**
     * Base path for resolving directories
     */
    private string $basePath;

    /**
     * Create a new file watcher
     *
     * @param array<string> $directories Directories to watch (relative to base path)
     * @param string|null $basePath Base path for directory resolution
     */
    public function __construct(array $directories = [], ?string $basePath = null)
    {
        $this->basePath = $basePath ?? getcwd() ?: '';
        $this->directories = $directories ?: [
            'api',
            'src',
            'config',
            'routes',
        ];
    }

    /**
     * Set output for logging
     */
    public function setOutput(OutputInterface $output): self
    {
        $this->output = $output;
        return $this;
    }

    /**
     * Set file extensions to watch
     *
     * @param array<string> $extensions File extensions without dot
     */
    public function setExtensions(array $extensions): self
    {
        $this->extensions = $extensions;
        return $this;
    }

    /**
     * Add extensions to watch
     *
     * @param array<string> $extensions Additional extensions
     */
    public function addExtensions(array $extensions): self
    {
        $this->extensions = array_unique(array_merge($this->extensions, $extensions));
        return $this;
    }

    /**
     * Set patterns to ignore
     *
     * @param array<string> $patterns Glob patterns to ignore
     */
    public function setIgnore(array $patterns): self
    {
        $this->ignore = $patterns;
        return $this;
    }

    /**
     * Add patterns to ignore
     *
     * @param array<string> $patterns Additional patterns
     */
    public function addIgnore(array $patterns): self
    {
        $this->ignore = array_unique(array_merge($this->ignore, $patterns));
        return $this;
    }

    /**
     * Set polling interval
     *
     * @param int $milliseconds Polling interval in milliseconds
     */
    public function setInterval(int $milliseconds): self
    {
        $this->interval = $milliseconds * 1000;
        return $this;
    }

    /**
     * Add directories to watch
     *
     * @param array<string> $directories Additional directories
     */
    public function addDirectories(array $directories): self
    {
        $this->directories = array_unique(array_merge($this->directories, $directories));
        return $this;
    }

    /**
     * Initialize file hashes for change detection
     */
    public function initialize(): void
    {
        $this->fileHashes = [];

        foreach ($this->getWatchedFiles() as $file) {
            $this->fileHashes[$file] = $this->getFileHash($file);
        }

        $this->log(
            sprintf('Watching <info>%d</info> files for changes...', count($this->fileHashes)),
            'comment'
        );
    }

    /**
     * Watch for changes and call callback when detected
     *
     * @param callable $onChange Callback when file changes detected: fn(array<string, string> $changes)
     */
    public function watch(callable $onChange): void
    {
        $this->running = true;
        $this->initialize();

        while ($this->running) {
            $changes = $this->checkForChanges();

            if ($changes !== []) {
                $changedFiles = array_keys($changes);
                $firstFile = $this->getRelativePath($changedFiles[0]);
                $changeType = $changes[$changedFiles[0]];

                $this->log(
                    sprintf('File %s: <info>%s</info>', $changeType, $firstFile),
                    'comment'
                );

                $onChange($changes);

                // Re-initialize after handling change
                $this->initialize();
            }

            usleep($this->interval);

            // Allow signal handling
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Check for changes once without looping
     *
     * @return array<string, string> Changed files with change type (created, modified, deleted)
     */
    public function checkOnce(): array
    {
        if ($this->fileHashes === []) {
            $this->initialize();
        }

        return $this->checkForChanges();
    }

    /**
     * Stop watching
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Check if watcher is running
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Get the number of watched files
     */
    public function getWatchedFileCount(): int
    {
        return count($this->fileHashes);
    }

    /**
     * Get list of watched directories
     *
     * @return array<string>
     */
    public function getDirectories(): array
    {
        return $this->directories;
    }

    /**
     * Check for file changes
     *
     * @return array<string, string> Changed files with change type
     */
    private function checkForChanges(): array
    {
        $changes = [];
        $currentFiles = $this->getWatchedFiles();

        // Check for modified and deleted files
        foreach ($this->fileHashes as $file => $hash) {
            if (!file_exists($file)) {
                $changes[$file] = 'deleted';
                continue;
            }

            $newHash = $this->getFileHash($file);
            if ($newHash !== $hash) {
                $changes[$file] = 'modified';
            }
        }

        // Check for new files
        foreach ($currentFiles as $file) {
            if (!isset($this->fileHashes[$file])) {
                $changes[$file] = 'created';
            }
        }

        return $changes;
    }

    /**
     * Get all files to watch
     *
     * @return array<string>
     */
    private function getWatchedFiles(): array
    {
        $files = [];

        foreach ($this->directories as $directory) {
            $fullPath = $this->resolvePath($directory);

            if (!is_dir($fullPath)) {
                continue;
            }

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $fullPath,
                        \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
                    ),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($iterator as $file) {
                    if ($file->isDir()) {
                        continue;
                    }

                    $path = $file->getPathname();

                    // Check extension
                    $ext = pathinfo($path, PATHINFO_EXTENSION);
                    if (!in_array($ext, $this->extensions, true)) {
                        continue;
                    }

                    // Check ignore patterns
                    if ($this->shouldIgnore($path)) {
                        continue;
                    }

                    $files[] = $path;
                }
            } catch (\UnexpectedValueException) {
                // Directory access error, skip it
                continue;
            }
        }

        return $files;
    }

    /**
     * Check if file should be ignored
     */
    private function shouldIgnore(string $path): bool
    {
        $relativePath = $this->getRelativePath($path);

        foreach ($this->ignore as $pattern) {
            if (fnmatch($pattern, $relativePath) || fnmatch($pattern, basename($path))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get file hash for change detection
     *
     * Uses modification time + size for fast comparison
     */
    private function getFileHash(string $file): string
    {
        $stat = @stat($file);
        if ($stat === false) {
            return '';
        }

        return md5($stat['mtime'] . ':' . $stat['size']);
    }

    /**
     * Resolve path relative to base path
     */
    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($this->basePath, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Get path relative to base path for display
     */
    private function getRelativePath(string $path): string
    {
        $basePath = rtrim($this->basePath, '/') . '/';

        if (str_starts_with($path, $basePath)) {
            return substr($path, strlen($basePath));
        }

        return $path;
    }

    /**
     * Log message to output
     */
    private function log(string $message, string $style = 'info'): void
    {
        if ($this->output === null) {
            return;
        }

        $time = date('H:i:s');
        $this->output->writeln("<comment>[{$time}]</comment> <{$style}>{$message}</{$style}>");
    }
}
