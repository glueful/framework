<?php

declare(strict_types=1);

namespace Glueful\Auth\ApiKey\Exceptions;

use Glueful\Http\Exceptions\Domain\AuthenticationException;

/**
 * Thrown when an API key fails authentication for any reason other than
 * expiration: not found, hash mismatch, revoked, or IP not in allowlist.
 * The provider catches this and returns null with a generic error message
 * (don't leak which specific check failed — attackers shouldn't learn
 * "this prefix is known but the IP doesn't match").
 */
final class InvalidApiKeyException extends AuthenticationException
{
}
