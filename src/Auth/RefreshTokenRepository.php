<?php

declare(strict_types=1);

namespace Glueful\Auth;

class RefreshTokenRepository
{
    public function __construct(private readonly RefreshTokenStore $store)
    {
    }

    /** @return array<string, mixed>|null */
    public function findActiveSessionByToken(string $refreshToken): ?array
    {
        return $this->store->getActiveSessionByRefreshToken($refreshToken);
    }

    /** @return array<string, mixed>|null */
    public function rotateOneTimeToken(string $oldRefreshToken, string $newRefreshToken, int $ttlSeconds): ?array
    {
        return $this->store->rotateActiveToken($oldRefreshToken, $newRefreshToken, $ttlSeconds, true);
    }

    public function revokeSessionFamily(string $sessionUuid): void
    {
        $this->store->revokeSessionScope($sessionUuid);
    }
}
