<?php

declare(strict_types=1);

namespace Glueful\Auth;

class ProviderTokenIssuer
{
    public function __construct(private readonly TokenManager $tokenManager)
    {
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return array<string, mixed>|null
     */
    public function refresh(string $refreshToken, string $provider, array $sessionData): ?array
    {
        return $this->tokenManager->refreshTokens($refreshToken, $provider, null, $sessionData);
    }
}
