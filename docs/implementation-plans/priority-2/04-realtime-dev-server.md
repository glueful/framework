# Real-Time Development Server Implementation Plan

> A comprehensive plan for enhancing the development server with file watching, colorized logging, performance metrics, and integrated services in Glueful Framework.

## Implementation Status: ✅ COMPLETE

**Implemented in:** v1.15.0 (Rigel)
**Released:** January 22, 2026

### What Was Implemented

| Component | Status | Location |
|-----------|--------|----------|
| FileWatcher Class | ✅ Complete | `src/Development/Watcher/FileWatcher.php` |
| RequestLogger Class | ✅ Complete | `src/Development/Logger/RequestLogger.php` |
| LogEntry Class | ✅ Complete | `src/Development/Logger/LogEntry.php` |
| Enhanced ServeCommand | ✅ Complete | `src/Console/Commands/ServeCommand.php` |

### Implementation Notes

The final implementation follows the design with these key features:
- Polling-based file watcher for cross-platform compatibility
- Colorized HTTP request logging with method, status, and timing
- Queue worker integration with `--queue` option
- Auto-port selection when port is in use
- Graceful shutdown handling with signal handlers

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Goals and Non-Goals](#goals-and-non-goals)
3. [Current State Analysis](#current-state-analysis)
4. [Architecture Design](#architecture-design)
5. [File Watcher](#file-watcher)
6. [Request Logger](#request-logger)
7. [Performance Metrics](#performance-metrics)
8. [Integrated Services](#integrated-services)
9. [Implementation Phases](#implementation-phases)
10. [Testing Strategy](#testing-strategy)
11. [API Reference](#api-reference)

---

## Executive Summary

This document outlines the enhancement of Glueful's development server (`php glueful serve`). The enhanced server will provide:

- **File watching** with auto-reload on code changes
- **Colorized request logging** with method, path, status, and timing
- **Performance metrics** per request (duration, memory)
- **Queue worker integration** for development
- **Browser auto-open** option
- **Port conflict detection** with auto-selection

The implementation enhances the existing `ServeCommand` with minimal external dependencies.

---

## Goals and Non-Goals

### Goals

- ✅ File watcher with configurable paths
- ✅ Colorized HTTP request/response logging
- ✅ Request timing and memory usage display
- ✅ Auto-restart on file changes
- ✅ Queue worker process management
- ✅ `--open` flag to launch browser
- ✅ Port availability checking
- ✅ Graceful shutdown handling

### Non-Goals

- ❌ Hot Module Replacement (HMR) - requires frontend bundler
- ❌ Live browser reload injection - use browser extensions
- ❌ Production server replacement
- ❌ Full-featured process manager (use Supervisor for production)
- ❌ WebSocket support in built-in server

---

## Current State Analysis

### Existing ServeCommand

```php
// Current: Basic PHP built-in server
#[AsCommand(name: 'serve', description: 'Start the development server')]
class ServeCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = $input->getOption('host') ?? 'localhost';
        $port = $input->getOption('port') ?? 8000;

        $output->writeln("<info>Starting server at http://{$host}:{$port}</info>");

        passthru("php -S {$host}:{$port} -t public");

        return 0;
    }
}
```

### Gap Analysis

| Gap | Solution |
|-----|----------|
| No file watching | File system watcher with restart |
| Basic output | Colorized request logger middleware |
| No timing info | Request timing wrapper |
| No queue worker | Parallel process management |
| Manual browser open | `--open` flag with browser launch |

---

## Architecture Design

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                   Development Server                             │
│                                                                 │
│  php glueful serve --watch --queue                              │
│        │                                                        │
│        ▼                                                        │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │              Process Manager                             │   │
│  │                                                          │   │
│  │   ┌──────────────┐  ┌──────────────┐  ┌─────────────┐  │   │
│  │   │ PHP Server   │  │ File Watcher │  │Queue Worker │  │   │
│  │   │ (primary)    │  │ (optional)   │  │ (optional)  │  │   │
│  │   └──────────────┘  └──────────────┘  └─────────────┘  │   │
│  │         │                  │                  │         │   │
│  │         │                  │                  │         │   │
│  │         ▼                  ▼                  ▼         │   │
│  │   ┌──────────────────────────────────────────────────┐ │   │
│  │   │              Output Aggregator                    │ │   │
│  │   │         (colorized, formatted)                   │ │   │
│  │   └──────────────────────────────────────────────────┘ │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Directory Structure

```
src/
├── Console/
│   └── Commands/
│       └── ServeCommand.php               # Enhanced serve command
│
├── Development/                           # 📋 NEW
│   ├── Server/
│   │   ├── DevelopmentServer.php          # Main server manager
│   │   ├── ProcessManager.php             # Child process management
│   │   └── ServerRouter.php               # Custom router script
│   │
│   ├── Watcher/
│   │   ├── FileWatcher.php                # File system watcher
│   │   ├── WatcherConfig.php              # Watcher configuration
│   │   └── Strategies/
│   │       ├── WatcherStrategy.php        # Strategy interface
│   │       ├── PollingStrategy.php        # Polling fallback
│   │       └── InotifyStrategy.php        # Linux inotify (if available)
│   │
│   └── Logger/
│       ├── RequestLogger.php              # HTTP request logger
│       ├── OutputFormatter.php            # Colorized output
│       └── LogEntry.php                   # Structured log entry
│
└── ...existing...
```

---

## File Watcher

### FileWatcher Class

```php
<?php

declare(strict_types=1);

namespace Glueful\Development\Watcher;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * File system watcher for development auto-reload
 *
 * Watches specified directories for file changes and triggers
 * callbacks when modifications are detected.
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
    ];

    /**
     * File modification times cache
     *
     * @var array<string, int>
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
     * Create a new file watcher
     *
     * @param array<string> $directories Directories to watch
     */
    public function __construct(array $directories = [])
    {
        $this->directories = $directories ?: [
            'app',
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
     * @param array<string> $extensions
     */
    public function setExtensions(array $extensions): self
    {
        $this->extensions = $extensions;
        return $this;
    }

    /**
     * Set patterns to ignore
     *
     * @param array<string> $patterns
     */
    public function setIgnore(array $patterns): self
    {
        $this->ignore = $patterns;
        return $this;
    }

    /**
     * Set polling interval
     */
    public function setInterval(int $milliseconds): self
    {
        $this->interval = $milliseconds * 1000;
        return $this;
    }

    /**
     * Initialize file hashes
     */
    public function initialize(): void
    {
        $this->fileHashes = [];

        foreach ($this->getWatchedFiles() as $file) {
            $this->fileHashes[$file] = $this->getFileHash($file);
        }

        $this->log("Watching " . count($this->fileHashes) . " files for changes...");
    }

    /**
     * Watch for changes and call callback when detected
     *
     * @param callable $onChange Callback when file changes detected
     */
    public function watch(callable $onChange): void
    {
        $this->running = true;
        $this->initialize();

        while ($this->running) {
            $changes = $this->checkForChanges();

            if (!empty($changes)) {
                $this->log("Change detected: " . implode(', ', array_keys($changes)));
                $onChange($changes);

                // Re-initialize after handling change
                $this->initialize();
            }

            usleep($this->interval);
        }
    }

    /**
     * Stop watching
     */
    public function stop(): void
    {
        $this->running = false;
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
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
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
        }

        return $files;
    }

    /**
     * Check if file should be ignored
     */
    private function shouldIgnore(string $path): bool
    {
        foreach ($this->ignore as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get file hash for change detection
     */
    private function getFileHash(string $file): string
    {
        // Use modification time + size for fast comparison
        $stat = stat($file);
        return $stat ? md5($stat['mtime'] . $stat['size']) : '';
    }

    /**
     * Log message to output
     */
    private function log(string $message): void
    {
        if ($this->output !== null) {
            $time = date('H:i:s');
            $this->output->writeln("<comment>[{$time}]</comment> {$message}");
        }
    }
}
```

---

## Request Logger

### RequestLogger Class

```php
<?php

declare(strict_types=1);

namespace Glueful\Development\Logger;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Colorized HTTP request logger for development
 */
class RequestLogger
{
    private OutputInterface $output;

    /**
     * Status code colors
     */
    private const STATUS_COLORS = [
        '2' => 'info',      // 2xx - green
        '3' => 'comment',   // 3xx - yellow
        '4' => 'error',     // 4xx - red
        '5' => 'error',     // 5xx - red
    ];

    /**
     * Method colors
     */
    private const METHOD_COLORS = [
        'GET' => 'info',
        'POST' => 'comment',
        'PUT' => 'comment',
        'PATCH' => 'comment',
        'DELETE' => 'error',
        'OPTIONS' => 'question',
        'HEAD' => 'question',
    ];

    /**
     * Create a new request logger
     */
    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * Log an HTTP request
     */
    public function log(LogEntry $entry): void
    {
        $time = $entry->timestamp->format('H:i:s');
        $method = $this->formatMethod($entry->method);
        $path = $this->formatPath($entry->path);
        $status = $this->formatStatus($entry->status);
        $duration = $this->formatDuration($entry->duration);
        $memory = $this->formatMemory($entry->memory);

        $line = sprintf(
            "<comment>[%s]</comment> %s %s %s %s %s",
            $time,
            $method,
            $path,
            $status,
            $duration,
            $memory
        );

        $this->output->writeln($line);
    }

    /**
     * Format HTTP method with color
     */
    private function formatMethod(string $method): string
    {
        $color = self::METHOD_COLORS[$method] ?? 'info';
        $padded = str_pad($method, 7);
        return "<{$color}>{$padded}</{$color}>";
    }

    /**
     * Format request path
     */
    private function formatPath(string $path): string
    {
        $maxLength = 50;

        if (strlen($path) > $maxLength) {
            $path = substr($path, 0, $maxLength - 3) . '...';
        }

        return str_pad($path, $maxLength);
    }

    /**
     * Format status code with color
     */
    private function formatStatus(int $status): string
    {
        $first = (string) floor($status / 100);
        $color = self::STATUS_COLORS[$first] ?? 'info';
        return "<{$color}>{$status}</{$color}>";
    }

    /**
     * Format duration in human readable format
     */
    private function formatDuration(float $milliseconds): string
    {
        $color = 'info';

        if ($milliseconds > 1000) {
            $color = 'error';
            $value = number_format($milliseconds / 1000, 2) . 's';
        } elseif ($milliseconds > 200) {
            $color = 'comment';
            $value = number_format($milliseconds, 0) . 'ms';
        } else {
            $value = number_format($milliseconds, 0) . 'ms';
        }

        return "<{$color}>" . str_pad($value, 8, ' ', STR_PAD_LEFT) . "</{$color}>";
    }

    /**
     * Format memory usage
     */
    private function formatMemory(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        $formatted = number_format($value, 1) . $units[$i];
        return "<comment>" . str_pad($formatted, 8, ' ', STR_PAD_LEFT) . "</comment>";
    }

    /**
     * Log server startup
     */
    public function logStartup(string $host, int $port, array $options = []): void
    {
        $this->output->writeln('');
        $this->output->writeln("<info>  Glueful Development Server</info>");
        $this->output->writeln('');
        $this->output->writeln("  <comment>Local:</comment>   http://{$host}:{$port}");

        if (isset($options['watch']) && $options['watch']) {
            $this->output->writeln("  <comment>Watch:</comment>   Enabled");
        }

        if (isset($options['queue']) && $options['queue']) {
            $this->output->writeln("  <comment>Queue:</comment>   Worker running");
        }

        $this->output->writeln('');
        $this->output->writeln("  Press <comment>Ctrl+C</comment> to stop");
        $this->output->writeln('');
    }

    /**
     * Log server restart
     */
    public function logRestart(string $reason = 'File changed'): void
    {
        $time = date('H:i:s');
        $this->output->writeln('');
        $this->output->writeln("<comment>[{$time}]</comment> <info>↻</info> Restarting server ({$reason})...");
        $this->output->writeln('');
    }
}
```

### LogEntry Class

```php
<?php

declare(strict_types=1);

namespace Glueful\Development\Logger;

/**
 * Structured log entry for HTTP requests
 */
class LogEntry
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly int $status,
        public readonly float $duration,
        public readonly int $memory,
        public readonly \DateTimeImmutable $timestamp,
    ) {
    }

    /**
     * Create from access log line
     */
    public static function fromAccessLog(string $line): ?self
    {
        // Parse PHP built-in server access log format
        // [timestamp] ip:port [status]: method path
        $pattern = '/\[([^\]]+)\]\s+(\S+)\s+\[(\d+)\]:\s+(\w+)\s+(.+)/';

        if (!preg_match($pattern, $line, $matches)) {
            return null;
        }

        return new self(
            method: $matches[4],
            path: trim($matches[5]),
            status: (int) $matches[3],
            duration: 0.0, // Not available in access log
            memory: 0,
            timestamp: new \DateTimeImmutable($matches[1])
        );
    }

    /**
     * Create with timing information
     */
    public static function create(
        string $method,
        string $path,
        int $status,
        float $duration,
        int $memory
    ): self {
        return new self(
            method: $method,
            path: $path,
            status: $status,
            duration: $duration,
            memory: $memory,
            timestamp: new \DateTimeImmutable()
        );
    }
}
```

---

## Development Server

### Enhanced ServeCommand

```php
<?php

declare(strict_types=1);

namespace Glueful\Console\Commands;

use Glueful\Console\BaseCommand;
use Glueful\Development\Watcher\FileWatcher;
use Glueful\Development\Logger\RequestLogger;
use Glueful\Development\Server\ProcessManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'serve',
    description: 'Start the development server with optional file watching'
)]
class ServeCommand extends BaseCommand
{
    private ?Process $serverProcess = null;
    private ?Process $queueProcess = null;
    private bool $shouldRestart = false;

    protected function configure(): void
    {
        $this->addOption('host', 'H', InputOption::VALUE_OPTIONAL, 'Host to bind to', 'localhost')
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Port to bind to', '8000')
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Watch for file changes and auto-restart')
            ->addOption('queue', 'q', InputOption::VALUE_NONE, 'Start queue worker alongside server')
            ->addOption('open', 'o', InputOption::VALUE_NONE, 'Open browser after starting')
            ->addOption('no-ansi', null, InputOption::VALUE_NONE, 'Disable ANSI output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = $input->getOption('host');
        $port = (int) $input->getOption('port');
        $watch = $input->getOption('watch');
        $queue = $input->getOption('queue');
        $open = $input->getOption('open');

        // Check port availability
        $port = $this->findAvailablePort($port, $output);

        $logger = new RequestLogger($output);
        $logger->logStartup($host, $port, [
            'watch' => $watch,
            'queue' => $queue,
        ]);

        // Handle shutdown signals
        $this->setupSignalHandlers();

        // Open browser if requested
        if ($open) {
            $this->openBrowser("http://{$host}:{$port}");
        }

        // Start queue worker if requested
        if ($queue) {
            $this->startQueueWorker($output);
        }

        // Start with or without file watching
        if ($watch) {
            $this->runWithWatcher($host, $port, $output, $logger);
        } else {
            $this->runServer($host, $port, $output);
        }

        return 0;
    }

    /**
     * Run server with file watcher
     */
    private function runWithWatcher(
        string $host,
        int $port,
        OutputInterface $output,
        RequestLogger $logger
    ): void {
        $watcher = new FileWatcher(['app', 'src', 'config', 'routes']);
        $watcher->setOutput($output);

        while (true) {
            // Start server process
            $this->startServerProcess($host, $port, $output);

            // Watch for changes
            $watcher->watch(function (array $changes) use ($logger) {
                $logger->logRestart(array_key_first($changes));
                $this->shouldRestart = true;
                $this->stopServerProcess();
            });

            if (!$this->shouldRestart) {
                break;
            }

            $this->shouldRestart = false;
        }
    }

    /**
     * Run server without file watching
     */
    private function runServer(string $host, int $port, OutputInterface $output): void
    {
        $this->startServerProcess($host, $port, $output);

        // Wait for process to exit
        $this->serverProcess->wait(function ($type, $buffer) use ($output) {
            $output->write($buffer);
        });
    }

    /**
     * Start the PHP built-in server process
     */
    private function startServerProcess(string $host, int $port, OutputInterface $output): void
    {
        $routerPath = $this->getRouterScript();

        $this->serverProcess = new Process([
            PHP_BINARY,
            '-S', "{$host}:{$port}",
            '-t', 'public',
            $routerPath,
        ]);

        $this->serverProcess->setTimeout(null);
        $this->serverProcess->start(function ($type, $buffer) use ($output) {
            // Parse and format access log lines
            foreach (explode("\n", trim($buffer)) as $line) {
                if (!empty($line)) {
                    $output->writeln($this->formatAccessLog($line));
                }
            }
        });
    }

    /**
     * Stop the server process
     */
    private function stopServerProcess(): void
    {
        if ($this->serverProcess !== null && $this->serverProcess->isRunning()) {
            $this->serverProcess->stop(3);
        }
    }

    /**
     * Start queue worker process
     */
    private function startQueueWorker(OutputInterface $output): void
    {
        $this->queueProcess = new Process([
            PHP_BINARY,
            'glueful',
            'queue:work',
            '--once', // Process one job at a time for dev
        ]);

        $this->queueProcess->setTimeout(null);
        $this->queueProcess->start(function ($type, $buffer) use ($output) {
            $output->write("<comment>[Queue]</comment> {$buffer}");
        });
    }

    /**
     * Find an available port
     */
    private function findAvailablePort(int $preferredPort, OutputInterface $output): int
    {
        $port = $preferredPort;
        $maxAttempts = 10;

        while (!$this->isPortAvailable($port) && $maxAttempts > 0) {
            $output->writeln("<comment>Port {$port} is in use, trying {$port + 1}...</comment>");
            $port++;
            $maxAttempts--;
        }

        if ($maxAttempts === 0) {
            throw new \RuntimeException("Could not find available port after trying {$preferredPort} to {$port}");
        }

        return $port;
    }

    /**
     * Check if port is available
     */
    private function isPortAvailable(int $port): bool
    {
        $socket = @fsockopen('localhost', $port, $errno, $errstr, 1);

        if ($socket !== false) {
            fclose($socket);
            return false;
        }

        return true;
    }

    /**
     * Open browser to URL
     */
    private function openBrowser(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        exec("{$command} {$url} > /dev/null 2>&1 &");
    }

    /**
     * Setup signal handlers for graceful shutdown
     */
    private function setupSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () {
                $this->shutdown();
            });

            pcntl_signal(SIGTERM, function () {
                $this->shutdown();
            });
        }
    }

    /**
     * Graceful shutdown
     */
    private function shutdown(): void
    {
        $this->stopServerProcess();

        if ($this->queueProcess !== null && $this->queueProcess->isRunning()) {
            $this->queueProcess->stop(3);
        }

        exit(0);
    }

    /**
     * Get the custom router script path
     */
    private function getRouterScript(): string
    {
        $routerPath = __DIR__ . '/../../Development/Server/router.php';

        // Fallback to no router (use public/index.php directly)
        if (!file_exists($routerPath)) {
            return '';
        }

        return $routerPath;
    }

    /**
     * Format access log line with colors
     */
    private function formatAccessLog(string $line): string
    {
        // Parse: [timestamp] ip [status]: METHOD /path
        if (preg_match('/\[([^\]]+)\]\s+\S+\s+\[(\d+)\]:\s+(\w+)\s+(.+)/', $line, $m)) {
            $time = date('H:i:s', strtotime($m[1]));
            $status = (int) $m[2];
            $method = str_pad($m[3], 7);
            $path = $m[4];

            $statusColor = $status < 400 ? ($status < 300 ? 'info' : 'comment') : 'error';
            $methodColor = in_array($m[3], ['POST', 'PUT', 'PATCH', 'DELETE']) ? 'comment' : 'info';

            return sprintf(
                "<comment>[%s]</comment> <{$methodColor}>%s</{$methodColor}> %s <{$statusColor}>%d</{$statusColor}>",
                $time,
                $method,
                $path,
                $status
            );
        }

        return $line;
    }
}
```

---

## Implementation Phases

### Phase 1: Enhanced Logging ✅ COMPLETE

**Deliverables:**
- [x] `RequestLogger` with colorized output
- [x] `LogEntry` data class
- [x] Enhanced `ServeCommand` with better output
- [x] Port availability checking

**Acceptance Criteria:**
```bash
$ php glueful serve

  Glueful Development Server

  Local:   http://localhost:8000

  Press Ctrl+C to stop

[14:23:15] GET     /api/users                                  200    45ms    2.3MB
[14:23:16] POST    /api/users                                  201   123ms    4.1MB
[14:23:17] GET     /api/users/123                              404    12ms    1.8MB
```

### Phase 2: File Watcher ✅ COMPLETE

**Deliverables:**
- [x] `FileWatcher` class
- [x] Polling strategy (cross-platform)
- [x] Configurable paths and extensions
- [x] Auto-restart on changes

**Acceptance Criteria:**
```bash
$ php glueful serve --watch

  Glueful Development Server

  Local:   http://localhost:8000
  Watch:   Enabled

[14:23:15] Watching 342 files for changes...
[14:23:45] Change detected: app/Http/Controllers/UserController.php
[14:23:45] ↻ Restarting server (File changed)...
```

### Phase 3: Integrated Services ✅ COMPLETE

**Deliverables:**
- [x] Queue worker integration (`--queue`)
- [x] Browser auto-open (`--open`)
- [x] Signal handling (Ctrl+C)
- [x] Documentation

**Acceptance Criteria:**
```bash
$ php glueful serve --watch --queue --open

  Glueful Development Server

  Local:   http://localhost:8000
  Watch:   Enabled
  Queue:   Worker running

[14:23:15] Watching 342 files for changes...
[Queue] Processing job: SendWelcomeEmail
```

---

## Testing Strategy

### Unit Tests

```php
<?php

namespace Glueful\Tests\Unit\Development\Watcher;

use PHPUnit\Framework\TestCase;
use Glueful\Development\Watcher\FileWatcher;

class FileWatcherTest extends TestCase
{
    public function testDetectsNewFiles(): void
    {
        // Create temp directory, start watching, create file, verify detection
    }

    public function testDetectsModifiedFiles(): void
    {
        // Modify existing file, verify detection
    }

    public function testIgnoresPatterns(): void
    {
        // Verify vendor/* is ignored
    }

    public function testRespectsExtensionFilter(): void
    {
        // Only .php files should be watched by default
    }
}
```

---

## API Reference

### serve Command Options

| Option | Short | Description | Default |
|--------|-------|-------------|---------|
| `--host` | `-H` | Host to bind to | `localhost` |
| `--port` | `-p` | Port to bind to | `8000` |
| `--watch` | `-w` | Watch files and auto-restart | `false` |
| `--queue` | `-q` | Start queue worker | `false` |
| `--open` | `-o` | Open browser | `false` |
| `--no-ansi` | - | Disable colors | `false` |

### FileWatcher Methods

| Method | Description |
|--------|-------------|
| `setOutput($output)` | Set console output |
| `setExtensions($exts)` | Set file extensions to watch |
| `setIgnore($patterns)` | Set patterns to ignore |
| `setInterval($ms)` | Set polling interval |
| `initialize()` | Initialize file hashes |
| `watch($callback)` | Start watching with callback |
| `stop()` | Stop watching |

### RequestLogger Methods

| Method | Description |
|--------|-------------|
| `log($entry)` | Log a request entry |
| `logStartup($host, $port, $options)` | Log server startup |
| `logRestart($reason)` | Log server restart |

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `DEV_SERVER_POLL_INTERVAL` | File watcher poll interval (ms) | `500` |
| `DEV_SERVER_WATCH_DIRS` | Comma-separated watch directories | `app,src,config,routes` |
| `DEV_SERVER_EXTENSIONS` | File extensions to watch | `php,env` |
