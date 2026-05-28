<?php

declare(strict_types=1);

namespace Glueful\Auth\TwoFactor\Exceptions;

use Glueful\Http\Exceptions\Domain\AuthenticationException;

/**
 * Thrown when a 2FA challenge token is invalid, expired, wrong-purpose, or already consumed.
 */
final class InvalidChallengeTokenException extends AuthenticationException
{
}
