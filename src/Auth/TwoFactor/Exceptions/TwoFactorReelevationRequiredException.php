<?php

declare(strict_types=1);

namespace Glueful\Auth\TwoFactor\Exceptions;

use Glueful\Http\Exceptions\Client\ForbiddenException;
use Throwable;

/**
 * Thrown when an authenticated user attempts a 2FA-sensitive action (e.g. disabling 2FA)
 * without a recent enough 2FA verification on the current session. Maps to HTTP 403.
 */
final class TwoFactorReelevationRequiredException extends ForbiddenException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message = 'Recent two-factor verification is required to perform this action',
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $headers, $previous);
    }
}
