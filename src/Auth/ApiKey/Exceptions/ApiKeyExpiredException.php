<?php

declare(strict_types=1);

namespace Glueful\Auth\ApiKey\Exceptions;

use Glueful\Http\Exceptions\Domain\AuthenticationException;

/**
 * Thrown when a row matched the request's key but is past expires_at.
 * Distinct from InvalidApiKeyException so consumers can produce a specific
 * "your key expired" diagnostic — this is a frequent support question.
 */
final class ApiKeyExpiredException extends AuthenticationException
{
}
