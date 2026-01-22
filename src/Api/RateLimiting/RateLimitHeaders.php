<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * IETF-compliant rate limit header generation
 *
 * Implements the draft-ietf-httpapi-ratelimit-headers specification
 * for standardized rate limit header responses.
 *
 * @see https://datatracker.ietf.org/doc/draft-ietf-httpapi-ratelimit-headers/
 */
final class RateLimitHeaders
{
    /**
     * @param array<string, mixed> $config Header configuration
     */
    public function __construct(
        private readonly array $config = []
    ) {
    }

    /**
     * Add rate limit headers to a response
     *
     * @param Response $response The response to add headers to
     * @param RateLimitResult $result Rate limit result
     * @return Response The response with headers added
     */
    public function addToResponse(Response $response, RateLimitResult $result): Response
    {
        if ($result->limit === 0) {
            // Unlimited - no headers needed
            return $response;
        }

        $headers = $this->generateHeaders($result);

        foreach ($headers as $name => $value) {
            $response->headers->set($name, (string) $value);
        }

        return $response;
    }

    /**
     * Generate rate limit headers from result
     *
     * @param RateLimitResult $result Rate limit result
     * @return array<string, string|int> Headers to add
     */
    public function generateHeaders(RateLimitResult $result): array
    {
        $headers = [];

        $includeLegacy = (bool) ($this->config['include_legacy'] ?? true);
        $includeIetf = (bool) ($this->config['include_ietf'] ?? true);

        // Legacy X-RateLimit-* headers (widely adopted)
        if ($includeLegacy) {
            $headers['X-RateLimit-Limit'] = $result->limit;
            $headers['X-RateLimit-Remaining'] = max(0, $result->remaining);
            $headers['X-RateLimit-Reset'] = $result->resetAt;
        }

        // IETF draft headers
        if ($includeIetf) {
            $headers['RateLimit-Limit'] = $result->limit;
            $headers['RateLimit-Remaining'] = max(0, $result->remaining);
            $headers['RateLimit-Reset'] = $result->resetAt;

            // Policy header with window information
            if ($result->policy !== null) {
                $headers['RateLimit-Policy'] = $result->policy;
            }
        }

        return $headers;
    }

    /**
     * Create a 429 Too Many Requests response
     *
     * @param RateLimitResult $result Rate limit result
     * @return JsonResponse The 429 response
     */
    public function createExceededResponse(RateLimitResult $result): JsonResponse
    {
        $response = new JsonResponse(
            [
                'error' => 'rate_limit_exceeded',
                'message' => 'Too many requests. Please try again later.',
                'retry_after' => $result->retryAfter,
                'limit' => $result->limit,
                'tier' => $result->tier,
            ],
            Response::HTTP_TOO_MANY_REQUESTS
        );

        // Add rate limit headers
        $this->addToResponse($response, $result);

        // Add Retry-After header (RFC 7231)
        if ($result->retryAfter > 0) {
            $response->headers->set('Retry-After', (string) $result->retryAfter);
        }

        return $response;
    }

    /**
     * Create headers for CORS preflight response
     *
     * Exposes rate limit headers for cross-origin requests
     *
     * @return string Comma-separated list of exposed headers
     */
    public function getExposedHeadersString(): string
    {
        $headers = [];

        $includeLegacy = (bool) ($this->config['include_legacy'] ?? true);
        $includeIetf = (bool) ($this->config['include_ietf'] ?? true);

        if ($includeLegacy) {
            $headers = array_merge($headers, [
                'X-RateLimit-Limit',
                'X-RateLimit-Remaining',
                'X-RateLimit-Reset',
            ]);
        }

        if ($includeIetf) {
            $headers = array_merge($headers, [
                'RateLimit-Limit',
                'RateLimit-Remaining',
                'RateLimit-Reset',
                'RateLimit-Policy',
            ]);
        }

        $headers[] = 'Retry-After';

        return implode(', ', $headers);
    }

    /**
     * Get array of exposed headers for CORS configuration
     *
     * @return array<string>
     */
    public function getExposedHeaders(): array
    {
        $headers = [];

        $includeLegacy = (bool) ($this->config['include_legacy'] ?? true);
        $includeIetf = (bool) ($this->config['include_ietf'] ?? true);

        if ($includeLegacy) {
            $headers = array_merge($headers, [
                'X-RateLimit-Limit',
                'X-RateLimit-Remaining',
                'X-RateLimit-Reset',
            ]);
        }

        if ($includeIetf) {
            $headers = array_merge($headers, [
                'RateLimit-Limit',
                'RateLimit-Remaining',
                'RateLimit-Reset',
                'RateLimit-Policy',
            ]);
        }

        $headers[] = 'Retry-After';

        return $headers;
    }

    /**
     * Format a limit for policy header
     *
     * Creates an IETF-compliant policy string like "100;w=60" or "100;w=3600;comment=\"hourly\""
     *
     * @param int $limit The rate limit
     * @param int $windowSeconds The window in seconds
     * @param string|null $comment Optional comment
     * @return string Formatted policy string
     */
    public function formatPolicy(int $limit, int $windowSeconds, ?string $comment = null): string
    {
        $policy = "{$limit};w={$windowSeconds}";

        if ($comment !== null) {
            $policy .= ";comment=\"{$comment}\"";
        }

        return $policy;
    }

    /**
     * Format multiple limits as a combined policy
     *
     * Creates policies like "100;w=60, 1000;w=3600, 10000;w=86400"
     *
     * @param array<array{limit: int, window: int, comment?: string}> $limits
     * @return string Combined policy string
     */
    public function formatCombinedPolicy(array $limits): string
    {
        $policies = [];

        foreach ($limits as $limitConfig) {
            $policies[] = $this->formatPolicy(
                $limitConfig['limit'],
                $limitConfig['window'],
                $limitConfig['comment'] ?? null
            );
        }

        return implode(', ', $policies);
    }

    /**
     * Check if headers are enabled
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    /**
     * Check if legacy headers are enabled
     */
    public function includeLegacy(): bool
    {
        return (bool) ($this->config['include_legacy'] ?? true);
    }

    /**
     * Check if IETF headers are enabled
     */
    public function includeIetf(): bool
    {
        return (bool) ($this->config['include_ietf'] ?? true);
    }
}
