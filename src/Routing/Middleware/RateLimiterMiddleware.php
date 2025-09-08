<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Glueful\Security\RateLimiter;
use Glueful\Security\AdaptiveRateLimiter;
use Glueful\Exceptions\RateLimitExceededException;
use Glueful\Events\Auth\RateLimitExceededEvent;
use Glueful\Events\Event;

/**
 * Rate Limiter Middleware for Next-Gen Router
 *
 * Native Glueful middleware that implements rate limiting for API endpoints.
 * Uses the sliding window algorithm to accurately track and limit request rates.
 *
 * Features:
 * - IP-based rate limiting
 * - User-based rate limiting (when authenticated)
 * - Endpoint-based rate limiting
 * - Adaptive rate limiting with behavior profiling
 * - Distributed rate limiting across multiple nodes
 * - ML-powered anomaly detection
 * - Configurable limits and time windows
 * - Returns appropriate HTTP 429 responses when limits are exceeded
 * - Adds rate limit headers to responses
 * - Support for route-based parameter configuration
 */
class RateLimiterMiddleware implements RouteMiddleware
{
    /** @var int Maximum number of requests allowed in the time window */
    private int $maxAttempts;

    /** @var int Time window in seconds */
    private int $windowSeconds;

    /** @var string Rate limiter type (ip, user, endpoint) */
    private string $type;

    /** @var bool Whether to use adaptive rate limiting */
    private bool $useAdaptiveLimiter;

    /** @var bool Whether to enable distributed rate limiting */
    private bool $enableDistributed;


    /**
     * Create a new rate limiter middleware
     *
     * @param int $maxAttempts Maximum number of requests allowed
     * @param int $windowSeconds Time window in seconds
     * @param string $type Rate limiter type (ip, user, endpoint)
     * @param bool $useAdaptiveLimiter Whether to use adaptive rate limiting
     * @param bool $enableDistributed Whether to enable distributed rate limiting
     */
    public function __construct(
        int $maxAttempts = 60,
        int $windowSeconds = 60,
        string $type = 'ip',
        ?bool $useAdaptiveLimiter = null,
        ?bool $enableDistributed = null
    ) {
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->type = $type;

        // Default to config values if not specified
        $this->useAdaptiveLimiter = $useAdaptiveLimiter ??
            (bool) config('security.rate_limiter.enable_adaptive', false);
        $this->enableDistributed = $enableDistributed ??
            (bool) config('security.rate_limiter.enable_distributed', false);
    }

    /**
     * Handle rate limiting for the request
     *
     * @param Request $request The HTTP request
     * @param callable $next Next handler in pipeline
     * @param mixed ...$params Optional parameters (maxAttempts, windowSeconds, type)
     * @return mixed Response
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Extract parameters if provided via route configuration
        $maxAttempts = isset($params[0]) && is_int($params[0]) ? $params[0] : $this->maxAttempts;
        $windowSeconds = isset($params[1]) && is_int($params[1]) ? $params[1] : $this->windowSeconds;
        $type = isset($params[2]) && is_string($params[2]) ? $params[2] : $this->type;

        // Create the appropriate rate limiter based on type
        $limiter = $this->createLimiter($request, $maxAttempts, $windowSeconds, $type);

        // Check if the rate limit has been exceeded
        if ($limiter->isExceeded()) {
            // Emit event for application business logic
            $currentAttempts = $maxAttempts - $limiter->remaining();
            Event::dispatch(new RateLimitExceededEvent(
                $request->getClientIp() ?? '0.0.0.0',
                $type,
                $currentAttempts,
                $maxAttempts,
                $windowSeconds,
                [
                    'endpoint' => $request->getPathInfo(),
                    'method' => $request->getMethod(),
                    'user_agent' => $request->headers->get('User-Agent'),
                    'request_id' => $request->attributes->get('request_id')
                ]
            ));

            // Let exceptions bubble up instead of returning response directly
            throw new RateLimitExceededException('Too Many Requests', $limiter->getRetryAfter());
        }

        // Register this attempt
        $limiter->attempt();

        // Process the request through the middleware pipeline
        $response = $next($request);

        // Add rate limit headers to the response
        $response = $this->addRateLimitHeaders($response, $limiter, $maxAttempts, $windowSeconds);

        return $response;
    }

    /**
     * Create a rate limiter instance based on the configuration
     *
     * @param Request $request The incoming request
     * @param int $maxAttempts Maximum attempts for this request
     * @param int $windowSeconds Window seconds for this request
     * @param string $type Limiter type for this request
     * @return RateLimiter|AdaptiveRateLimiter The rate limiter instance
     */
    private function createLimiter(
        Request $request,
        int $maxAttempts,
        int $windowSeconds,
        string $type
    ): RateLimiter|AdaptiveRateLimiter {
        // Create context data for adaptive rate limiting
        $context = [
            'ip' => $request->getClientIp() ?? '0.0.0.0',
            'user_agent' => $request->headers->get('User-Agent', ''),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query_count' => count($request->query->all()),
            'timestamp' => time(),
        ];

        // Standard vs. Adaptive rate limiting decision
        if ($this->useAdaptiveLimiter) {
            return $this->createAdaptiveLimiter($request, $context, $maxAttempts, $windowSeconds, $type);
        } else {
            return $this->createStandardLimiter($request, $maxAttempts, $windowSeconds, $type);
        }
    }

