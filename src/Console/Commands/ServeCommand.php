<?php

namespace Glueful\Console\Commands;

use Glueful\Console\BaseCommand;
use Glueful\Development\Watcher\FileWatcher;
use Glueful\Development\Logger\RequestLogger;
use Glueful\Development\Logger\LogEntry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Development Server Command
 *
 * Starts a local development server with enhanced features:
 * - File watching with auto-restart on code changes
 * - Colorized request logging with timing
 * - Port availability checking with auto-selection
 * - Queue worker integration
 * - Browser auto-open
 * - Graceful shutdown handling
 *
 * @package Glueful\Console\Commands
 */
#[AsCommand(
    name: 'serve',
    description: 'Start the Glueful development server'
)]
class ServeCommand extends BaseCommand
{
    /**
     * Server process
     */
    private ?Process $serverProcess = null;

    /**
     * Queue worker process
     */
    private ?Process $queueProcess = null;

    /**
     * File watcher instance
     */
    private ?FileWatcher $watcher = null;

    /**
     * Request logger instance
     */
    private ?RequestLogger $logger = null;

    /**
     * Flag indicating shutdown requested
     */
    private bool $shutdownRequested = false;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Start the Glueful development server')
             ->setHelp($this->getDetailedHelp())
             ->addOption(
                 'port',
                 'p',
                 InputOption::VALUE_REQUIRED,
                 'Port to run the server on',
                 '8000'
             )
             ->addOption(
                 'host',
                 'H',
                 InputOption::VALUE_REQUIRED,
                 'Host to bind the server to',
                 'localhost'
             )
             ->addOption(
                 'watch',
                 'w',
                 InputOption::VALUE_NONE,
                 'Watch for file changes and auto-restart'
             )
             ->addOption(
                 'queue',
                 'q',
                 InputOption::VALUE_NONE,
                 'Start queue worker alongside server'
             )
             ->addOption(
                 'open',
                 'o',
                 InputOption::VALUE_NONE,
                 'Open the server URL in default browser'
             )
             ->addOption(
                 'poll-interval',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'File watcher poll interval in milliseconds',
                 '500'
             )
             ->addOption(
                 'no-color',
                 null,
                 InputOption::VALUE_NONE,
                 'Disable colorized output'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $port = (int) $input->getOption('port');
        $host = (string) $input->getOption('host');
        $watch = (bool) $input->getOption('watch');
        $queue = (bool) $input->getOption('queue');
        $openBrowser = (bool) $input->getOption('open');
        $pollInterval = (int) $input->getOption('poll-interval');

        // Validate port
        if (!$this->isValidPort($port)) {
            $this->error('Invalid port number. Must be between 1 and 65535.');
            return self::FAILURE;
        }

        // Find available port
        $port = $this->findAvailablePort($host, $port, $output);
        if ($port === null) {
            return self::FAILURE;
        }

        // Get public directory
        $publicDir = base_path('public');
        if (!is_dir($publicDir)) {
            $this->error('Public directory not found. Expected: ' . $publicDir);
            return self::FAILURE;
        }

        // Set environment to development
        putenv('APP_ENV=development');

        // Initialize request logger
        $this->logger = new RequestLogger($output);

        // Display startup information
        $this->logger->logStartup($host, $port, [
            'watch' => $watch,
            'queue' => $queue,
        ]);

        // Setup signal handlers for graceful shutdown
        $this->setupSignalHandlers();

        // Open browser if requested
        if ($openBrowser) {
            $this->openBrowser($host, $port);
        }

        // Start queue worker if requested
        if ($queue) {
            $this->startQueueWorker();
        }

        // Start server with or without file watching
        if ($watch) {
            return $this->runWithWatcher($host, $port, $publicDir, $pollInterval);
        }

        return $this->runServer($host, $port, $publicDir);
    }

