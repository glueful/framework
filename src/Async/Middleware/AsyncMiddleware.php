<?php

declare(strict_types=1);

namespace Glueful\Async\Middleware;

use Glueful\Async\Contracts\Scheduler;
use Glueful\Async\FiberScheduler;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Async Middleware
 *
 * Injects the FiberScheduler into the request attributes,
 * making it available to controllers and other middleware
 * for spawning and coordinating async tasks.
 *
 * Usage in routes:
 *   $router->get('/users', [UserController::class, 'index'])
 *       ->middleware(['async']);
 *
 * Accessing in controller:
 *   $scheduler = $request->attributes->get(AsyncMiddleware::ATTR_SCHEDULER);
 *   $task = $scheduler->spawn(fn() => $this->fetchUser());
 */
final class AsyncMiddleware implements RouteMiddleware
{
    public const ATTR_SCHEDULER = 'glueful.async.scheduler';

    public function __construct(private ?Scheduler $scheduler = null)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $scheduler = $this->scheduler ?? new FiberScheduler();
        $request->attributes->set(self::ATTR_SCHEDULER, $scheduler);

        return $next($request);
    }
}
