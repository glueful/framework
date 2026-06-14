<?php

declare(strict_types=1);

namespace Glueful\Auth\DTOs;

/**
 * Documentation-only 200 response for {@see \Glueful\Controllers\AuthController::validateToken()}.
 *
 * Reflected by {@see \Glueful\Support\Documentation\ClassSchemaReflector} only —
 * never constructed at runtime — to document the `data` payload the route
 * returns (via #[ApiResponse(200, ...)]), mirroring the legacy comment
 * docblock's `@response 200` `data` keys.
 */
final class ValidatedTokenData
{
    /**
     * Authenticated user profile resolved from the validated token.
     *
     * @var array<string,mixed>
     */
    public array $user = [];

    /** Whether the supplied token is valid and active. */
    public bool $is_valid = false;
}
