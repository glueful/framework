<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Domain;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * Provisioning Exception
 *
 * Thrown when account setup/provisioning fails after successful authentication.
 * This is a server-side setup error and should not be mapped to 401.
 */
class ProvisioningException extends HttpException
{
    /**
     * @param string $message
     * @param array<string, mixed> $context
     * @param Throwable|null $previous
     */
    public function __construct(
        string $message = 'Failed to complete account setup',
        array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(500, $message, [], 0, $previous);
        $this->context = $context !== [] ? $context : null;
    }

    /**
     * @param string $step
     * @param string $reason
     */
    public static function failedAtStep(string $step, string $reason): self
    {
        return new self(
            'Failed to complete account setup',
            ['step' => $step, 'reason' => $reason]
        );
    }
}