    /**
     * Run server with file watcher
     */
    private function runWithWatcher(
        string $host,
        int $port,
        string $publicDir,
        int $pollInterval
    ): int {
        $basePath = dirname($publicDir);

        $this->watcher = new FileWatcher(['api', 'src', 'config', 'routes'], $basePath);
        $this->watcher->setOutput($this->output);
        $this->watcher->setInterval($pollInterval);
        $this->watcher->addExtensions(['php', 'env', 'json', 'yaml', 'yml']);

        while (!$this->shutdownRequested) {
            // Start server process
            $this->startServerProcess($host, $port, $publicDir);

            // Initialize watcher
            $this->watcher->initialize();

            // Monitor for changes while server is running
            while (
                $this->serverProcess !== null
                && $this->serverProcess->isRunning()
                && !$this->shutdownRequested
            ) {
                // Check for file changes
                $changes = $this->watcher->checkOnce();

                if ($changes !== []) {
                    $firstFile = array_key_first($changes);
                    $relativePath = str_replace($basePath . '/', '', $firstFile);
                    $this->logger?->logRestart($relativePath);
                    $this->stopServerProcess();
                    break;
                }

                // Process server output
                $this->processServerOutput();

                // Small sleep to prevent CPU spinning
                usleep($pollInterval * 1000);

                // Handle signals
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }

            if ($this->shutdownRequested) {
                break;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Run server without file watching
     */
    private function runServer(string $host, int $port, string $publicDir): int
    {
        $this->startServerProcess($host, $port, $publicDir);

        // Wait for process to exit
        if ($this->serverProcess !== null) {
            $this->serverProcess->wait(function (string $_type, string $buffer) {
                $this->handleServerOutput($buffer);
            });

            return $this->serverProcess->getExitCode() ?? self::SUCCESS;
        }

        return self::SUCCESS;
    }

    /**
     * Start the PHP built-in server process
     */
    private function startServerProcess(string $host, int $port, string $publicDir): void
    {
        $routerScript = $this->findRouterScript($publicDir);

        $command = [
            PHP_BINARY,
            '-S',
            "{$host}:{$port}",
            '-t',
            $publicDir,
        ];

        if ($routerScript !== null) {
            $command[] = $routerScript;
        }

        $this->serverProcess = new Process($command);
        $this->serverProcess->setTimeout(null);
        $this->serverProcess->start();
    }

    /**
     * Process server output in non-blocking mode
     */
    private function processServerOutput(): void
    {
        if ($this->serverProcess === null) {
            return;
        }

        $output = $this->serverProcess->getIncrementalOutput();
        $errorOutput = $this->serverProcess->getIncrementalErrorOutput();

        if ($output !== '') {
            $this->handleServerOutput($output);
        }

        if ($errorOutput !== '') {
            $this->handleServerOutput($errorOutput);
        }
    }

    /**
     * Handle server output and format it
     */
    private function handleServerOutput(string $buffer): void
    {
        foreach (explode("\n", trim($buffer)) as $line) {
            if ($line === '') {
                continue;
            }

            // Skip PHP built-in server startup messages
            if (str_contains($line, 'Development Server')) {
                continue;
            }

            // Try to parse as access log
            $entry = LogEntry::fromAccessLog($line);
            if ($entry !== null && $this->logger !== null) {
                $this->logger->log($entry);
            } elseif ($this->isPhpServerLogLine($line)) {
                // Format known server log lines
                $this->formatAndOutputLogLine($line);
            } else {
                // Output unknown lines as-is
                $this->line($line);
            }
        }
    }

    /**
     * Format and output a PHP server log line
     */
    private function formatAndOutputLogLine(string $line): void
    {
        // Parse: [timestamp] ip [status]: METHOD /path
        if (preg_match('/\[([^\]]+)\]\s+\S+\s+\[(\d+)\]:\s+(\w+)\s+(.+)/', $line, $m)) {
            $entry = LogEntry::create(
                method: $m[3],
                path: $m[4],
                status: (int) $m[2]
            );

            if ($this->logger !== null) {
                $this->logger->log($entry);
            }
            return;
        }

        // Handle connection messages (Accepted, Closed, etc.)
        if (preg_match('/\[([^\]]+)\]\s+\S+\s+(.+)/', $line, $m)) {
            // Skip connection lifecycle messages in watch mode for cleaner output
            return;
        }

        $this->line($line);
    }

    /**
     * Stop the server process
     */
    private function stopServerProcess(): void
    {
        if ($this->serverProcess !== null && $this->serverProcess->isRunning()) {
            $this->serverProcess->stop(3);
        }
        $this->serverProcess = null;
    }

    /**
     * Start queue worker process
     */
    private function startQueueWorker(): void
    {
        $this->queueProcess = new Process([
            PHP_BINARY,
            'glueful',
            'queue:work',
            '--sleep=3',
        ], base_path());

        $this->queueProcess->setTimeout(null);
        $this->queueProcess->start(function (string $_type, string $buffer) {
            foreach (explode("\n", trim($buffer)) as $line) {
                if ($line !== '') {
                    $this->logger?->logQueue($line);
                }
            }
        });
    }

    /**
     * Stop queue worker process
     */
    private function stopQueueWorker(): void
    {
        if ($this->queueProcess !== null && $this->queueProcess->isRunning()) {
            $this->queueProcess->stop(3);
        }
        $this->queueProcess = null;
    }

    /**
     * Find an available port, incrementing if necessary
     */
    private function findAvailablePort(string $host, int $preferredPort, OutputInterface $output): ?int
    {
        $port = $preferredPort;
        $maxAttempts = 10;
        $attempts = 0;

        while (!$this->isPortAvailable($host, $port) && $attempts < $maxAttempts) {
            $output->writeln(
                "<comment>Port {$port} is in use, trying " . ($port + 1) . '...</comment>'
            );
            $port++;
            $attempts++;
        }

        if ($attempts >= $maxAttempts) {
            $this->error(
                "Could not find available port after trying {$preferredPort} to " . ($port - 1)
            );
            return null;
        }

        return $port;
    }

    /**
     * Validate port number
     */
    private function isValidPort(int $port): bool
    {
        return $port >= 1 && $port <= 65535;
    }

    /**
     * Check if port is available
     */
    private function isPortAvailable(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($socket !== false) {
            fclose($socket);
            return false;
        }
        return true;
    }

    /**
     * Open browser to server URL
     */
    private function openBrowser(string $host, int $port): void
    {
        $url = "http://{$host}:{$port}";

        $command = match (PHP_OS_FAMILY) {
            'Darwin' => "open '{$url}'",
            'Windows' => "start '{$url}'",
            'Linux' => "xdg-open '{$url}'",
            default => null
        };

        if ($command !== null) {
            $this->line('Opening browser...');
            exec($command . ' 2>/dev/null &');
        } else {
            $this->warning('Could not detect OS to open browser automatically.');
            $this->line("Please open: {$url}");
        }
    }

    /**
     * Setup signal handlers for graceful shutdown
     */
    private function setupSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $shutdown = function () {
            $this->shutdown();
        };

        pcntl_signal(SIGINT, $shutdown);
        pcntl_signal(SIGTERM, $shutdown);
    }

    /**
     * Graceful shutdown
     */
    private function shutdown(): void
    {
        $this->shutdownRequested = true;

        $this->logger?->logShutdown();

        $this->watcher?->stop();
        $this->stopServerProcess();
        $this->stopQueueWorker();

        exit(0);
    }

    /**
     * Find the router script for PHP's built-in server
     */
    private function findRouterScript(string $publicDir): ?string
    {
        $possibleLocations = [
            $publicDir . '/router.php',
            dirname($publicDir) . '/router.php',
            dirname(__DIR__, 3) . '/router.php',
        ];

        foreach ($possibleLocations as $path) {
            if (file_exists($path)) {
                return realpath($path) ?: null;
            }
        }

        return null;
    }

    /**
     * Check if line is a PHP built-in server log line
     */
    private function isPhpServerLogLine(string $line): bool
    {
        // Matches built-in server log formats like:
        // [Tue Oct 21 05:20:14 2025] [::1]:56643 Accepted
        // [Tue Oct 21 05:20:14 2025] [::1]:56643 [200]: GET /status
        $pattern = '/^\[[A-Z][a-z]{2}\s+[A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2}\s+\d{4}\]'
            . '\s+\[(?:\:\:1|127\.0\.0\.1|\d+\.\d+\.\d+\.\d+)\]:\d+\s+/';

        if (preg_match($pattern, $line) === 1) {
            return true;
        }

        if (str_contains($line, 'Development Server')) {
            return true;
        }

        return false;
    }

    /**
     * Get detailed help text
     */
    private function getDetailedHelp(): string
    {
        return <<<HELP
Start a local development server with optional file watching and queue worker.

The development server provides:
  - Colorized request logging with status codes
  - Auto-restart on file changes (with --watch)
  - Queue worker integration (with --queue)
  - Port auto-selection if preferred port is in use
  - Browser auto-open (with --open)

Examples:
  php glueful serve
  php glueful serve --port=3000
  php glueful serve --watch --queue
  php glueful serve -w -q -o
  php glueful serve --host=0.0.0.0 --port=8080

The server watches these directories by default:
  - api/
  - src/
  - config/
  - routes/

File extensions watched: .php, .env, .json, .yaml, .yml
HELP;
    }
}
