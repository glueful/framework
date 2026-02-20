<?php

declare(strict_types=1);

namespace Glueful\Auth;

class AccessTokenIssuer
{
    public function __construct(private readonly TokenManager $tokenManager)
    {
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function issuePair(array $sessionData, int $accessTtl, int $refreshTtl, string $refreshToken): array
    {
        return $this->tokenManager->generateTokenPair($sessionData, $accessTtl, $refreshTtl, $refreshToken);
    }
}
