<?php

declare(strict_types=1);

namespace Glueful\Events\Http;

use Glueful\Http\Exceptions\HttpClientException;
use Glueful\Events\Contracts\BaseEvent;

/**
 * HTTP Client Failure Event
 *
 * Fired when HTTP client operations fail, allowing applications to handle
 * business-specific logging and error handling for external service failures.
 */
class HttpClientFailureEvent extends BaseEvent
{
    /**
     * Create a new HTTP client failure event
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $url The URL that failed
     * @param HttpClientException $exception The exception that occurred
     * @param string $failureReason Reason for failure (connection_failed, request_failed, etc.)
     * @param array<string, mixed> $context Additional context data
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly HttpClientException $exception,
        public readonly string $failureReason,
        public readonly array $context = []
    ) {
        parent::__construct();
    }

    public function isConnectionFailure(): bool
    {
        return $this->failureReason === 'connection_failed';
    }

    public function isRequestFailure(): bool
    {
        return $this->failureReason === 'request_failed';
    }

    public function getHost(): ?string
    {
        $parsed = parse_url($this->url);
        return $parsed['host'] ?? null;
    }

    public function getScheme(): ?string
    {
        $parsed = parse_url($this->url);
        return $parsed['scheme'] ?? null;
    }
}
