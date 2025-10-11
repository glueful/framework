<?php

declare(strict_types=1);

namespace Glueful\Auth\Interfaces;

/**
 * Session Store Interface
 *
 * Unified interface for session lifecycle operations (create/update/revoke/lookup)
 * across DB and cache layers with provider-aware TTL handling.
 */
interface SessionStoreInterface
{
    /**
     * Create a new session
     *
     * @param array<string, mixed> $user User data (must include 'uuid')
     * @param array{access_token: string, refresh_token: string, expires_in?: int} $tokens Token pair
     */
    public function create(array $user, array $tokens, string $provider, bool $rememberMe = false): bool;

    /**
     * Update session tokens
     *
     * @param array{access_token: string, refresh_token: string, expires_in?: int} $tokens
     */
    public function updateTokens(string $sessionIdOrRefreshToken, array $tokens): bool;

    /** @return array<string, mixed>|null */
    public function getByAccessToken(string $accessToken): ?array;

    /** @return array<string, mixed>|null */
    public function getByRefreshToken(string $refreshToken): ?array;

    /** Revoke a session by token or ID */
    public function revoke(string $sessionIdOrToken): bool;

    /** Revoke all sessions for a user */
    public function revokeAllForUser(string $userUuid): bool;

    /** Cleanup expired sessions */
    public function cleanupExpired(): int;

    /**
     * Health snapshot
     *
     * @return array<string, mixed>
     */
    public function health(): array;

    /** Validate storage consistency for a session */
    public function isConsistent(string $sessionIdOrToken): bool;

    /**
     * Listing helpers
     *
     * @return list<array<string, mixed>>
     */
    public function listByProvider(string $provider): array;
    /**
     * @return list<array<string, mixed>>
     */
    public function listByUser(string $userUuid): array;

    /**
     * Canonical TTL helpers
     */
    public function getAccessTtl(string $provider, bool $rememberMe = false): int;
    public function getRefreshTtl(string $provider, bool $rememberMe = false): int;
}
