<?php

declare(strict_types=1);

namespace Glueful\Auth\ApiKey\Exceptions;

use Glueful\Http\Exceptions\Domain\AuthorizationException;

/**
 * Thrown by RequireScopeMiddleware when an authenticated API key lacks
 * the scopes a route declares via #[RequireScope]. Maps to 403.
 */
final class InsufficientScopeException extends AuthorizationException
{
}
