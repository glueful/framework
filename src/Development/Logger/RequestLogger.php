<?php

declare(strict_types=1);

namespace Glueful\Development\Logger;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Colorized HTTP request logger for development
 *
 * Provides formatted, colorized output for HTTP requests during
 * development server operation. Includes timing, status codes,
 * and memory usage information.
 *
 * @example
 * $logger = new RequestLogger($output);
 * $logger->logStartup('localhost', 8000, ['watch' => true]);
 * $logger->log(LogEntry::create('GET', '/api/users', 200, 45.2, 2097152));
 *
 * @package Glueful\Development\Logger
 */
class RequestLogger
{
    /**
     * Console output interface
     */
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
     * HTTP method colors
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
     *
     * @param OutputInterface $output Console output
     */
    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * Log an HTTP request entry
     *
     * @param LogEntry $entry Request log entry
     */
    public function log(LogEntry $entry): void
    {
        $time = $entry->timestamp->format('H:i:s');
        $method = $this->formatMethod($entry->method);
        $path = $this->formatPath($entry->path);
        $status = $this->formatStatus($entry->status);
        $duration = $this->formatDuration($entry->duration);
        $memory = $entry->memory > 0 ? $this->formatMemory($entry->memory) : '';

        $line = sprintf(
            '<comment>[%s]</comment> %s %s %s %s%s',
            $time,
            $method,
            $path,
            $status,
            $duration,
            $memory !== '' ? ' ' . $memory : ''
        );

        $this->output->writeln($line);
    }

    /**
     * Log a raw access log line from PHP built-in server
     *
     * @param string $line Raw log line
     */
    public function logRaw(string $line): void
    {
        $entry = LogEntry::fromAccessLog($line);

        if ($entry !== null) {
            $this->log($entry);
        } else {
            // Output as-is if can't parse
            $this->output->writeln($line);
        }
    }

    /**
     * Log server startup information
     *
     * @param string $host Server host
     * @param int $port Server port
     * @param array<string, mixed> $options Server options
     */
    public function logStartup(string $host, int $port, array $options = []): void
    {
        $this->output->writeln('');
        $this->output->writeln('  <info>Glueful Development Server</info>');
        $this->output->writeln('');
        $this->output->writeln("  <comment>Local:</comment>   http://{$host}:{$port}");

        if (isset($options['watch']) && $options['watch'] === true) {
            $this->output->writeln('  <comment>Watch:</comment>   <info>Enabled</info>');
        }

        if (isset($options['queue']) && $options['queue'] === true) {
            $this->output->writeln('  <comment>Queue:</comment>   <info>Worker running</info>');
        }

        $this->output->writeln('');
        $this->output->writeln('  Press <comment>Ctrl+C</comment> to stop');
        $this->output->writeln('');
    }

    /**
     * Log server restart
     *
     * @param string $reason Reason for restart
     */
    public function logRestart(string $reason = 'File changed'): void
    {
        $time = date('H:i:s');
        $this->output->writeln('');
        $this->output->writeln(
            "<comment>[{$time}]</comment> <info>↻</info> Restarting server ({$reason})..."
        );
        $this->output->writeln('');
    }

    /**
     * Log server shutdown
     */
    public function logShutdown(): void
    {
        $this->output->writeln('');
        $this->output->writeln('<comment>Shutting down server...</comment>');
    }

    /**
     * Log queue worker activity
     *
     * @param string $message Queue message
     */
    public function logQueue(string $message): void
    {
        $time = date('H:i:s');
        $this->output->writeln(
            "<comment>[{$time}]</comment> <question>[Queue]</question> {$message}"
        );
    }

    /**
     * Log a file watcher event
     *
     * @param string $file Changed file
     * @param string $type Change type (created, modified, deleted)
     */
    public function logFileChange(string $file, string $type = 'modified'): void
    {
        $time = date('H:i:s');
        $color = match ($type) {
            'created' => 'info',
            'deleted' => 'error',
            default => 'comment',
        };

        $this->output->writeln(
            "<comment>[{$time}]</comment> File {$type}: <{$color}>{$file}</{$color}>"
        );
    }

    /**
     * Log an error message
     *
     * @param string $message Error message
     */
    public function logError(string $message): void
    {
        $time = date('H:i:s');
        $this->output->writeln("<comment>[{$time}]</comment> <error>{$message}</error>");
    }

    /**
     * Log an info message
     *
     * @param string $message Info message
     */
    public function logInfo(string $message): void
    {
        $time = date('H:i:s');
        $this->output->writeln("<comment>[{$time}]</comment> <info>{$message}</info>");
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
     * Format request path with truncation
     */
    private function formatPath(string $path, int $maxLength = 50): string
    {
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
     * Format duration with color based on speed
     */
    private function formatDuration(float $milliseconds): string
    {
        if ($milliseconds <= 0) {
            return str_pad('--', 8, ' ', STR_PAD_LEFT);
        }

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

        return '<' . $color . '>' . str_pad($value, 8, ' ', STR_PAD_LEFT) . '</' . $color . '>';
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
        return '<comment>' . str_pad($formatted, 8, ' ', STR_PAD_LEFT) . '</comment>';
    }
}
