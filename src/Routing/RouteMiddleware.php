<?php

declare(strict_types=1);

  namespace Glueful\Routing;

  use Symfony\Component\HttpFoundation\Request;

/**
 * Native Glueful Middleware Contract
 *
 * This is the primary middleware interface for Glueful's routing system.
 * It provides flexibility with parameter passing while maintaining clean semantics.
 */
interface RouteMiddleware
{
    /**
     * Handle middleware processing
     *
     * @param Request $request The HTTP request being processed
     * @param callable $next Next handler in the pipeline - call $next($request) to continue
     * @param mixed ...$params Additional parameters extracted from route or middleware config
     *                         Examples: rate limits, auth requirements, etc.
     * @return Response|mixed Response object or data to be normalized
     *
     * Example implementations:
     *
     * // Simple middleware
     * public function handle(Request $request, callable $next) {
     *     // Do something before
     *     $response = $next($request);
     *     // Do something after
     *     return $response;
     * }
     *
     * // Middleware with parameters
     * public function handle(Request $request, callable $next, string $role = 'user') {
     *     if (!$this->auth->hasRole($role)) {
     *         return new Response('Forbidden', 403);
     *     }
     *     return $next($request);
     * }
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed;
}
