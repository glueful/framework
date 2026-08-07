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
        if (!is_string($sessionData['uuid'] ?? null) || !is_string($sessionData['created_at'] ?? null)) {
            return null;
        }
        /** @var array{uuid: string, created_at: string, provider?: string, remember_me?: bool} $sessionData */
        return $this->tokenManager->refreshTokens($refreshToken, $provider, null, $sessionData);
    }
}
