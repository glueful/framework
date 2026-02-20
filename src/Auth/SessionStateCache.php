<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Auth\Interfaces\SessionStoreInterface;

class SessionStateCache
{
    public function __construct(private readonly SessionStoreInterface $sessionStore)
    {
    }

    /** @param array{access_token: string, refresh_token?: string, expires_in?: int} $tokens */
    public function persistRotatedSession(string $sessionUuid, array $tokens): bool
    {
        return $this->sessionStore->updateTokens($sessionUuid, $tokens);
    }

    public function invalidateSession(string $sessionUuid): bool
    {
        return $this->sessionStore->revoke($sessionUuid);
    }
}
