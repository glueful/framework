<?php

declare(strict_types=1);

namespace Glueful\Auth\DTOs;

/**
 * Documentation-only 200 response for {@see \Glueful\Controllers\AuthController::refreshPermissions()}.
 *
 * Reflected by {@see \Glueful\Support\Documentation\ClassSchemaReflector} only —
 * never constructed at runtime — to document the `data` payload the route
 * returns (via #[ApiResponse(200, ...)]), mirroring the legacy comment
 * docblock's `@response 200` `data` keys.
 */
final class RefreshedPermissionsData
{
    /** Updated JWT access token. */
    public string $access_token = '';

    /** Updated JWT refresh token. */
    public string $refresh_token = '';

    /**
     * Updated user permissions.
     *
     * @var array<int,mixed>
     */
    public array $permissions = [];

    /** Timestamp of the permission update. */
    public string $updated_at = '';
}
