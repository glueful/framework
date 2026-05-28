<?php

declare(strict_types=1);

namespace Glueful\Auth\TwoFactor\Exceptions;

use Glueful\Http\Exceptions\Domain\AuthenticationException;

/**
 * Thrown when a submitted 2FA PIN does not match (or there is no active PIN for the challenge).
 */
final class InvalidTwoFactorCodeException extends AuthenticationException
{
}
