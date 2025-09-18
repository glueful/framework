<?php

declare(strict_types=1);

namespace Glueful\Events\Auth;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Session Destroyed Event
 *
 * Dispatched when a user session is destroyed/revoked.
 * Contains session information for cleanup operations
 * (e.g., cache invalidation, logging, analytics).
 *
 * @package Glueful\Events\Auth
 */
class SessionDestroyedEvent extends BaseEvent
{
    /**
     * @param string $accessToken The access token that was revoked
     * @param string|null $userUuid User UUID if available
     * @param string $reason Reason for session destruction
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        private readonly string $accessToken,
        private readonly ?string $userUuid = null,
        private readonly string $reason = 'logout',
        array $metadata = []
    ) {
        parent::__construct();

        foreach ($metadata as $key => $value) {
            $this->setMetadata($key, $value);
        }
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getUserUuid(): ?string
    {
        return $this->userUuid;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function isExpired(): bool
    {
        return $this->reason === 'expired';
    }

    public function isRevoked(): bool
    {
        return in_array($this->reason, ['revoked', 'admin_revoked', 'security_revoked'], true);
    }
}
