<?php

namespace Glueful\Console\Commands;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Development Server Command
 * Starts a local development server with enhanced features:
 * - Port availability checking
 * - Host binding configuration
 * - Graceful shutdown handling
 * - Enhanced output and monitoring
 * @package Glueful\Console\Commands
 */
#[AsCommand(
    name: 'serve',
    description: 'Start the Glueful development server'
)]
class ServeCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Start the Glueful development server')
             ->setHelp('This command starts a local development server for testing and development.')
             ->addOption(
                 'port',
                 'p',
                 InputOption::VALUE_REQUIRED,
                 'Port to run the server on',
                 '8000'
             )
             ->addOption(
                 'host',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Host to bind the server to',
                 'localhost'
             )
             ->addOption(
                 'open',
                 'o',
                 InputOption::VALUE_NONE,
                 'Open the server URL in default browser'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $port = (string) $input->getOption('port');
        $host = (string) $input->getOption('host');
        $openBrowser = (bool) $input->getOption('open');

        // Validate port
        if (!$this->isValidPort($port)) {
            $this->error('Invalid port number. Must be between 1 and 65535.');
            return self::FAILURE;
        }

        // Check if port is available
        if (!$this->isPortAvailable($host, (int) $port)) {
            $this->error("Port $port is already in use on $host.");
            $this->tip('Try a different port with --port=<number>');
            return self::FAILURE;
        }

        // Get public directory
        $publicDir =  base_path('public');
        if (!is_dir($publicDir)) {
            $this->error('Public directory not found. Expected: ' . $publicDir);
            return self::FAILURE;
        }

        // Set environment to development
        putenv('APP_ENV=development');

        // Display server information
        $this->displayServerInfo($host, $port, $publicDir);

        // Open browser if requested
        if ($openBrowser === true) {
            $this->openBrowser($host, $port);
        }

        // Start the server
        return $this->startServer($host, $port, $publicDir);
    }

    private function isValidPort(string $port): bool
    {
        return is_numeric($port) && $port >= 1 && $port <= 65535;
    }

    private function isPortAvailable(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($socket) {
            fclose($socket);
            return false;
        }
        return true;
    }

    private function displayServerInfo(string $host, string $port, string $publicDir): void
    {
        $this->info('🚀 Starting Glueful development server...');
        $this->line('');

        $url = "http://$host:$port";
        $this->line('  Server URL:    <info>' . $url . '</info>');
        $this->line('  Document root: <comment>' . $publicDir . '</comment>');
        $this->line('  Environment:   <comment>development</comment>');
        $this->line('');

        $this->success('Server is ready! Press Ctrl+C to stop.');
        $this->line('');
    }

    private function openBrowser(string $host, string $port): void
    {
        $url = "http://$host:$port";

        // Detect OS and open browser
        $os = PHP_OS_FAMILY;
        $command = match ($os) {
            'Darwin' => "open '$url'",
            'Windows' => "start '$url'",
            'Linux' => "xdg-open '$url'",
            default => null
        };

        if ($command !== null) {
            $this->line('Opening browser...');
            exec($command . ' 2>/dev/null &');
        } else {
            $this->warning('Could not detect OS to open browser automatically.');
            $this->line("Please open: $url");
        }
    }

    private function startServer(string $host, string $port, string $publicDir): int
    {
        // Build the PHP server command
        $command = [
            'php',
            '-S',
            "$host:$port",
            '-t',
            $publicDir
        ];

        // Create and start the process
        $process = new Process($command);
        $process->setTimeout(null); // No timeout for long-running server

        try {
            // Handle graceful shutdown
            $this->setupSignalHandlers($process);

            // Run the server
            $process->run(function ($type, $buffer) {
                // Output server logs in real-time
                $line = rtrim($buffer);
                if ($line === '') {
                    return;
                }
                if (Process::ERR === $type) {
                    // The PHP built-in server writes all access and lifecycle logs to STDERR.
                    // Re-classify known server log lines as normal output to avoid false [ERROR] noise.
                    if ($this->isPhpServerLogLine($line)) {
                        $this->line($line);
                        return;
                    }
                    $this->error($line);
                } else {
                    // Filter out PHP built-in server startup message on STDOUT
                    if (!str_contains($line, 'Development Server')) {
                        $this->line($line);
                    }
                }
            });

            return $process->getExitCode() ?? self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Server error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function setupSignalHandlers(Process $process): void
    {
        // Handle SIGINT (Ctrl+C) for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use ($process) {
                $this->line('');
                $this->warning('Shutting down server...');
                $process->stop();
                exit(0);
            });
        }
    }

    private function isPhpServerLogLine(string $line): bool
    {
        // Matches built-in server log formats like:
        // [Tue Oct 21 05:20:14 2025] [::1]:56643 Accepted
        // [Tue Oct 21 05:20:14 2025] [::1]:56643 [200]: GET /status
        // [Tue Oct 21 05:20:14 2025] [::1]:56642 Closed without sending a request
        $pattern = '/^\[[A-Z][a-z]{2}\s+[A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2}\s+\d{4}\]'
            . '\s+\[(?:\:\:1|127\.0\.0\.1)\]:\d+\s+/';

        if (preg_match($pattern, $line) === 1) {
            return true;
        }
        // Also treat the generic startup banner as non-error
        if (str_contains($line, 'Development Server')) {
            return true;
        }
        return false;
    }
}
