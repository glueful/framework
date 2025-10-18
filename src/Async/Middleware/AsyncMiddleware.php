<?php

declare(strict_types=1);

namespace Glueful\Async\Middleware;

use Glueful\Async\Contracts\Scheduler;
use Glueful\Async\FiberScheduler;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Async middleware for providing FiberScheduler to request handlers.
 *
 * This middleware injects a FiberScheduler instance into the request attributes,
 * making async task scheduling available to controllers and downstream middleware.
 * It enables concurrent task execution within HTTP request handlers.
 *
 * The scheduler is stored in request attributes under a standardized key,
 * allowing controllers to spawn and coordinate async tasks during request processing.
 *
 * Route registration:
 * ```php
 * $router->get('/users', [UserController::class, 'index'])
 *     ->middleware(['async']);
 * ```
 *
 * Controller usage:
 * ```php
 * public function index(Request $request): Response
 * {
 *     $scheduler = $request->attributes->get(AsyncMiddleware::ATTR_SCHEDULER);
 *
 *     // Spawn concurrent tasks
 *     $tasks = [
 *         $scheduler->spawn(fn() => $this->fetchUsers()),
 *         $scheduler->spawn(fn() => $this->fetchStats()),
 *     ];
 *
 *     // Wait for all tasks
 *     [$users, $stats] = $scheduler->all($tasks);
 *
 *     return new JsonResponse(['users' => $users, 'stats' => $stats]);
 * }
 * ```
 *
 * Benefits:
 * - Enables concurrent I/O operations within request handlers
 * - Reduces request latency by parallelizing independent operations
 * - Provides consistent scheduler access across the application
 * - Supports dependency injection for testing
 */
final class AsyncMiddleware implements RouteMiddleware
{
    /**
     * Request attribute key for accessing the scheduler.
     *
     * Use this constant to retrieve the scheduler from request attributes
     * to ensure consistency across the application.
     */
    public const ATTR_SCHEDULER = 'glueful.async.scheduler';

    /**
     * Creates async middleware with optional scheduler injection.
     *
     * @param Scheduler|null $scheduler Optional scheduler instance for DI/testing.
     *                                  If null, a new FiberScheduler is created per request.
     */
    public function __construct(private ?Scheduler $scheduler = null)
    {
    }

    /**
     * Injects the scheduler into request attributes and continues the chain.
     *
     * This method:
     * 1. Creates or uses the injected scheduler instance
     * 2. Stores it in request attributes under ATTR_SCHEDULER
     * 3. Continues to the next middleware/handler
     *
     * The scheduler remains available throughout the request lifecycle,
     * allowing any downstream handler to access it.
     *
     * @param Request $request The HTTP request
     * @param callable $next Next middleware/handler in the chain
     * @param mixed ...$params Optional middleware parameters (unused)
     * @return mixed The response from the handler chain
     */
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Use injected scheduler or create new FiberScheduler instance
        $scheduler = $this->scheduler ?? new FiberScheduler();

        // Store scheduler in request attributes for handler access
        $request->attributes->set(self::ATTR_SCHEDULER, $scheduler);

        // Continue to next middleware/handler
        return $next($request);
    }
}
