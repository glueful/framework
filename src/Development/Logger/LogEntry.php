<?php

declare(strict_types=1);

namespace Glueful\Development\Logger;

/**
 * Structured log entry for HTTP requests
 *
 * Immutable data class representing a single HTTP request/response
 * log entry with timing and memory information.
 *
 * @package Glueful\Development\Logger
 */
class LogEntry
{
    /**
     * Create a new log entry
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path Request path
     * @param int $status HTTP status code
     * @param float $duration Request duration in milliseconds
     * @param int $memory Memory usage in bytes
     * @param \DateTimeImmutable $timestamp Request timestamp
     */
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
     * Create from PHP built-in server access log line
     *
     * Parses log format: [timestamp] ip:port [status]: METHOD /path
     *
     * @param string $line Raw access log line
     * @return self|null LogEntry or null if parsing fails
     */
    public static function fromAccessLog(string $line): ?self
    {
        // Parse PHP built-in server access log format
        // [Mon Jan 22 14:23:15 2026] [::1]:56643 [200]: GET /api/users
        $pattern = '/\[([^\]]+)\]\s+\S+\s+\[(\d+)\]:\s+(\w+)\s+(.+)/';

        if (!preg_match($pattern, $line, $matches)) {
            return null;
        }

        try {
            $timestamp = new \DateTimeImmutable($matches[1]);
        } catch (\Exception) {
            $timestamp = new \DateTimeImmutable();
        }

        return new self(
            method: strtoupper($matches[3]),
            path: trim($matches[4]),
            status: (int) $matches[2],
            duration: 0.0, // Not available in basic access log
            memory: 0,
            timestamp: $timestamp
        );
    }

    /**
     * Create with full timing information
     *
     * @param string $method HTTP method
     * @param string $path Request path
     * @param int $status HTTP status code
     * @param float $duration Duration in milliseconds
     * @param int $memory Memory usage in bytes
     * @return self
     */
    public static function create(
        string $method,
        string $path,
        int $status,
        float $duration = 0.0,
        int $memory = 0
    ): self {
        return new self(
            method: strtoupper($method),
            path: $path,
            status: $status,
            duration: $duration,
            memory: $memory,
            timestamp: new \DateTimeImmutable()
        );
    }

    /**
     * Check if response was successful (2xx)
     */
    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Check if response was a redirect (3xx)
     */
    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    /**
     * Check if response was a client error (4xx)
     */
    public function isClientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /**
     * Check if response was a server error (5xx)
     */
    public function isServerError(): bool
    {
        return $this->status >= 500;
    }

    /**
     * Check if request was slow (> threshold ms)
     */
    public function isSlow(float $thresholdMs = 200.0): bool
    {
        return $this->duration > $thresholdMs;
    }

    /**
     * Get status category (2xx, 3xx, 4xx, 5xx)
     */
    public function getStatusCategory(): string
    {
        return ((string) floor($this->status / 100)) . 'xx';
    }
}
