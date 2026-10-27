<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting\Middleware;

use Glueful\Api\RateLimiting\RateLimitHeaders;
use Glueful\Api\RateLimiting\RateLimitManager;
use Glueful\Api\RateLimiting\RateLimitResult;
use Glueful\Events\Auth\RateLimitExceededEvent;
use Glueful\Events\EventService;
use Glueful\Routing\Middleware\MiddlewareParams;
use Glueful\Routing\Route;
use Glueful\Routing\RouteMiddleware;
use Glueful\Support\SensitiveParamRedactor;
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
    /** Keying modes understood by RateLimitManager (see ->rateLimit(by:) / #[RateLimit(by:)]). */
    private const BY_VALUES = ['ip', 'user', 'endpoint'];

    /**
     * @var array<string> IPs that bypass rate limiting
     */
    private array $bypassIps = [];

    private bool $enabled;

    /**
     * @param RateLimitManager $manager Rate limit manager
     * @param RateLimitHeaders $headers Header generator
     * @param array<string, mixed> $config Additional configuration
     * @param EventService|null $events Optional; dispatches RateLimitExceededEvent when a request is limited
     */
    public function __construct(
        private readonly RateLimitManager $manager,
        private readonly RateLimitHeaders $headers,
        array $config = [],
        private readonly ?EventService $events = null
    ) {
        // api.rate_limiting.enabled (API_RATE_LIMITING_ENABLED): off passes every request through.
        $this->enabled = MiddlewareParams::bool($config['enabled'] ?? null) ?? true;

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
        if (!$this->enabled) {
            return $next($request);
        }

        // Check for bypass IPs
        $clientIp = $request->getClientIp() ?? '';
        if ($this->shouldBypass($clientIp)) {
            return $next($request);
        }

        // Get route from request attributes
        $route = $request->attributes->get('_route');

        // Get rate limit configuration from route; fall back to middleware
        // string params ("rate_limit:attempts,windowSeconds[,by]")
        $limits = $this->getLimitsFromRoute($route);
        if ($limits === []) {
            $limits = $this->getLimitsFromParams($params);
        }

        // Get cost multiplier from route
        $cost = $this->getCostFromRoute($route);

        // Attempt rate limit check
        $result = $this->manager->attempt($request, $limits, $cost);

        // Return 429 response if limit exceeded
        if (!$result->isAllowed()) {
            $this->dispatchExceeded($request, $clientIp, $result, $limits);
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
     * Dispatch RateLimitExceededEvent for security monitoring/activity logging. Never lets an
     * event-system failure turn the 429 into a 500.
     *
     * @param array<array<string, mixed>> $limits
     */
    private function dispatchExceeded(Request $request, string $clientIp, RateLimitResult $result, array $limits): void
    {
        if ($this->events === null) {
            return;
        }

        try {
            $this->events->dispatch(new RateLimitExceededEvent(
                $clientIp,
                $result->policy ?? (string) $result->limit,
                $result->limit - $result->remaining + $result->cost,
                $result->limit,
                $this->windowFor($result, $limits),
                [
                    'source' => 'rate_limit_middleware',
                    'method' => $request->getMethod(),
                    'path' => SensitiveParamRedactor::sanitizePath($request->getPathInfo()),
                    'tier' => $result->tier,
                    'retry_after' => $result->retryAfter,
                    'triggered_at' => time(),
                ]
            ));
        } catch (\Throwable) {
            // Monitoring must not break limiting.
        }
    }

    /**
     * Window (seconds) of the limit that was hit: the configured decaySeconds of the matching
     * limit when identifiable, else the time until the window resets.
     *
     * @param array<array<string, mixed>> $limits
     */
    private function windowFor(RateLimitResult $result, array $limits): int
    {
        foreach ($limits as $limit) {
            if ((int) ($limit['attempts'] ?? 0) === $result->limit && isset($limit['decaySeconds'])) {
                return (int) $limit['decaySeconds'];
            }
        }

        return max(1, $result->getWindowSeconds());
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
     * Build a limit config from "rate_limit:attempts,windowSeconds[,by]" middleware params.
     *
     * Route-level config (`->rateLimit()` builder or #[RateLimit] attribute) takes
     * precedence; this only applies when the route carries no rate limit config.
     * The window param is in seconds (matching the documented string form) and
     * defaults to 60.
     *
     * @param array<int|string, mixed> $params
     * @return array<array<string, mixed>>
     */
    private function getLimitsFromParams(array $params): array
    {
        if ($params === [] || !is_numeric($params[0] ?? null)) {
            return [];
        }

        $attempts = (int) $params[0];
        if ($attempts <= 0) {
            return [];
        }

        $window = isset($params[1]) && is_numeric($params[1]) ? max(1, (int) $params[1]) : 60;

        $limit = [
            'attempts' => $attempts,
            'decaySeconds' => $window,
        ];

        // Optional third param: what to key by — same values as ->rateLimit(by:) / #[RateLimit(by:)],
        // resolved by RateLimitManager. Unknown values keep the default (ip).
        if (array_key_exists(2, $params)) {
            $by = is_string($params[2]) ? strtolower(trim($params[2])) : '';
            $limit['by'] = in_array($by, self::BY_VALUES, true) ? $by : 'ip';
        }

        return [$limit];
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
