<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Glueful\Routing\RouteMiddleware;
use Glueful\Http\Exceptions\Handler;
use Glueful\Http\Exceptions\Contracts\ExceptionHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Exception Handling Middleware
 *
 * Catches exceptions thrown during request processing and delegates
 * to the exception handler for consistent error responses.
 *
 * This middleware should typically be registered early in the middleware
 * pipeline to catch exceptions from all subsequent middleware and controllers.
 *
 * @example
 * // Register globally in route configuration
 * $router->group(['middleware' => ['exception']], function ($router) {
 *     // All routes here will have exception handling
 * });
 *
 * @example
 * // Or apply to specific routes
 * $router->get('/api/users', UserController::class)
 *     ->middleware(['exception', 'auth']);
 */
class ExceptionMiddleware implements RouteMiddleware
{
    /**
     * Create a new exception middleware instance
     *
     * @param ExceptionHandlerInterface $handler The exception handler
     */
    public function __construct(
        private ExceptionHandlerInterface $handler
    ) {
    }

    /**
     * Handle the request and catch any exceptions
     *
     * @param Request $request The HTTP request
     * @param callable $next The next middleware in the pipeline
     * @param mixed ...$params Additional middleware parameters (unused)
     * @return mixed The response
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        try {
            return $next($request);
        } catch (Throwable $e) {
            return $this->handler->handle($e, $request);
        }
    }
}
