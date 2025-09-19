<?php

declare(strict_types=1);

namespace Glueful\Events\Auth;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Session Created Event
 *
 * Dispatched when a new user session is created during authentication.
 * Contains session data and tokens for listeners that need to respond
 * to new session creation (e.g., logging, analytics, cache warming).
 *
 * @package Glueful\Events\Auth
 */
class SessionCreatedEvent extends BaseEvent
{
    /**
     * @param array<string, mixed> $sessionData Session data (uuid, username, email, etc.)
     * @param array<string, string> $tokens Access and refresh tokens
     * @param array<string, mixed> $metadata Additional session metadata
     */
    public function __construct(
        private readonly array $sessionData,
        private readonly array $tokens,
        array $metadata = []
    ) {
        parent::__construct();

        foreach ($metadata as $key => $value) {
            $this->setMetadata($key, $value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getSessionData(): array
    {
        return $this->sessionData;
    }

    public function getUserUuid(): ?string
    {
        return $this->sessionData['uuid'] ?? null;
    }

    public function getUsername(): ?string
    {
        return $this->sessionData['username'] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }

    public function getAccessToken(): ?string
    {
        return $this->tokens['access_token'] ?? null;
    }

    public function getRefreshToken(): ?string
    {
        return $this->tokens['refresh_token'] ?? null;
    }

    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->getMetadata($key) ?? $default;
    }
}
