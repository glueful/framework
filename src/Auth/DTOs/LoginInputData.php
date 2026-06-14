<?php

declare(strict_types=1);

namespace Glueful\Auth\DTOs;

/**
 * Documentation-only request body for {@see \Glueful\Controllers\AuthController::login()}.
 *
 * Login stays a MANUAL handler (polymorphic: username/password, token, and
 * api_key flows), so this DTO is NEVER hydrated or validated at runtime — it is
 * reflected by {@see \Glueful\Support\Documentation\ClassSchemaReflector} purely
 * to document the JSON request body the route accepts (via #[ApiRequestBody]).
 *
 * Public typed props mirror the `@requestBody` the legacy comment docblock
 * documented (`username`, `password`) plus the optional fields the handler reads
 * (`provider`, `remember`).
 */
final class LoginInputData
{
    /** Username or email address. */
    public string $username = '';

    /** User password. */
    public string $password = '';

    /** Authentication provider to use (e.g. jwt, ldap, saml); defaults to jwt. */
    public ?string $provider = null;

    /** Remember-me preference for an extended session. */
    public ?bool $remember = null;
}
