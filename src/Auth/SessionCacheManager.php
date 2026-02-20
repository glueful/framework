<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Interfaces\Permission\PermissionProviderInterface;
use Glueful\Interfaces\Permission\RbacPermissionProviderInterface;
use Glueful\Queue\QueueManager;
use Glueful\Events\EventService;
use Glueful\Events\Auth\SessionCachedEvent;

/**
 * Session Cache Management System
 *
 * Manages cached user session data:
 * - Session data storage and retrieval
 * - Session expiration handling
 * - Session data structure
 * - Multi-provider authentication support
 *
 * This class focuses purely on session data management,
 * delegating all token operations to TokenManager.
 */
/**
 * @phpstan-type AuthSessionUser array{
 *   uuid?: string,
 *   permissions?: array<string, list<string>>|list<string>,
 *   roles?: list<string>
 * }
 * @phpstan-type AuthSessionPayload array{
 *   id: string,
 *   token: string,
 *   user: AuthSessionUser,
 *   created_at: int,
 *   last_activity: int,
 *   provider: string,
 *   permissions_loaded_at?: int
 * }
 */
class SessionCacheManager
{
    use \Glueful\Auth\Traits\ResolvesSessionStore;

    private const SESSION_PREFIX = 'session:';
    private const PROVIDER_INDEX_PREFIX = 'provider:';
    private const PERMISSION_CACHE_PREFIX = 'user_permissions:';
    private const USER_SESSION_INDEX_PREFIX = 'user_sessions:';
    private const DEFAULT_TTL = 3600; // 1 hour
    private const PERMISSIONS_TTL = 1800; // 30 minutes

    /**
     * @var CacheStore<mixed> Cache driver service
     */
    private CacheStore $cache;

    /** @var int Session TTL */
    private int $ttl;

    /**
     * @var array<string, array<string, mixed>> Provider configurations
     */
    private array $providerConfigs;

    /** @var PermissionProviderInterface|null Permission service */
    private ?PermissionProviderInterface $permissionService = null;

    /** @var QueueManager|null Queue service */
    private ?QueueManager $queueService = null;
    private ?ApplicationContext $context;

    /**
     * Constructor
     *
     * @param CacheStore<mixed> $cache Cache driver service
     * @param ApplicationContext|null $context
     */
    public function __construct(CacheStore $cache, ?ApplicationContext $context = null)
    {
        $this->cache = $cache;
        $this->context = $context;
        $this->ttl = (int) $this->getConfig('session.access_token_lifetime', self::DEFAULT_TTL);
        $this->providerConfigs = (array) $this->getConfig('security.authentication_providers', []);
        $this->initializeServices();
    }

