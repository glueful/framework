<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Validation\ValidationException;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Renders a ValidationException thrown further down the pipeline as a 422.
 *
 * @deprecated since 1.91 — never registered under an alias, and a strict subset of the
 *             `validate` middleware ({@see \Glueful\Validation\Middleware\ValidationMiddleware}),
 *             which also catches downstream ValidationExceptions and additionally runs
 *             #[Validate]/FormRequest validation; the exception handler renders a 422 for an
 *             uncaught one as well. Use `->middleware(['validate'])`. Removal in a later release.
 */
final class ValidationMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        try {
            return $next($request);
        } catch (ValidationException $e) {
            return new JsonResponse([
                'status' => 422,
                'message' => self::messageFor($e),
                'errors' => $e->errors(),
            ], 422);
        }
    }

    /**
     * The exception's own message when one was explicitly given; the generic default otherwise
     * (the exception's constructor default is a placeholder, not an app-chosen message).
     */
    private static function messageFor(ValidationException $e): string
    {
        $message = $e->getMessage();
        $default = (new \ReflectionParameter([ValidationException::class, '__construct'], 'message'))
            ->getDefaultValue();

        return $message === '' || $message === $default ? 'Validation failed' : $message;
    }
}