    /**
     * Create a standard rate limiter
     *
     * @param Request $request The incoming request
     * @param int $maxAttempts Maximum attempts
     * @param int $windowSeconds Window seconds
     * @param string $type Limiter type
     * @return RateLimiter Standard rate limiter instance
     */
    private function createStandardLimiter(
        Request $request,
        int $maxAttempts,
        int $windowSeconds,
        string $type
    ): RateLimiter {
        if ($type === 'user') {
            // Get user ID from the authenticated session
            $userId = $this->getUserIdFromRequest($request);

            // If no user ID is available, fall back to IP-based limiting
            if ($userId === null) {
                return RateLimiter::perIp(
                    $request->getClientIp() ?? '0.0.0.0',
                    $maxAttempts,
                    $windowSeconds
                );
            }

            return RateLimiter::perUser(
                $userId,
                $maxAttempts,
                $windowSeconds
            );
        } elseif ($type === 'endpoint') {
            // Create an endpoint-specific limiter
            $endpoint = $request->getPathInfo();
            $identifier = $request->getClientIp() ?? '0.0.0.0';

            return RateLimiter::perEndpoint(
                $endpoint,
                $identifier,
                $maxAttempts,
                $windowSeconds
            );
        }

        // Default to IP-based rate limiting
        return RateLimiter::perIp(
            $request->getClientIp() ?? '0.0.0.0',
            $maxAttempts,
            $windowSeconds
        );
    }

    /**
     * Create an adaptive rate limiter
     *
     * @param Request $request The incoming request
     * @param array<string, mixed> $context Request context for behavior analysis
     * @param int $maxAttempts Maximum attempts
     * @param int $windowSeconds Window seconds
     * @param string $type Limiter type
     * @return AdaptiveRateLimiter Adaptive rate limiter instance
     */
    private function createAdaptiveLimiter(
        Request $request,
        array $context,
        int $maxAttempts,
        int $windowSeconds,
        string $type
    ): AdaptiveRateLimiter {
        $key = '';

        if ($type === 'user') {
            // Get user ID from the authenticated session
            $userId = $this->getUserIdFromRequest($request);

            // If no user ID is available, fall back to IP-based limiting
            if ($userId === null) {
                $key = "ip:" . ($request->getClientIp() ?? '0.0.0.0');
            } else {
                $key = "user:$userId";
            }
        } elseif ($type === 'endpoint') {
            // Create an endpoint-specific limiter
            $endpoint = $request->getPathInfo();
            $identifier = $request->getClientIp() ?? '0.0.0.0';
            $key = "endpoint:$endpoint:$identifier";
        } else {
            // Default to IP-based rate limiting
            $key = "ip:" . ($request->getClientIp() ?? '0.0.0.0');
        }

        // Create new AdaptiveRateLimiter instance with specific parameters per request,
        // so we create new instances rather than using DI container
        return new AdaptiveRateLimiter(
            $key,
            $maxAttempts,
            $windowSeconds,
            $context,
            $this->enableDistributed
        );
    }


    /**
     * Get user ID from the authenticated request
     *
     * @param Request $request The incoming request
     * @return string|null The user ID or null if not authenticated
     */
    private function getUserIdFromRequest(Request $request): ?string
    {
        // Try to get the user ID from the request attributes
        $userId = $request->attributes->get('user_id');

        if ($userId !== null) {
            return $userId;
        }

        // Try to get the user from token if available
        $token = $request->headers->get('Authorization');
        if ($token !== null) {
            // Remove 'Bearer ' prefix if present
            $token = str_replace('Bearer ', '', $token);

            // Try to get user from session using TokenStorageService
            $tokenStorage = new \Glueful\Auth\TokenStorageService();
            $session = $tokenStorage->getSessionByAccessToken($token);
            return $session['uuid'] ?? null;
        }

        return null;
    }

    /**
     * Add rate limit headers to the response
     *
     * @param Response $response The response
     * @param RateLimiter|AdaptiveRateLimiter $limiter The rate limiter instance
     * @param int $maxAttempts Maximum attempts
     * @param int $windowSeconds Window seconds
     * @return Response The response with rate limit headers
     */
    private function addRateLimitHeaders(
        $response,
        RateLimiter|AdaptiveRateLimiter $limiter,
        int $maxAttempts,
        int $windowSeconds
    ): Response {
        // Ensure we have a proper Response object
        if (!$response instanceof Response) {
            $response = new JsonResponse($response);
        }

        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) $limiter->remaining());
        $response->headers->set('X-RateLimit-Reset', (string) (time() + $windowSeconds));

        // Add adaptive headers if applicable
        if ($limiter instanceof AdaptiveRateLimiter) {
            $response->headers->set('X-Adaptive-RateLimit', 'true');

            // Add distributed header if enabled
            if ($this->enableDistributed) {
                $response->headers->set('X-Distributed-RateLimit', 'true');
            }
        }

        return $response;
    }
}
