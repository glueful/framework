<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Cache\CacheStore;
use Glueful\Database\Connection;
use Glueful\Events\Auth\SessionCreatedEvent;
use Glueful\Events\Auth\SessionDestroyedEvent;
use Glueful\Events\Event;
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

    /**
     * @param CacheStore<string>|null $cache
     */
    public function __construct(
        ?CacheStore $cache = null,
        ?Connection $db = null,
        ?RequestContext $requestContext = null,
        bool $useTransactions = true
    ) {
        $this->cache = $cache ?? CacheHelper::createCacheInstance();
        $this->db = $db ?? new Connection();
        $this->requestContext = $requestContext ?? RequestContext::fromGlobals();
        $this->useTransactions = $useTransactions;
        $this->cacheDefaultTtl = (int) (function_exists('config') ? config('session.access_token_lifetime', 900) : 900);
    }

    /**
     * @param array<string, mixed> $user
     * @param array{access_token: string, refresh_token: string, expires_in?: int} $tokens
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
            $refreshExpiresAt = date('Y-m-d H:i:s', time() + $this->getRefreshTtl($provider, $rememberMe));

            // Prepare DB row
            $sessionUuid = $user['session_id'] ?? Utils::generateNanoID();
            $dbSessionData = [
                'uuid' => $sessionUuid,
                'user_uuid' => $user['uuid'],
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'access_expires_at' => $accessExpiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
                'provider' => $provider,
                'user_agent' => $this->requestContext?->getUserAgent(),
                'ip_address' => $this->requestContext?->getClientIp(),
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'last_token_refresh' => date('Y-m-d H:i:s'),
                'token_fingerprint' => hash('sha256', $tokens['access_token']),
                'remember_me' => $rememberMe ? 1 : 0
            ];

            $success = $this->db->table($this->sessionTable)->insert($dbSessionData);
            if ($success <= 0) {
                throw new \RuntimeException('Failed to insert session');
            }

            // Cache
            if ($this->cache !== null) {
                $this->cacheSessionData($dbSessionData, $tokens['access_token'], $tokens['refresh_token']);
            }

            if ($this->useTransactions) {
                $this->db->getPDO()->commit();
            }

            // Event
            Event::dispatch(new SessionCreatedEvent($user, $tokens, [
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
     * @param array{access_token: string, refresh_token: string, expires_in?: int} $tokens
     */
    public function updateTokens(string $sessionIdOrRefreshToken, array $tokens): bool
    {
        try {
            if ($this->useTransactions) {
                $this->db->getPDO()->beginTransaction();
            }

            $existing = $this->getByRefreshToken($sessionIdOrRefreshToken);
            if ($existing === null) {
                throw new \RuntimeException('Session not found');
            }

            $remember = (bool)($existing['remember_me'] ?? false);
            $provider = (string)($existing['provider'] ?? 'jwt');
            $accessExpiresAt = $remember
                ? $this->getJwtTokenExpiration($tokens['access_token'])
                : date('Y-m-d H:i:s', time() + (int)($tokens['expires_in'] ?? $this->cacheDefaultTtl));
            $refreshExpiresAt = date('Y-m-d H:i:s', time() + $this->getRefreshTtl($provider, $remember));

            $updateData = [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'access_expires_at' => $accessExpiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
                'last_token_refresh' => date('Y-m-d H:i:s'),
                'token_fingerprint' => hash('sha256', $tokens['access_token'])
            ];

            $result = $this->db->table($this->sessionTable)
                ->where(['refresh_token' => $sessionIdOrRefreshToken, 'status' => 'active'])
                ->update($updateData);

            if ($result <= 0) {
                throw new \RuntimeException('Failed to update session');
            }

            if ($this->cache !== null) {
                $updated = $existing;
                $updated['access_token'] = $tokens['access_token'];
                $updated['refresh_token'] = $tokens['refresh_token'];
                $updated['access_expires_at'] = $accessExpiresAt;
                $updated['refresh_expires_at'] = $refreshExpiresAt;
                $this->clearSessionCache($existing);
                $this->cacheSessionData($updated, $tokens['access_token'], $tokens['refresh_token']);
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
        // Cache lookup (use hashed token key)
        if ($this->cache !== null) {
            $tokenKey = $this->hashToken($accessToken);
            $cached = $this->resolveCacheReference("session_token_{$tokenKey}");
            if ($cached !== null) {
                return json_decode($cached, true);
            }
        }

        // DB fallback with expiration check
        $now = date('Y-m-d H:i:s');
        $result = $this->db->table($this->sessionTable)
            ->select(['*'])
            ->where(['access_token' => $accessToken, 'status' => 'active'])
            ->where('access_expires_at', '>', $now)
            ->get();
        if ($result === []) {
            return null;
        }
        $session = $result[0];
        if ($this->cache !== null) {
            $this->cacheSessionData($session, $accessToken, null);
        }
        return $session;
    }

    public function getByRefreshToken(string $refreshToken): ?array
    {
        if ($this->cache !== null) {
            $tokenKey = $this->hashToken($refreshToken);
            $cached = $this->resolveCacheReference("session_refresh_{$tokenKey}");
            if ($cached !== null) {
                return json_decode($cached, true);
            }
        }
        $result = $this->db->table($this->sessionTable)
            ->select(['*'])
            ->where(['refresh_token' => $refreshToken, 'status' => 'active'])
            ->get();
        if ($result === []) {
            return null;
        }
        $session = $result[0];
        if ($this->cache !== null) {
            $this->cacheSessionData($session, null, $refreshToken);
        }
        return $session;
    }

    public function revoke(string $sessionIdOrToken): bool
    {
        try {
            if ($this->useTransactions) {
                $this->db->getPDO()->beginTransaction();
            }

            // Locate session by refresh or access token
            $session = $this->getByRefreshToken($sessionIdOrToken) ?? $this->getByAccessToken($sessionIdOrToken);
            if ($session === null) {
                return false;
            }

            $result = $this->db->table($this->sessionTable)
                ->where(['uuid' => $session['uuid']])
                ->update(['status' => 'revoked']);

            if ($result <= 0) {
                throw new \RuntimeException('Failed to revoke session');
            }

            if ($this->cache !== null) {
                $this->clearSessionCache($session);
            }

            if ($this->useTransactions) {
                $this->db->getPDO()->commit();
            }

            Event::dispatch(new SessionDestroyedEvent(
                $session['access_token'] ?? $sessionIdOrToken,
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
                ->where('refresh_expires_at', '<', $now)
                ->where(['status' => 'active'])
                ->get();
            if ($expiredSessions !== []) {
                $ids = array_column($expiredSessions, 'uuid');
                $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                $sql = "UPDATE {$this->sessionTable} SET status = 'expired', expired_at = ? "
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
            try { $cachedData = $this->cache->get($hashedKey); } catch (\Throwable) { /* ignore */ }
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
            return $dbSession['access_token'] === $cacheSession['access_token']
                && $dbSession['refresh_token'] === $cacheSession['refresh_token'];
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function listByProvider(string $provider): array
    {
        try {
            $manager = container()->get(SessionCacheManager::class);
            return $manager->getSessionsByProvider($provider);
        } catch (\Throwable) {
            return [];
        }
    }

    public function listByUser(string $userUuid): array
    {
        try {
            $manager = container()->get(SessionCacheManager::class);
            return $manager->getUserSessions($userUuid);
        } catch (\Throwable) {
            return [];
        }
    }

    // Helpers

    public function getAccessTtl(string $provider, bool $rememberMe = false): int
    {
        $providerConfigs = (array) (function_exists('config') ? config('security.authentication_providers', []) : []);
        if (isset($providerConfigs[$provider]['session_ttl'])) {
            return (int) $providerConfigs[$provider]['session_ttl'];
        }

        if ($rememberMe) {
            return (int) (
                function_exists('config')
                    ? config('session.remember_expiration', 30 * 24 * 3600)
                    : 30 * 24 * 3600
            );
        }

        return (int) (
            function_exists('config')
                ? config('session.access_token_lifetime', 3600)
                : 3600
        );
    }

    public function getRefreshTtl(string $provider, bool $rememberMe = false): int
    {
        if ($rememberMe) {
            return (int) (
                function_exists('config')
                    ? config('session.remember_expiration', 60 * 24 * 3600)
                    : 60 * 24 * 3600
            );
        }

        return (int) (
            function_exists('config')
                ? config('session.refresh_token_lifetime', 7 * 24 * 3600)
                : 7 * 24 * 3600
        );
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
        $refreshTtl = (int) (function_exists('config') ? config('session.refresh_token_lifetime', 604800) : 604800);
        $maxTtl = max($this->cacheDefaultTtl, $refreshTtl);
        $this->cache?->set($canonicalKey, $sessionJson, $maxTtl);
        if ($accessToken !== null) {
            $this->cache?->set("session_token_" . $this->hashToken($accessToken), $canonicalKey, $this->cacheDefaultTtl);
        }
        if ($refreshToken !== null) {
            $this->cache?->set("session_refresh_" . $this->hashToken($refreshToken), $canonicalKey, $refreshTtl);
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

    /**
     * @param array<string, mixed> $session
     */
    private function clearSessionCache(array $session): void
    {
        if (isset($session['access_token'])) {
            $this->cache?->delete("session_token_" . $this->hashToken((string)$session['access_token']));
        }
        if (isset($session['refresh_token'])) {
            $this->cache?->delete("session_refresh_" . $this->hashToken((string)$session['refresh_token']));
        }
        if (isset($session['uuid'])) {
            $this->cache?->delete("session_data:{$session['uuid']}");
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getSessionFromDatabase(string $sessionIdentifier): ?array
    {
        $result = $this->db->table($this->sessionTable)
            ->select(['*'])
            ->where(['refresh_token' => $sessionIdentifier, 'status' => 'active'])
            ->get();
        if ($result !== []) {
            return $result[0];
        }
        $result = $this->db->table($this->sessionTable)
            ->select(['*'])
            ->where(['access_token' => $sessionIdentifier, 'status' => 'active'])
            ->get();
        return $result !== [] ? $result[0] : null;
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
                time() + (int) (
                    function_exists('config')
                        ? config('session.access_token_lifetime', 3600)
                        : 3600
                )
            );
        }
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
