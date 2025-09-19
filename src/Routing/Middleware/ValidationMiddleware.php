<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Validation\ValidationException;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

final class ValidationMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        try {
            return $next($request);
        } catch (ValidationException $e) {
            return new JsonResponse([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }
    }
}
