<?php

declare(strict_types=1);

namespace Glueful\Events\Auth;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Dispatched after a session payload is written to cache (and DB).
 *
 * Provides the token, session id, provider name, and a mutable payload copy
 * that listeners can update via setPayload()/mergePayload(). The cache writer
 * may persist the updated payload back to cache after dispatch.
 */
final class SessionCachedEvent extends BaseEvent
{
    /** @var array<string, mixed> */
    private array $payload = [];

    /**
     * @param string $token Access token for the session
     * @param string $sessionId Session identifier (UUID or NanoID)
     * @param string $provider Authentication provider (e.g., 'jwt')
     * @param array<string, mixed> $payload Cached session payload (listeners may update)
     */
    public function __construct(
        private readonly string $token,
        private readonly string $sessionId,
        private readonly string $provider,
        array $payload = []
    ) {
        parent::__construct();
        $this->payload = $payload;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Replace the payload completely.
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    /**
     * Merge into the payload recursively.
     * @param array<string, mixed> $patch
     */
    public function mergePayload(array $patch): void
    {
        $this->payload = array_replace_recursive($this->payload, $patch);
    }
}
