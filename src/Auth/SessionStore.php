<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Cache\CacheStore;
use Glueful\Database\Connection;
use Glueful\Events\Auth\SessionCreatedEvent;
use Glueful\Events\Auth\SessionDestroyedEvent;
use Glueful\Events\EventService;
use Glueful\Helpers\CacheHelper;
use Glueful\Helpers\Utils;
use Glueful\Http\RequestContext;

/**
 * Default Session Store
 *
 * Owns session lifecycle operations (create/update/revoke/lookup) and
 * applies canonical TTL policy while ensuring DB+cache consistency.
 */
class SessionStore implements SessionStoreInterface
{
    private Connection $db;
    /** @var CacheStore<string>|null */
    private ?CacheStore $cache;
    private ?RequestContext $requestContext;
    private bool $useTransactions = true;
    private string $sessionTable = 'auth_sessions';
    private int $cacheDefaultTtl;
    private ?ApplicationContext $context;
    private RefreshTokenStore $refreshTokenStore;

    /**
     * Request-level cache to prevent N+1 queries within the same request
     * @var array<string, array<string, mixed>|null>
     */
    private array $requestCache = [];

    /**
     * @param CacheStore<string>|null $cache
     */
    public function __construct(
        ?CacheStore $cache = null,
        ?Connection $db = null,
        ?RequestContext $requestContext = null,
        bool $useTransactions = true,
        ?ApplicationContext $context = null
    ) {
        $this->context = $context;
        $this->cache = $cache ?? CacheHelper::createCacheInstance();
        $this->db = $db ?? Connection::fromContext($context);

        // Resolve RequestContext: prefer explicit param, then container, then fail
        if ($requestContext !== null) {
            $this->requestContext = $requestContext;
        } elseif ($context !== null && $context->hasContainer()) {
            $this->requestContext = $context->getContainer()->get(RequestContext::class);
        } else {
            throw new \RuntimeException(
                'RequestContext is required for SessionStore. '
                . 'Provide it directly or ensure ApplicationContext has a booted container.'
            );
        }

        $this->useTransactions = $useTransactions;
        $this->cacheDefaultTtl = (int) $this->getConfig('session.access_token_lifetime', 900);
        $this->refreshTokenStore = new RefreshTokenStore($this->db, $context);
    }

    /**
     * @param array<string, mixed> $user
     * @param array{access_token: string, refresh_token?: string, expires_in?: int} $tokens
     */
    public function create(array $user, array $tokens, string $provider, bool $rememberMe = false): bool
    {
        try {
            if ($this->useTransactions) {
                $this->db->getPDO()->beginTransaction();
            }

            // Calculate expiration times
            if ($rememberMe) {
                $accessExpiresAt = $this->getJwtTokenExpiration($tokens['access_token']);
            } else {
                $accessExpiresAt = date('Y-m-d H:i:s', time() + (int)($tokens['expires_in'] ?? $this->cacheDefaultTtl));
            }
            // Prepare DB row
            $sessionUuid = $user['session_id'] ?? Utils::generateNanoID();
            $dbSessionData = [
                'uuid' => $sessionUuid,
                'user_uuid' => $user['uuid'],
                'expires_at' => $accessExpiresAt,
                'provider' => $provider,
                'user_agent' => $this->requestContext?->getUserAgent(),
                'ip_address' => $this->requestContext?->getClientIp(),
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'last_seen_at' => date('Y-m-d H:i:s'),
                'session_version' => 1,
                'remember_me' => $rememberMe ? 1 : 0
            ];

            $success = $this->db->table($this->sessionTable)->insert($dbSessionData);
            if ($success <= 0) {
                throw new \RuntimeException('Failed to insert session');
            }

            // Cache
            if ($this->cache !== null) {
                $this->cacheSessionData($dbSessionData, $tokens['access_token']);
            }

            if ($this->useTransactions) {
                $this->db->getPDO()->commit();
            }

            // Event
            $this->dispatchEvent(new SessionCreatedEvent($user, $tokens, [
                'session_uuid' => $sessionUuid,
                'ip_address' => $this->requestContext?->getClientIp(),
                'user_agent' => $this->requestContext?->getUserAgent()
            ]));

            return true;
        } catch (\Throwable $e) {
            if ($this->useTransactions) {
                $this->db->getPDO()->rollBack();
            }
            return false;
        }
    }

