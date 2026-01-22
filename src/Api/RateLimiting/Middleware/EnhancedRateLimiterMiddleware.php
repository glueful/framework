<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting\Middleware;

use Glueful\Api\RateLimiting\RateLimitHeaders;
use Glueful\Api\RateLimiting\RateLimitManager;
use Glueful\Routing\Route;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enhanced Rate Limiter Middleware
 *
 * Provides advanced rate limiting with:
 * - Per-route limits via #[RateLimit] attributes
 * - Tiered user access (anonymous, free, pro, enterprise)
 * - Cost-based limiting via #[RateLimitCost] attributes
 * - Multiple algorithms (fixed, sliding, token bucket)
 * - IETF-compliant rate limit headers
 *
 * @example Route configuration:
 * ```php
 * $router->get('/users', [UserController::class, 'index'])
 *     ->middleware(['enhanced_rate_limit']);
 * ```
 *
 * @example Attribute-based configuration:
 * ```php
 * #[RateLimit(attempts: 60, perMinutes: 1)]
 * #[RateLimit(attempts: 1000, perHours: 1)]
 * public function index(): Response { }
 * ```
 */
class EnhancedRateLimiterMiddleware implements RouteMiddleware
{
    /**
     * @var array<string> IPs that bypass rate limiting
     */
    private array $bypassIps = [];

    /**
     * @param RateLimitManager $manager Rate limit manager
     * @param RateLimitHeaders $headers Header generator
     * @param array<string, mixed> $config Additional configuration
     */
    public function __construct(
        private readonly RateLimitManager $manager,
        private readonly RateLimitHeaders $headers,
        array $config = []
    ) {
        // Parse bypass IPs from config
        $bypassIpsConfig = $config['bypass_ips'] ?? '';
        if (is_string($bypassIpsConfig) && $bypassIpsConfig !== '') {
            $this->bypassIps = array_map('trim', explode(',', $bypassIpsConfig));
        } elseif (is_array($bypassIpsConfig)) {
            $this->bypassIps = $bypassIpsConfig;
        }
    }

    /**
     * Handle rate limiting for the request
     *
     * @param Request $request The HTTP request
     * @param callable $next Next handler in pipeline
     * @param mixed ...$params Optional parameters from route configuration
     * @return mixed Response
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Check for bypass IPs
        $clientIp = $request->getClientIp() ?? '';
        if ($this->shouldBypass($clientIp)) {
            return $next($request);
        }

        // Get route from request attributes
        $route = $request->attributes->get('_route');

        // Get rate limit configuration from route
        $limits = $this->getLimitsFromRoute($route);

        // Get cost multiplier from route
        $cost = $this->getCostFromRoute($route);

        // Attempt rate limit check
        $result = $this->manager->attempt($request, $limits, $cost);

        // Return 429 response if limit exceeded
        if (!$result->isAllowed()) {
            return $this->headers->createExceededResponse($result);
        }

        // Process the request
        $response = $next($request);

        // Ensure we have a Response object
        if (!$response instanceof Response) {
            return $response;
        }

        // Add rate limit headers to response
        if ($this->headers->isEnabled()) {
            $this->headers->addToResponse($response, $result);
        }

        return $response;
    }

    /**
     * Check if the IP should bypass rate limiting
     */
    private function shouldBypass(string $ip): bool
    {
        if ($ip === '' || count($this->bypassIps) === 0) {
            return false;
        }

        return in_array($ip, $this->bypassIps, true);
    }

    /**
     * Get rate limit configurations from route
     *
     * @param Route|mixed $route
     * @return array<array<string, mixed>>
     */
    private function getLimitsFromRoute(mixed $route): array
    {
        if (!$route instanceof Route) {
            return [];
        }

        $config = $route->getRateLimitConfig();

        return count($config) > 0 ? $config : [];
    }

    /**
     * Get cost multiplier from route
     *
     * @param Route|mixed $route
     */
    private function getCostFromRoute(mixed $route): int
    {
        if (!$route instanceof Route) {
            return 1;
        }

        $cost = $route->getRateLimitCost();

        return $cost !== null && $cost >= 1 ? $cost : 1;
    }
}
