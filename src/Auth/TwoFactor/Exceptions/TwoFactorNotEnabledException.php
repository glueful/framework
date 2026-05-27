<?php

declare(strict_types=1);

namespace Glueful\Auth\TwoFactor\Exceptions;

use Glueful\Http\Exceptions\Domain\AuthenticationException;

/**
 * Thrown when a 2FA-login verification is attempted but 2FA is no longer enabled
 * for the account (e.g. an admin disabled it during the challenge window).
 */
final class TwoFactorNotEnabledException extends AuthenticationException
{
}