    /**
     * @param array{access_token: string, refresh_token?: string, expires_in?: int} $tokens
     */
    public function updateTokens(string $sessionUuid, array $tokens): bool
    {
        try {
            if ($this->useTransactions) {
                $this->db->getPDO()->beginTransaction();
            }

            $existing = $this->getBySessionUuid($sessionUuid);
            if ($existing === null) {
                throw new \RuntimeException('Session not found');
            }

            $remember = (bool)($existing['remember_me'] ?? false);
            $accessExpiresAt = $remember
                ? $this->getJwtTokenExpiration($tokens['access_token'])
                : date('Y-m-d H:i:s', time() + (int)($tokens['expires_in'] ?? $this->cacheDefaultTtl));

            $updateData = [
                'expires_at' => $accessExpiresAt,
                'last_seen_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $result = $this->db->table($this->sessionTable)
                ->where(['uuid' => $sessionUuid, 'status' => 'active'])
                ->update($updateData);

            if ($result <= 0) {
                throw new \RuntimeException('Failed to update session');
            }

            if ($this->cache !== null) {
                $updated = $existing;
                $updated['access_token'] = $tokens['access_token'];
                $updated['expires_at'] = $accessExpiresAt;
                $this->clearSessionCache($existing);
                $this->cacheSessionData($updated, $tokens['access_token']);
            }

            if ($this->useTransactions) {
                $this->db->getPDO()->commit();
            }

            return true;
        } catch (\Throwable $e) {
            if ($this->useTransactions) {
                $this->db->getPDO()->rollBack();
            }
            return false;
        }
    }

    public function getByAccessToken(string $accessToken): ?array
    {
        // Request-level cache to prevent N+1 queries within the same request
        $requestCacheKey = 'access:' . $this->hashToken($accessToken);
        if (array_key_exists($requestCacheKey, $this->requestCache)) {
            return $this->requestCache[$requestCacheKey];
        }

        // Cache lookup (use hashed token key)
        if ($this->cache !== null) {
            $tokenKey = $this->hashToken($accessToken);
            $cached = $this->resolveCacheReference("session_token_{$tokenKey}");
            if ($cached !== null) {
                $session = json_decode($cached, true);
                $this->requestCache[$requestCacheKey] = $session;
                return $session;
            }
        }

        // DB fallback via JWT session claims (sid/ver)
        $sessionUuid = $this->extractSessionIdFromAccessToken($accessToken);
        if ($sessionUuid === null || $sessionUuid === '') {
            $this->requestCache[$requestCacheKey] = null;
            return null;
        }

        $session = $this->getBySessionUuid($sessionUuid);
        if ($session === null) {
            $this->requestCache[$requestCacheKey] = null;
            return null;
        }

        $now = date('Y-m-d H:i:s');
        if (isset($session['expires_at']) && (string) $session['expires_at'] <= $now) {
            $this->requestCache[$requestCacheKey] = null;
            return null;
        }
        if (!$this->matchesSessionClaims($accessToken, $session)) {
            $this->requestCache[$requestCacheKey] = null;
            return null;
        }
        if ($this->cache !== null) {
            $this->cacheSessionData($session, $accessToken);
        }
        $this->requestCache[$requestCacheKey] = $session;
        return $session;
    }

    /**
     * Clear request-level cache (useful for testing or after session changes)
     */
    public function resetRequestCache(): void
    {
        $this->requestCache = [];
    }

    public function getByRefreshToken(string $refreshToken): ?array
    {
        $requestCacheKey = 'refresh:' . $this->hashToken($refreshToken);
        if (array_key_exists($requestCacheKey, $this->requestCache)) {
            return $this->requestCache[$requestCacheKey];
        }

        $refreshSession = $this->refreshTokenStore->getActiveSessionByRefreshToken($refreshToken);
        if ($refreshSession === null) {
            $this->requestCache[$requestCacheKey] = null;
            return null;
        }

        $sessionUuid = (string) ($refreshSession['session_uuid'] ?? '');
        $session = $sessionUuid !== '' ? $this->getBySessionUuid($sessionUuid) : null;
        if ($session === null) {
            $this->requestCache[$requestCacheKey] = null;
            return null;
        }

        $this->requestCache[$requestCacheKey] = $session;
        return $session;
    }

    public function revoke(string $sessionIdOrToken): bool
    {
        try {
            if ($this->useTransactions) {
                $this->db->getPDO()->beginTransaction();
            }

            // Locate session by UUID, access token, or refresh token.
            $session = $this->getBySessionUuid($sessionIdOrToken)
                ?? $this->getByAccessToken($sessionIdOrToken)
                ?? $this->getByRefreshToken($sessionIdOrToken);
            if ($session === null) {
                return false;
            }

            $result = $this->db->table($this->sessionTable)
                ->where(['uuid' => $session['uuid']])
                ->update([
                    'status' => 'revoked',
                    'revoked_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            if ($result <= 0) {
                throw new \RuntimeException('Failed to revoke session');
            }

            if ($this->cache !== null) {
                $this->clearSessionCache($session);
            }

            if ($this->useTransactions) {
                $this->db->getPDO()->commit();
            }

            $this->dispatchEvent(new SessionDestroyedEvent(
                (string) ($session['uuid'] ?? $sessionIdOrToken),
                $session['user_uuid'] ?? null,
                'revoked',
                [
                    'session_uuid' => $session['uuid'],
                    'ip_address' => $this->requestContext?->getClientIp(),
                    'user_agent' => $this->requestContext?->getUserAgent()
                ]
            ));

            return true;
        } catch (\Throwable $e) {
            if ($this->useTransactions) {
                $this->db->getPDO()->rollBack();
            }
            return false;
        }
    }

    public function revokeAllForUser(string $userUuid): bool
    {
        try {
            if ($this->useTransactions) {
                $this->db->getPDO()->beginTransaction();
            }

            $sessions = $this->db->table($this->sessionTable)
                ->select(['*'])
                ->where(['user_uuid' => $userUuid, 'status' => 'active'])
                ->get();

            $result = $this->db->table($this->sessionTable)
                ->where(['user_uuid' => $userUuid, 'status' => 'active'])
                ->update(['status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s')]);

            if ($result <= 0) {
                throw new \RuntimeException('Failed to revoke sessions');
            }

            if ($this->cache !== null) {
                foreach ($sessions as $session) {
                    $this->clearSessionCache($session);
                }
            }

            if ($this->useTransactions) {
                $this->db->getPDO()->commit();
            }

            return true;
        } catch (\Throwable $e) {
            if ($this->useTransactions) {
                $this->db->getPDO()->rollBack();
            }
            return false;
        }
    }

    public function cleanupExpired(): int
    {
        $now = date('Y-m-d H:i:s');
        $cleanedCount = 0;
        try {
            $expiredSessions = $this->db->table($this->sessionTable)
                ->select(['*'])
                ->where('expires_at', '<', $now)
                ->where(['status' => 'active'])
                ->get();
            if ($expiredSessions !== []) {
                $ids = array_column($expiredSessions, 'uuid');
                $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                $sql = "UPDATE {$this->sessionTable} SET status = 'expired', updated_at = ? "
                     . "WHERE uuid IN ({$placeholders})";
                $params = array_merge([$now], $ids);
                $stmt = $this->db->getPDO()->prepare($sql);
                $success = $stmt->execute($params);
            } else {
                $success = true;
            }
            if ($success) {
                $cleanedCount = count($expiredSessions);
                if ($this->cache !== null) {
                    foreach ($expiredSessions as $session) {
                        $this->clearSessionCache($session);
                    }
                }
            }
        } catch (\Throwable $e) {
            // log silently
        }
        return $cleanedCount;
    }

    public function health(): array
    {
        $health = [
            'database' => ['status' => 'unknown', 'response_time' => null],
            'cache' => ['status' => 'unknown', 'response_time' => null],
            'overall' => 'unknown'
        ];
        try {
            $start = microtime(true);
            $this->db->table($this->sessionTable)->count();
            $health['database'] = [
                'status' => 'healthy',
                'response_time' => round((microtime(true) - $start) * 1000, 2)
            ];
        } catch (\Throwable $e) {
            $health['database'] = ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
        if ($this->cache !== null) {
            try {
                $start = microtime(true);
                $this->cache->set('health_check', 'ok', 5);
                $this->cache->get('health_check');
                $health['cache'] = [
                    'status' => 'healthy',
                    'response_time' => round((microtime(true) - $start) * 1000, 2)
                ];
            } catch (\Throwable $e) {
                $health['cache'] = ['status' => 'unhealthy', 'error' => $e->getMessage()];
            }
        } else {
            $health['cache'] = ['status' => 'disabled'];
        }
        $dbHealthy = $health['database']['status'] === 'healthy';
        $cacheHealthy = $this->cache === null || $health['cache']['status'] === 'healthy';
        $health['overall'] = ($dbHealthy && $cacheHealthy) ? 'healthy' : 'degraded';
        return $health;
    }

    public function isConsistent(string $sessionIdOrToken): bool
    {
        if ($this->cache === null) {
            return true;
        }
        try {
            $dbSession = $this->getSessionFromDatabase($sessionIdOrToken);
            // Try hashed refresh key first, then legacy key
            $cachedData = null;
            $hashedKey = 'session_refresh_' . $this->hashToken($sessionIdOrToken);
            try {
                $cachedData = $this->cache->get($hashedKey);
            } catch (\Throwable) {
                // ignore
            }
            if ($cachedData === null) {
                try {
                    $legacyKey = CacheHelper::sessionKey($sessionIdOrToken, 'refresh');
                    $cachedData = $this->cache->get($legacyKey);
                } catch (\Throwable) {
                    // ignore
                }
            }
            $cacheSession = ($cachedData !== null) ? json_decode($cachedData, true) : null;
            if ($dbSession === null && $cacheSession === null) {
                return true;
            }
            if ($dbSession === null || $cacheSession === null) {
                return false;
            }
            return (string) ($dbSession['uuid'] ?? '') === (string) ($cacheSession['uuid'] ?? '')
                && (int) ($dbSession['session_version'] ?? 1) === (int) ($cacheSession['session_version'] ?? 1);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function listByProvider(string $provider): array
    {
        try {
            $manager = $this->resolveSessionCacheManager();
            return $manager->getSessionsByProvider($provider);
        } catch (\Throwable) {
            return [];
        }
    }

    public function listByUser(string $userUuid): array
    {
        try {
            $manager = $this->resolveSessionCacheManager();
            return $manager->getUserSessions($userUuid);
        } catch (\Throwable) {
            return [];
        }
    }

    // Helpers

    public function getAccessTtl(string $provider, bool $rememberMe = false): int
    {
        $providerConfigs = (array) $this->getConfig('security.authentication_providers', []);
        if (isset($providerConfigs[$provider]['session_ttl'])) {
            return (int) $providerConfigs[$provider]['session_ttl'];
        }

        if ($rememberMe) {
            return (int) $this->getConfig('session.remember_expiration', 30 * 24 * 3600);
        }

        return (int) $this->getConfig('session.access_token_lifetime', 3600);
    }

    public function getRefreshTtl(string $provider, bool $rememberMe = false): int
    {
        if ($rememberMe) {
            return (int) $this->getConfig('session.remember_expiration', 60 * 24 * 3600);
        }

        return (int) $this->getConfig('session.refresh_token_lifetime', 7 * 24 * 3600);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function cacheSessionData(array $session, ?string $accessToken = null, ?string $refreshToken = null): void
    {
        if ($accessToken === null && $refreshToken === null) {
            return;
        }
        $canonicalKey = "session_data_{$session['uuid']}";
        $sessionJson = json_encode($session);
        $refreshTtl = (int) $this->getConfig('session.refresh_token_lifetime', 604800);
        $maxTtl = max($this->cacheDefaultTtl, $refreshTtl);
        $this->cache?->set($canonicalKey, $sessionJson, $maxTtl);
        if ($accessToken !== null) {
            $tokenKey = "session_token_" . $this->hashToken($accessToken);
            $this->cache?->set($tokenKey, $canonicalKey, $this->cacheDefaultTtl);
        }
        if ($refreshToken !== null) {
            $refreshKey = "session_refresh_" . $this->hashToken($refreshToken);
            $this->cache?->set($refreshKey, $canonicalKey, $refreshTtl);
        }
    }

    private function resolveCacheReference(string $key): ?string
    {
        $cached = $this->cache?->get($key);
        if ($cached === null) {
            return null;
        }
        if (is_string($cached) && str_starts_with($cached, 'session_data_')) {
            return $this->cache?->get($cached);
        }
        return $cached;
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if (function_exists('config') && $this->context !== null) {
            return config($this->context, $key, $default);
        }

        return $default;
    }

    private function resolveSessionCacheManager(): SessionCacheManager
    {
        if ($this->context !== null) {
            return container($this->context)->get(SessionCacheManager::class);
        }

        if ($this->cache === null) {
            throw new \RuntimeException('CacheStore is required to create SessionCacheManager.');
        }

        return new SessionCacheManager($this->cache, $this->context);
    }

    private function dispatchEvent(object $event): void
    {
        if ($this->context === null) {
            return;
        }

        try {
            app($this->context, EventService::class)->dispatch($event);
        } catch (\Throwable) {
            // Best-effort only
        }
    }

    /**
     * @param array<string, mixed> $session
     */
    private function clearSessionCache(array $session): void
    {
        // Refresh-token cache entries are managed by RefreshTokenStore lifecycle.
        if (isset($session['access_token']) && is_string($session['access_token'])) {
            $this->cache?->delete("session_token_" . $this->hashToken($session['access_token']));
        }
        if (isset($session['uuid'])) {
            $this->cache?->delete("session_data_{$session['uuid']}");
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getSessionFromDatabase(string $sessionIdentifier): ?array
    {
        $result = $this->db->table($this->sessionTable)
            ->select(['*'])
            ->where(['uuid' => $sessionIdentifier, 'status' => 'active'])
            ->get();
        return $result !== [] ? $result[0] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getBySessionUuid(string $sessionUuid): ?array
    {
        if ($sessionUuid === '') {
            return null;
        }

        $result = $this->db->table($this->sessionTable)
            ->select(['*'])
            ->where(['uuid' => $sessionUuid, 'status' => 'active'])
            ->limit(1)
            ->get();

        return $result !== [] ? $result[0] : null;
    }

    private function extractSessionIdFromAccessToken(string $accessToken): ?string
    {
        if (substr_count($accessToken, '.') !== 2) {
            return null;
        }

        $claims = JWTService::getPayloadWithoutValidation($accessToken);
        if (!is_array($claims)) {
            return null;
        }

        $sid = $claims['sid'] ?? null;
        return is_string($sid) && $sid !== '' ? $sid : null;
    }

    private function getJwtTokenExpiration(string $jwtToken): string
    {
        try {
            $parts = explode('.', $jwtToken);
            if (count($parts) !== 3) {
                throw new \RuntimeException('Invalid token');
            }
            $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
            if ($decoded === false) {
                throw new \RuntimeException('Decode failed');
            }
            $payload = json_decode($decoded, true);
            if (!is_array($payload) || !array_key_exists('exp', $payload)) {
                throw new \RuntimeException('Missing exp');
            }
            $exp = (int) $payload['exp'];
            return date('Y-m-d H:i:s', $exp);
        } catch (\Throwable) {
            return date(
                'Y-m-d H:i:s',
                time() + (int) $this->getConfig('session.access_token_lifetime', 3600)
            );
        }
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Ensure access token claims match server-side session versioning.
     *
     * @param array<string, mixed> $session
     */
    private function matchesSessionClaims(string $accessToken, array $session): bool
    {
        $claims = JWTService::getPayloadWithoutValidation($accessToken);
        if (!is_array($claims)) {
            return true;
        }

        $sid = isset($claims['sid']) ? (string) $claims['sid'] : '';
        $ver = isset($claims['ver']) ? (int) $claims['ver'] : 0;
        if ($sid === '' || $ver <= 0) {
            return false;
        }

        $sessionUuid = (string) ($session['uuid'] ?? '');
        $sessionVersion = (int) ($session['session_version'] ?? 1);
        return $sid === $sessionUuid && $ver === $sessionVersion;
    }
}
