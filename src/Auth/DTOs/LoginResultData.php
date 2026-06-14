<?php

declare(strict_types=1);

namespace Glueful\Auth\DTOs;

/**
 * Documentation-only 200 response for {@see \Glueful\Controllers\AuthController::login()}.
 *
 * A SUPERSET of the documented login success shapes — login stays a MANUAL
 * handler, so this DTO is NEVER constructed at runtime; it is reflected by
 * {@see \Glueful\Support\Documentation\ClassSchemaReflector} purely to document
 * the `data` payload the route returns (via #[ApiResponse(200, ...)]).
 *
 * Covers the standard token issuance (the legacy comment docblock's `@response
 * 200` `data` keys) and the two-factor challenge branch the manual handler can
 * also return. 2FA fields are nullable since they are only present on the
 * challenge response.
 */
final class LoginResultData
{
    /** JWT access token. */
    public string $access_token = '';

    /** Token type — always "Bearer". */
    public string $token_type = '';

    /** Token expiration in seconds. */
    public int $expires_in = 0;

    /** JWT refresh token. */
    public string $refresh_token = '';

    /**
     * Authenticated user profile (OIDC-style claims: id, email, username, …).
     *
     * @var array<string,mixed>
     */
    public array $user = [];

    /** True when a two-factor challenge must be completed before login finishes. */
    public ?bool $two_factor_required = null;

    /** Opaque challenge token to complete the two-factor step. */
    public ?string $challenge_token = null;

    /** Masked destination the two-factor code was delivered to. */
    public ?string $delivered_to = null;
}
