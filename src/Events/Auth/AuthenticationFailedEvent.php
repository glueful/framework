<?php

declare(strict_types=1);

namespace Glueful\Events\Auth;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Authentication Failed Event
 *
 * Dispatched when authentication attempts fail.
 * Used for security monitoring, rate limiting, and audit logging.
 *
 * @package Glueful\Events\Auth
 */
class AuthenticationFailedEvent extends BaseEvent
{
    /**
     * @param string $username Attempted username/email
     * @param string $reason Failure reason
     * @param string|null $clientIp Client IP address
     * @param string|null $userAgent Client user agent
     * @param array<string, mixed> $metadata Additional failure metadata
     */
    public function __construct(
        private readonly string $username,
        private readonly string $reason,
        private readonly ?string $clientIp = null,
        private readonly ?string $userAgent = null,
        array $metadata = []
    ) {
        parent::__construct();

        foreach ($metadata as $key => $value) {
            $this->setMetadata($key, $value);
        }
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function isInvalidCredentials(): bool
    {
        return $this->reason === 'invalid_credentials';
    }

    public function isUserDisabled(): bool
    {
        return in_array($this->reason, ['user_disabled', 'user_suspended', 'user_locked'], true);
    }

    public function isSuspicious(): bool
    {
        return $this->getMetadata('suspicious') ?? false;
    }
}