    /**
     * Initialize services via dependency injection
     */
    private function initializeServices(): void
    {
        try {
            // Try to get services from DI container using the container() helper
            $container = $this->getContainer();
            if ($container !== null) {
                if ($container->has(QueueManager::class)) {
                    $this->queueService = $container->get(QueueManager::class);
                }
            }

            // Initialize PermissionManager's provider for efficient permission loading
            if (class_exists('Glueful\\Permissions\\PermissionManager')) {
                $manager = \Glueful\Permissions\PermissionManager::getInstance();
                if ($manager->hasActiveProvider()) {
                    $provider = $manager->getProvider();
                    if ($provider instanceof PermissionProviderInterface) {
                        $this->permissionService = $provider;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Container not available or services not found - this is expected during early initialization
            // Will fall back to container-based repositories for permissions
        }
    }

    /**
     * Set permission service (for testing or manual DI)
     */
    public function setPermissionService(?object $service): void
    {
        $this->permissionService = $service instanceof PermissionProviderInterface ? $service : null;
    }

    /**
     * Set queue service (for testing or manual DI)
     */
    public function setQueueService(?object $service): void
    {
        $this->queueService = $service instanceof QueueManager ? $service : null;
    }

    /**
     * Store new session
     *
     * Creates and stores session data in cache.
     * Supports multiple authentication providers.
     *
     * @param array<string, mixed> $userData User and permission data
     * @param string $token Access token for the session
     * @param string|null $provider Authentication provider (jwt, apikey, etc.)
     * @param int|null $ttl Custom time-to-live in seconds
     * @return bool Success status
     */
    public function storeSession(
        array $userData,
        string $token,
        ?string $provider = 'jwt',
        ?int $ttl = null
    ): bool {

        // Use existing session_id from userData if provided, otherwise generate new one
        $sessionId = $userData['session_id'] ?? $this->generateSessionId();

    // Use custom TTL if provided, or provider-specific TTL if available
        $sessionTtl = $ttl ?? $this->getProviderTtl($provider);

    // Pre-load permissions if RBAC extension is available
        $enhancedUserData = $this->enhanceUserDataWithPermissions($userData);

        $sessionData = [
            'id' => $sessionId,
            'token' => $token,
            'user' => $enhancedUserData,
            'created_at' => time(),
            'last_activity' => time(),
            'provider' => $provider ?? 'jwt', // Store the provider used
            'permissions_loaded_at' => time() // Track when permissions were cached
        ];

    // Store session data
        $success = $this->cache->set(
            self::SESSION_PREFIX . $sessionId,
            $sessionData,
            $sessionTtl
        );

        if ($success) {
            // Index this session by provider for easier management
            $this->indexSessionByProvider($provider ?? 'jwt', $sessionId, $sessionTtl);

            // Index session by user UUID for quick lookup
            if (isset($enhancedUserData['uuid'])) {
                $this->indexSessionByUser($enhancedUserData['uuid'], $sessionId, $sessionTtl);
            }
            error_log("Session stored successfully with ID: {$sessionId} for user: {$enhancedUserData['uuid']}");

            // Cache permissions separately for faster access
            if (isset($enhancedUserData['permissions']) && isset($enhancedUserData['uuid'])) {
                $this->cacheUserPermissions($enhancedUserData['uuid'], $enhancedUserData['permissions']);
            }

            // Skip audit logging here - session creation is already logged in TokenManager

            // Dispatch post-cache event with a mutable payload snapshot. If listeners
            // modify the payload, persist changes back to cache with the same TTL.
            try {
                $event = new SessionCachedEvent($token, $sessionId, $provider ?? 'jwt', $sessionData);
                if ($this->context !== null) {
                    app($this->context, EventService::class)->dispatch($event);
                }
                $updated = $event->getPayload();
                if ($updated !== $sessionData) {
                    $this->cache->set(self::SESSION_PREFIX . $sessionId, $updated, $sessionTtl);
                }
            } catch (\Throwable $e) {
                // Do not break session creation if event dispatch fails
                error_log('SessionCachedEvent dispatch failed: ' . $e->getMessage());
            }

            return true;
        }

        return false;
    }

    /**
     * Index session by provider type
     *
     * Creates a secondary index of sessions organized by provider.
     * Useful for provider-specific session operations.
     *
     * @param string $provider Provider name (jwt, apikey, etc.)
     * @param string $sessionId Session identifier
     * @param int $ttl Time-to-live in seconds
     * @return bool Success status
     */
    private function indexSessionByProvider(string $provider, string $sessionId, int $ttl): bool
    {
        $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
        $sessions = $this->cache->get($indexKey) ?? [];

        // Add session to the provider's index
        $sessions[] = $sessionId;

        // Remove any duplicates
        $sessions = array_unique($sessions);

        return $this->cache->set($indexKey, $sessions, $ttl);
    }

    /**
     * Index session by user UUID
     *
     * Creates a secondary index of sessions organized by user.
     * Enables efficient lookup of all sessions for a specific user.
     *
     * @param string $userUuid User UUID
     * @param string $sessionId Session identifier
     * @param int $ttl Time-to-live in seconds
     * @return bool Success status
     */
    private function indexSessionByUser(string $userUuid, string $sessionId, int $ttl): bool
    {
        $indexKey = self::USER_SESSION_INDEX_PREFIX . $userUuid;
        $sessions = $this->cache->get($indexKey) ?? [];

        // Add session to the user's index
        $sessions[] = $sessionId;

        // Remove any duplicates
        $sessions = array_unique($sessions);

        return $this->cache->set($indexKey, $sessions, $ttl);
    }

    /**
     * Remove session from user index
     *
     * Removes a session ID from a user's index list.
     *
     * @param string $userUuid User UUID
     * @param string $sessionId Session ID to remove
     * @return bool Success status
     */
    private function removeSessionFromUserIndex(string $userUuid, string $sessionId): bool
    {
        $indexKey = self::USER_SESSION_INDEX_PREFIX . $userUuid;
        $sessions = $this->cache->get($indexKey) ?? [];

        // Remove session from the index
        $sessions = array_diff($sessions, [$sessionId]);

        // If no sessions left, delete the index entirely
        if ($sessions === []) {
            return $this->cache->delete($indexKey);
        }

        // Use default TTL for user session index
        return $this->cache->set($indexKey, $sessions, self::DEFAULT_TTL);
    }

    /**
     * Get sessions by provider
     *
     * Retrieves all sessions for a specific authentication provider.
     *
     * @param string $provider Provider name (jwt, apikey, etc.)
     * @return array Array of session data
     * @phpstan-return list<AuthSessionPayload>
     */
    public function getSessionsByProvider(string $provider): array
    {


        $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
        $sessionIds = $this->cache->get($indexKey) ?? [];

        if ($sessionIds === []) {
            return [];
        }

        // Batch retrieve sessions to avoid N+1 cache calls
        return $this->batchGetSessions($sessionIds);
    }

    /**
     * Get session by token
     *
     * Retrieves and refreshes session data.
     *
     * @param string $token Authentication token
     * @param string|null $provider Optional provider hint
     * @return array<string, mixed>|null Session data or null if invalid
     * @phpstan-return AuthSessionPayload|null
     */
    public function getSession(string $token, ?string $provider = null): ?array
    {
        // Use SessionStore to get session data directly
        $sessionStore = $this->getSessionStore();
        $sessionData = $sessionStore->getByAccessToken($token);
        if ($sessionData === null) {
            return null;
        }

        // If provider is specified, validate it matches the session's provider
        if ($provider !== null && isset($sessionData['provider']) && $sessionData['provider'] !== $provider) {
            return null;
        }

        // Convert database session to cache format for backward compatibility
        $session = [
            'id' => $sessionData['uuid'],
            'token' => $token,
            'user' => [
                'uuid' => isset($sessionData['user_uuid']) ? $sessionData['user_uuid'] : $sessionData['uuid'],
                'permissions' => [],
                'roles' => []
            ],
            'created_at' => strtotime($sessionData['created_at']),
            'last_activity' => time(),
            'provider' => $sessionData['provider'] ?? 'jwt',
            'permissions_loaded_at' => time() // Mark as just loaded
        ];

        // Try to enhance with cached permissions
        $enhancedUserData = $this->enhanceUserDataWithPermissions($session['user']);
        $session['user'] = $enhancedUserData;

        return $session;
    }

    /**
     * Get provider-specific TTL value
     *
     * Returns the correct TTL value based on provider type and configuration.
     *
     * @param string $provider Provider name (jwt, apikey, etc.)
     * @return int Time-to-live in seconds
     */
    private function getProviderTtl(string $provider): int
    {
        if (isset($this->providerConfigs[$provider]['session_ttl'])) {
            return (int)$this->providerConfigs[$provider]['session_ttl'];
        }

        return $this->ttl;
    }

    /**
     * Remove session
     *
     * Deletes session data from cache and provider index.
     *
     * @param string $sessionId Session identifier
     * @return bool Success status
     */
    public function removeSession(string $sessionId): bool
    {


        // Get session to find its provider
        $session = $this->cache->get(self::SESSION_PREFIX . $sessionId);

        // Remove from provider index if provider information is available
        if ($session !== null && isset($session['provider'])) {
            $this->removeSessionFromProviderIndex($session['provider'], $sessionId);
        }

        // Remove from user index if user information is available
        if ($session !== null && isset($session['user']['uuid'])) {
            $this->removeSessionFromUserIndex($session['user']['uuid'], $sessionId);
        }

        return $this->cache->delete(self::SESSION_PREFIX . $sessionId);
    }

    /**
     * Remove session from provider index
     *
     * Removes a session ID from a provider's index list.
     *
     * @param string $provider Provider name
     * @param string $sessionId Session ID to remove
     * @return bool Success status
     */
    private function removeSessionFromProviderIndex(string $provider, string $sessionId): bool
    {
        $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
        $sessions = $this->cache->get($indexKey) ?? [];

        // Remove session from the index
        $sessions = array_diff($sessions, [$sessionId]);

        // Get the TTL for this provider type
        $ttl = $this->getProviderTtl($provider);

        return $this->cache->set($indexKey, $sessions, $ttl);
    }

    /**
     * Destroy session by token
     *
     * Removes both session data and token mapping.
     *
     * @param string $token Authentication token
     * @param string|null $provider Optional provider hint
     * @return bool Success status
     */
    public function destroySession(string $token, ?string $provider = null): bool
    {
        // Use SessionStore to fetch the session
        $sessionStore = $this->getSessionStore();
        $sessionData = $sessionStore->getByAccessToken($token);

        if ($sessionData === null) {
            return false;
        }

        // If provider is specified, validate it matches
        if ($provider !== null && isset($sessionData['provider']) && $sessionData['provider'] !== $provider) {
            return false;
        }

        // Revoke session via SessionStore
        $revoked = $sessionStore->revoke($token);

        // Clean up cache entries if we have session ID
        if ($revoked && isset($sessionData['uuid'])) {
            $this->removeSession($sessionData['uuid']);
        }

        return $revoked;
    }

    /**
     * Update session data
     *
     * Updates session with new data and token.
     *
     * @param string $oldToken Current token
     * @param array<string, mixed> $newData Updated session data
     * @param string $newToken New authentication token
     * @param string|null $provider Provider name (optional)
     * @return bool Success status
     */
    public function updateSession(
        string $oldToken,
        array $newData,
        string $newToken,
        ?string $provider = null
    ): bool {
        // Use SessionStore to update session tokens
        $sessionStore = $this->getSessionStore();

        // Get current session data
        $currentSession = $sessionStore->getByAccessToken($oldToken);
        if ($currentSession === null) {
            return false;
        }

        $sessionProvider = $provider ?? ($currentSession['provider'] ?? 'jwt');

        // Prepare new tokens array, compute TTL via SessionStore policy
        $store = $this->getSessionStore();
        $newTokens = [
            'access_token' => $newToken,
            'expires_in' => $store->getAccessTtl($sessionProvider)
        ];

        // Update session tokens via SessionStore
        $success = $sessionStore->updateTokens((string) $currentSession['uuid'], $newTokens);

        if ($success) {
            // Update cache session data
            $cacheSession = array_merge($newData, [
                'provider' => $sessionProvider,
                'last_activity' => time()
            ]);

            $ttl = $store->getAccessTtl($sessionProvider);
            $this->cache->set(
                self::SESSION_PREFIX . $currentSession['uuid'],
                $cacheSession,
                $ttl
            );
        }

        return $success;
    }

    /**
     * Get current session
     *
     * Retrieves session for current request.
     *
     * @param string|null $provider Optional provider hint
     * @return array|null Session data or null if not authenticated
     * @phpstan-return AuthSessionPayload|null
     */
    public function getCurrentSession(?string $provider = null): ?array
    {
        $token = $this->getTokenManager()->extractTokenFromRequest();
        if ($token === null || $token === '') {
            return null;
        }

        return $this->getSession($token, $provider);
    }

    /**
     * Get session with permission validation
     *
     * Retrieves session data and checks if permissions need refresh.
     *
     * @param string $token Authentication token
     * @param string|null $provider Optional provider hint
     * @return array|null Session data or null if invalid
     * @phpstan-return AuthSessionPayload|null
     */
    public function getSessionWithValidPermissions(string $token, ?string $provider = null): ?array
    {
        $session = $this->getSession($token, $provider);

        if ($session === null) {
            return null;
        }

        // Check if permissions need refresh
        $permissionsAge = time() - ($session['permissions_loaded_at'] ?? 0);

        if ($permissionsAge > self::PERMISSIONS_TTL) {
            // Queue background permission refresh for next request
            $userUuid = $session['user']['uuid'] ?? null;
            if ($userUuid !== null && $userUuid !== '') {
                $this->queuePermissionRefresh($userUuid, $token);
            }
        }

        return $session;
    }

    /**
     * Generate unique session identifier
     *
     * @return string Session ID
     */
    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Invalidate all sessions for a provider
     *
     * Removes all sessions associated with a specific authentication provider.
     * Useful for security events or when changing provider configuration.
     *
     * @param string $provider Provider name (jwt, apikey, etc.)
     * @return bool Success status
     */
    public function invalidateProviderSessions(string $provider): bool
    {


        $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
        $sessionIds = $this->cache->get($indexKey) ?? [];

        $success = true;
        foreach ($sessionIds as $sessionId) {
            $session = $this->cache->get(self::SESSION_PREFIX . $sessionId);
            if ($session !== null && isset($session['token'])) {
                // Use destroySession to properly clean up token mappings as well
                $result = $this->destroySession($session['token'], $provider);
                $success = $success && $result;
            }
        }

        // Clear the provider index
        $this->cache->delete($indexKey);


        return $success;
    }

    /**
     * Enhance user data with permissions and roles
     *
     * Pre-loads user permissions and roles using DI-injected permission service.
     *
     * @param array<string, mixed> $userData Base user data
     * @return array<string, mixed> Enhanced user data with permissions
     */
    private function enhanceUserDataWithPermissions(array $userData): array
    {
        $userUuid = $userData['uuid'] ?? null;

        if ($userUuid === null || $userUuid === '') {
            return $userData;
        }

        try {
            $permissions = $this->loadUserPermissions($userUuid);
            $roles = $this->loadUserRoles($userUuid);

            return array_merge($userData, [
            'permissions' => $permissions,
            'roles' => $roles,
            'permission_hash' => hash('xxh3', json_encode(array_merge($permissions, $roles)))
            ]);
        } catch (\Throwable $e) {
            // Log error but don't fail session creation
            error_log("Failed to load permissions for user 1 {$userUuid}: " . $e->getMessage());
            return array_merge($userData, [
            'permissions' => [],
            'roles' => [],
            'permission_hash' => null
            ]);
        }
    }

    /**
     * Load user permissions using RBAC permission provider
     *
     * @param string $userUuid User UUID
     * @return array<string, list<string>>|list<string> User permissions grouped by resource
     */
    private function loadUserPermissions(string $userUuid): array
    {
        try {
            // 1. Use initialized permission service (PermissionManager's provider)
            if ($this->permissionService !== null) {
                return $this->permissionService->getUserPermissions($userUuid);
            }

            // 2. Fallback to direct RBAC repository access if service not available
            if (function_exists('container')) {
                try {
                    $container = $this->getContainer();
                    if ($container === null) {
                        return [];
                    }
                    if ($container->has('rbac.repository.user_permission')) {
                        $userPermRepo = $container->get('rbac.repository.user_permission');
                        if (method_exists($userPermRepo, 'getUserPermissions')) {
                            return $userPermRepo->getUserPermissions($userUuid);
                        }
                    }
                } catch (\Throwable $e) {
                    // Container not available, return empty permissions
                }
            }

            return [];
        } catch (\Throwable $e) {
            error_log("Failed to load permissions for user {$userUuid}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Load user roles using RBAC role service
     *
     * @param string $userUuid User UUID
     * @return list<string> User roles with hierarchy information
     */
    private function loadUserRoles(string $userUuid): array
    {
        try {
            // 1. Use DI-injected RBAC service first (most efficient)
            if ($this->permissionService instanceof RbacPermissionProviderInterface) {
                $result = $this->permissionService->getUserRoles($userUuid);
                return $result;
            }

            // 2. Try RBAC role service directly (preferred - includes caching)
            if (function_exists('container')) {
                try {
                    $container = $this->getContainer();
                    if ($container === null) {
                        return [];
                    }
                    if ($container->has('rbac.role_service')) {
                        $roleService = $container->get('rbac.role_service');
                        if (method_exists($roleService, 'getUserRoles')) {
                            $result = $roleService->getUserRoles($userUuid);
                            // Only return if we got a valid result, otherwise continue to fallbacks
                            if (is_array($result)) {
                                return $result;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Log error and continue to fallbacks
                    error_log("Failed to load roles via RoleService: " . $e->getMessage());
                }
            }

            // 3. Try RBAC role repository (fallback only if RoleService failed)
            if (function_exists('container')) {
                try {
                    $container = $this->getContainer();
                    if ($container === null) {
                        return [];
                    }
                    if ($container->has('rbac.repository.user_role')) {
                        $userRoleRepo = $container->get('rbac.repository.user_role');
                        if (method_exists($userRoleRepo, 'getUserRoles')) {
                            return $userRoleRepo->getUserRoles($userUuid);
                        }
                    }
                } catch (\Throwable $e) {
                    // Log error and continue to fallbacks
                    error_log("Failed to load roles via UserRoleRepository: " . $e->getMessage());
                }
            }

            // 4. Try PermissionManager provider (only if it's an RBAC provider)
            if (class_exists('Glueful\\Permissions\\PermissionManager')) {
                $manager = \Glueful\Permissions\PermissionManager::getInstance();
                if ($manager->hasActiveProvider()) {
                    $provider = $manager->getProvider();
                    if ($provider instanceof RbacPermissionProviderInterface) {
                        $result = $provider->getUserRoles($userUuid);
                        return $result;
                    }
                }
            }

            // 5. Graceful fallback - return empty array if no role system available
            return [];
        } catch (\Throwable $e) {
            error_log("Failed to load roles for user {$userUuid}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Cache user permissions separately for faster access
     *
     * @param string $userUuid User UUID
     * @param array<string, list<string>>|list<string> $permissions User permissions
     * @return bool Success status
     */
    private function cacheUserPermissions(string $userUuid, array $permissions): bool
    {
        try {
            $cacheKey = self::PERMISSION_CACHE_PREFIX . $userUuid;
            return $this->cache->set($cacheKey, $permissions, self::PERMISSIONS_TTL);
        } catch (\Throwable $e) {
            error_log("Failed to cache permissions for user {$userUuid}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get cached user permissions
     *
     * @param string $userUuid User UUID
     * @return array|null Cached permissions or null if not found
     * @phpstan-return array<string, list<string>>|list<string>|null
     */
    public function getCachedUserPermissions(string $userUuid): ?array
    {
        try {
            $cacheKey = self::PERMISSION_CACHE_PREFIX . $userUuid;
            return $this->cache->get($cacheKey);
        } catch (\Throwable $e) {
            error_log("Failed to get cached permissions for user {$userUuid}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Invalidate cached user permissions across all systems
     *
     * Integrates with RBAC permission provider invalidation.
     *
     * @param string $userUuid User UUID
     * @return bool Success status
     */
    public function invalidateUserPermissions(string $userUuid): bool
    {
        try {
            // 1. Invalidate session-level permission cache
            $cacheKey = self::PERMISSION_CACHE_PREFIX . $userUuid;
            $success = $this->cache->delete($cacheKey);

            // 2. Invalidate RBAC permission provider cache if available
            if ($this->permissionService !== null) {
                $this->permissionService->invalidateUserCache($userUuid);
            }

            // 3. Fallback: invalidate via PermissionManager
            if (class_exists('Glueful\\Permissions\\PermissionManager')) {
                $manager = \Glueful\Permissions\PermissionManager::getInstance();
                if ($manager->hasActiveProvider()) {
                    $provider = $manager->getProvider();
                    if ($provider instanceof PermissionProviderInterface) {
                        $provider->invalidateUserCache($userUuid);
                    }
                }
            }

            // 4. Invalidate session permission patterns
            $patterns = [
            "session:*:user:{$userUuid}:permissions",
            "rbac:check:{$userUuid}:*",
            "permissions:user:{$userUuid}*"
            ];

            foreach ($patterns as $pattern) {
                $this->cache->deletePattern($pattern);
            }

            // 5. Clean up user session index (optional - sessions will auto-cleanup)
            $userIndexKey = self::USER_SESSION_INDEX_PREFIX . $userUuid;
            $this->cache->delete($userIndexKey);

            return $success;
        } catch (\Throwable $e) {
            error_log("Failed to invalidate permissions for user {$userUuid}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Queue permission refresh for background processing
     *
     * @param string $userUuid User UUID
     * @param string $token Session token
     * @return void
     */
    private function queuePermissionRefresh(string $userUuid, string $token): void
    {
        try {
            // Use DI-injected queue manager if available
            if ($this->queueService !== null) {
                $this->queueService->push('RefreshUserPermissionsJob', [
                    'user_uuid' => $userUuid,
                    'token' => $token,
                    'queued_at' => time()
                ]);
                return;
            }

            // Fallback: mark for refresh on next session access
            $refreshKey = "permission_refresh:{$userUuid}";
            $this->cache->set($refreshKey, $token, 300); // 5 minutes
        } catch (\Throwable $e) {
            error_log("Failed to queue permission refresh for user {$userUuid}: " . $e->getMessage());
        }
    }

    /**
     * Refresh user permissions in their active session
     *
     * @param string $userUuid User UUID
     * @param string $token Session token
     * @return bool Success status
     */
    public function refreshUserPermissionsInSession(string $userUuid, string $token): bool
    {
        try {
            // Get current session
            $session = $this->getSession($token);
            if ($session === null) {
                return false;
            }

            // Load fresh permissions
            $permissions = $this->loadUserPermissions($userUuid);
            $roles = $this->loadUserRoles($userUuid);

            // Update session data
            $session['user']['permissions'] = $permissions;
            $session['user']['roles'] = $roles;
            $session['user']['permission_hash'] = hash('xxh3', json_encode(array_merge($permissions, $roles)));
            $session['permissions_loaded_at'] = time();

            // Get session data from SessionStore to find session ID
            $sessionStore = $this->getSessionStore();
            $sessionData = $sessionStore->getByAccessToken($token);
            if ($sessionData !== null) {
                $ttl = $this->getProviderTtl($session['provider'] ?? 'jwt');
                $success = $this->cache->set(self::SESSION_PREFIX . $sessionData['uuid'], $session, $ttl);

                if ($success) {
                    // Update separate permission cache
                    $this->cacheUserPermissions($userUuid, $permissions);
                }

                return $success;
            }

            return false;
        } catch (\Throwable $e) {
            error_log("Failed to refresh permissions in session for user {$userUuid}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Refresh permissions for all active sessions of a user
     *
     * @param string $userUuid User UUID
     * @return int Number of sessions updated
     */
    public function refreshPermissionsForAllUserSessions(string $userUuid): int
    {
        $updatedSessions = 0;

        try {
            // Find all sessions for the user (this is a simplified approach)
            // In a real implementation, you might want to maintain a user-to-sessions index
            $sessions = $this->findUserSessions($userUuid);

            foreach ($sessions as $session) {
                if (isset($session['token'])) {
                    $success = $this->refreshUserPermissionsInSession($userUuid, $session['token']);
                    if ($success) {
                        $updatedSessions++;
                    }
                }
            }

            // Also invalidate the separate permission cache
            $this->invalidateUserPermissions($userUuid);
        } catch (\Throwable $e) {
            error_log("Failed to refresh permissions for all sessions of user {$userUuid}: " . $e->getMessage());
        }

        return $updatedSessions;
    }

    /**
     * Find all active sessions for a user
     *
     * Uses the user session index for efficient lookup.
     *
     * @param string $userUuid User UUID
     * @return array Array of session data
     * @phpstan-return list<AuthSessionPayload>
     */
    private function findUserSessions(string $userUuid): array
    {
        try {
            // Prefer SessionStore listByUser to avoid cache-shape coupling
            try {
                $container = $this->getContainer();
                if ($container === null) {
                    return [];
                }
                $store = $container->get(SessionStore::class);
                $fromStore = $store->listByUser($userUuid);
                if (is_array($fromStore) && $fromStore !== []) {
                    return $fromStore;
                }
            } catch (\Throwable) {
                // ignore and fallback to cache-indexed approach
            }
            // Get session IDs from user index
            $indexKey = self::USER_SESSION_INDEX_PREFIX . $userUuid;
            $sessionIds = $this->cache->get($indexKey) ?? [];

            // Use batch get to retrieve all sessions at once
            $sessions = $this->batchGetSessions($sessionIds);
            $validSessions = [];

            // Verify sessions belong to this user and clean up invalid entries
            foreach ($sessions as $i => $session) {
                if (isset($session['user']['uuid']) && $session['user']['uuid'] === $userUuid) {
                    $validSessions[] = $session;
                } else {
                    // Clean up invalid index entry if we can map it back to sessionId
                    if (isset($sessionIds[$i])) {
                        $this->removeSessionFromUserIndex($userUuid, $sessionIds[$i]);
                    }
                }
            }

            $sessions = $validSessions;

            return $sessions;
        } catch (\Throwable $e) {
            error_log("Failed to find sessions for user {$userUuid}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all active sessions for a user (public interface)
     *
     * @param string $userUuid User UUID
     * @return array Array of session data
     * @phpstan-return list<AuthSessionPayload>
     */
    public function getUserSessions(string $userUuid): array
    {
        return $this->findUserSessions($userUuid);
    }

    /**
     * Get count of active sessions for a user
     *
     * @param string $userUuid User UUID
     * @return int Number of active sessions
     */
    public function getUserSessionCount(string $userUuid): int
    {
        return count($this->findUserSessions($userUuid));
    }

    /**
     * Terminate all sessions for a user
     *
     * @param string $userUuid User UUID
     * @return int Number of sessions terminated
     */
    public function terminateAllUserSessions(string $userUuid): int
    {
        $sessions = $this->findUserSessions($userUuid);
        $terminated = 0;

        foreach ($sessions as $session) {
            if (isset($session['token'])) {
                $success = $this->destroySession($session['token']);
                if ($success) {
                    $terminated++;
                }
            }
        }

        return $terminated;
    }

    /**
     * Check if session permissions are valid and fresh
     *
     * Uses RBAC-style cache validation logic.
     *
     * @param array $session Session data
     * @return bool True if permissions are valid
     * @phpstan-param AuthSessionPayload $session
     */
    private function areSessionPermissionsValid(array $session): bool
    {
        // Check if permissions exist
        if (!isset($session['user']['permissions']) || !isset($session['permissions_loaded_at'])) {
            return false;
        }

        // Check TTL
        $age = time() - $session['permissions_loaded_at'];
        if ($age > self::PERMISSIONS_TTL) {
            return false;
        }

        // Validate permission hash if available (integrity check)
        if (isset($session['user']['permission_hash'])) {
            $currentHash = hash('xxh3', json_encode(array_merge(
                $session['user']['permissions'] ?? [],
                $session['user']['roles'] ?? []
            )));

            if ($currentHash !== $session['user']['permission_hash']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get optimized session with smart permission caching
     *
     * Uses RBAC-style optimization patterns for maximum performance.
     *
     * @param string $token Authentication token
     * @param array<string, mixed> $context Additional context for permission loading (reserved for future use)
     * @return array|null Session data with optimized permissions
     * @phpstan-return AuthSessionPayload|null
     */
    public function getOptimizedSession(string $token, array $context = []): ?array
    {
        // Note: $context parameter reserved for future context-aware permission loading
        unset($context); // Acknowledge unused parameter until context-aware loading is implemented

        $session = $this->getSession($token);
        if ($session === null) {
            return null;
        }

        // Check if permissions are valid
        if ($this->areSessionPermissionsValid($session)) {
            // Permissions are fresh, return as-is
            return $session;
        }

        // Permissions need refresh - load fresh data
        $userUuid = $session['user']['uuid'] ?? null;
        if ($userUuid !== null && $userUuid !== '') {
            try {
                $permissions = $this->loadUserPermissions($userUuid);
                $roles = $this->loadUserRoles($userUuid);

                // Update session with fresh permissions
                $session['user']['permissions'] = $permissions;
                $session['user']['roles'] = $roles;
                $session['user']['permission_hash'] = hash('xxh3', json_encode(array_merge($permissions, $roles)));
                $session['permissions_loaded_at'] = time();

                // Store updated session
                $sessionStore = $this->getSessionStore();
                $sessionData = $sessionStore->getByAccessToken($token);
                if ($sessionData !== null) {
                    $ttl = $this->getProviderTtl($session['provider'] ?? 'jwt');
                    $this->cache->set(self::SESSION_PREFIX . $sessionData['uuid'], $session, $ttl);

                    // Also update separate permission cache
                    $this->cacheUserPermissions($userUuid, $permissions);
                }
            } catch (\Throwable $e) {
                error_log("Failed to refresh permissions for optimized session: " . $e->getMessage());
                // Return session with potentially stale permissions rather than failing
            }
        }

        return $session;
    }

    /**
     * Batch load permissions for multiple users
     *
     * Optimizes permission loading for bulk operations.
     *
     * @param list<string> $userUuids Array of user UUIDs
     * @return array<string, array<string, list<string>>|list<string>> Associative array of userUuid => permissions
     */
    public function batchLoadUserPermissions(array $userUuids): array
    {
        if ($userUuids === []) {
            return [];
        }

        $results = [];
        $missing = [];

        // Check cache for all users first
        foreach ($userUuids as $userUuid) {
            $cached = $this->getCachedUserPermissions($userUuid);
            if ($cached !== null) {
                $results[$userUuid] = $cached;
            } else {
                $missing[] = $userUuid;
            }
        }

        // Load missing permissions
        if ($missing !== []) {
            try {
                // Try batch loading if RBAC provider supports it
                if ($this->permissionService instanceof RbacPermissionProviderInterface) {
                    $batchResults = $this->permissionService->batchGetUserPermissions($missing);
                    foreach ($batchResults as $userUuid => $permissions) {
                        $results[$userUuid] = $permissions;
                        $this->cacheUserPermissions($userUuid, $permissions);
                    }
                } else {
                    // Fallback to individual loading
                    foreach ($missing as $userUuid) {
                        $permissions = $this->loadUserPermissions($userUuid);
                        $results[$userUuid] = $permissions;
                        $this->cacheUserPermissions($userUuid, $permissions);
                    }
                }
            } catch (\Throwable $e) {
                error_log("Failed to batch load permissions: " . $e->getMessage());
                // Fill missing with empty arrays
                foreach ($missing as $userUuid) {
                    $results[$userUuid] = [];
                }
            }
        }

        return $results;
    }

    /**
     * Batch retrieve sessions to avoid N+1 cache calls
     *
     * @param list<string> $sessionIds Array of session IDs
     * @return array Array of valid sessions
     * @phpstan-return list<AuthSessionPayload>
     */
    private function batchGetSessions(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        // Prepare cache keys
        $cacheKeys = array_map(fn($id) => self::SESSION_PREFIX . $id, $sessionIds);

        // Use the batch get operation from CacheStore
        $cachedSessions = $this->cache->mget($cacheKeys);

        // Return only valid sessions (filter out null/false values)
        return array_values(array_filter($cachedSessions));
    }

    /**
     * Create session query builder for advanced filtering
     *
     * @return SessionQueryBuilder Query builder instance
     */
    public function sessionQuery(): SessionQueryBuilder
    {
        return new SessionQueryBuilder(__CLASS__);
    }

    /**
     * Get sessions by provider for query builder (public access for SessionQueryBuilder)
     *
     * @param string $provider Provider name
     * @return array Sessions for the provider
     * @phpstan-return list<AuthSessionPayload>
     */
    public function getSessionsByProviderForQuery(string $provider): array
    {
        try {
            $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
            $sessionIds = $this->cache->get($indexKey) ?? [];

            return $this->batchGetSessions($sessionIds);
        } catch (\Throwable $e) {
            error_log("Failed to get sessions for provider {$provider}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get provider TTL (public access for SessionTransaction)
     *
     * @param string $provider Provider name
     * @return int TTL in seconds
     */
    public function getProviderTtlPublic(string $provider): int
    {
        return $this->getProviderTtl($provider);
    }

    /**
     * Remove session from provider index (public access for SessionTransaction)
     *
     * @param string $provider Provider name
     * @param string $sessionId Session ID
     * @return bool Success status
     */
    public function removeSessionFromProviderIndexPublic(string $provider, string $sessionId): bool
    {
        try {
            $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
            $sessionIds = $this->cache->get($indexKey) ?? [];

            $sessionIds = array_filter($sessionIds, fn($id) => $id !== $sessionId);

            return $this->cache->set($indexKey, array_values($sessionIds), self::DEFAULT_TTL);
        } catch (\Throwable $e) {
            error_log("Failed to remove session from provider index: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Index session by provider (public access for SessionTransaction)
     *
     * @param string $provider Provider name
     * @param string $sessionId Session ID
     * @param int $ttl TTL in seconds
     * @return bool Success status
     */
    public function indexSessionByProviderPublic(string $provider, string $sessionId, int $ttl): bool
    {
        return $this->indexSessionByProvider($provider, $sessionId, $ttl);
    }

    /**
     * Create bulk session transaction
     *
     * @return SessionTransaction Transaction instance
     */
    public function transaction(): SessionTransaction
    {
        $transaction = new SessionTransaction();
        $transaction->begin();
        return $transaction;
    }

    /**
     * Invalidate sessions matching criteria
     *
     * @param array<string, mixed> $criteria Selection criteria
     * @return int Number of sessions invalidated
     */
    public function invalidateSessionsWhere(array $criteria): int
    {
        $transaction = $this->transaction();

        try {
            $count = $transaction->invalidateSessionsWhere($criteria);
            $transaction->commit();
            return $count;
        } catch (\Exception $e) {
            $transaction->rollback();
            throw $e;
        }
    }

    /**
     * Update sessions matching criteria
     *
     * @param array<string, mixed> $criteria Selection criteria
     * @param array<string, mixed> $updates Updates to apply
     * @return int Number of sessions updated
     */
    public function updateSessionsWhere(array $criteria, array $updates): int
    {
        $transaction = $this->transaction();

        try {
            $count = $transaction->updateSessionsWhere($criteria, $updates);
            $transaction->commit();
            return $count;
        } catch (\Exception $e) {
            $transaction->rollback();
            throw $e;
        }
    }

    /**
     * Create bulk sessions
     *
     * @param list<array<string, mixed>> $sessionsData Array of session data
     * @return list<string> Array of created session IDs
     */
    public function createBulkSessions(array $sessionsData): array
    {
        $transaction = $this->transaction();

        try {
            $sessionIds = $transaction->createSessions($sessionsData);
            $transaction->commit();
            return $sessionIds;
        } catch (\Exception $e) {
            $transaction->rollback();
            throw $e;
        }
    }

    /**
     * Migrate sessions between providers
     *
     * @param string $fromProvider Source provider
     * @param string $toProvider Target provider
     * @return int Number of sessions migrated
     */
    public function migrateSessions(string $fromProvider, string $toProvider): int
    {
        $transaction = $this->transaction();

        try {
            $count = $transaction->migrateSessions($fromProvider, $toProvider);
            $transaction->commit();
            return $count;
        } catch (\Exception $e) {
            $transaction->rollback();
            throw $e;
        }
    }

    private function getTokenManager(): TokenManager
    {
        if ($this->context !== null && $this->context->hasContainer()) {
            return $this->context->getContainer()->get(TokenManager::class);
        }

        return new TokenManager($this->context);
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if (function_exists('config') && $this->context !== null) {
            return config($this->context, $key, $default);
        }

        return $default;
    }

    private function getContainer(): ?\Psr\Container\ContainerInterface
    {
        if ($this->context === null) {
            return null;
        }

        return container($this->context);
    }
}
