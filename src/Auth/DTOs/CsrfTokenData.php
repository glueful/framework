<?php

declare(strict_types=1);

namespace Glueful\Auth\DTOs;

/**
 * Documentation-only 200 response for {@see \Glueful\Controllers\AuthController::csrfToken()}.
 *
 * Reflected by {@see \Glueful\Support\Documentation\ClassSchemaReflector} only —
 * never constructed at runtime — to document the `data` payload the route
 * returns (via #[ApiResponse(200, ...)]), mirroring the legacy comment
 * docblock's `@response 200` `data` keys.
 */
final class CsrfTokenData
{
    /** CSRF token value. */
    public string $token = '';

    /** Header name for the CSRF token (X-CSRF-Token). */
    public string $header = '';

    /** Form field name for the CSRF token (_token). */
    public string $field = '';

    /** Token expiration timestamp (Unix epoch). */
    public int $expires_at = 0;
}
