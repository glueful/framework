<?php

declare(strict_types=1);

namespace Glueful\Http;

use Glueful\Auth\TokenManager;
use Glueful\Auth\SessionCacheManager;
use Glueful\Models\User;

/**
 * Request User Context
 *
 * Provides request-level caching of user authentication data to avoid
 * repeated database queries and token validation during a single request.
 *
 * Features:
 * - Single authentication check per request
 * - Cached session data
 * - Lazy loading of user information
 * - Permission result caching
 * - Request-scoped singleton pattern
 *
 * @package Glueful\Http
 */
class RequestUserContext
{
/** @var \Glueful\Permissions\Gate|null */
    private ?\Glueful\Permissions\Gate $gate = null;

/** @var array<string,mixed> */
    private array $permissionsConfig = [];

    /**
     * @phpstan-type AuthSessionUser array{
     *   uuid?: string,
     *   username?: string,
     *   roles?: list<string>,
     *   permissions?: array<string, list<string>>|list<string>,
     *   permission_hash?: string|null
     * }
     * @phpstan-type AuthSessionPayload array{
     *   id?: string,
     *   token?: string,
     *   user: AuthSessionUser,
     *   created_at?: int,
     *   last_activity?: int,
     *   provider?: string,
     *   permissions_loaded_at?: int
     * }
     */
    /** @var array<string, self> Request-scoped instances */
    private static array $instances = [];

    /** @var string|null Cached authentication token */
    private ?string $token = null;

    /** @var array<string, mixed>|null Cached session data */
    private ?array $sessionData = null; // @phpstan-var AuthSessionPayload|null

    /** @var User|null Cached user object */
    private ?User $user = null;

    /** @var array<string, bool> Cached permission results */
    private array $permissionCache = [];

    /** @var array<string, mixed> Cached user roles and capabilities */
    private array $userCapabilities = [];

    /** @var bool Whether authentication has been attempted */
    private bool $authAttempted = false;

    /** @var bool Whether user is authenticated */
    private bool $isAuthenticated = false;

    /** @var array<string, mixed> Request metadata */
    private array $requestMetadata = [];

    /** @var string Request ID for tracking */
    private string $requestId;

    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct(string $requestId)
    {
        $this->requestId = $requestId;
        $this->requestMetadata = [
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'timestamp' => time()
        ];
    }

    /**
     * Get request-scoped instance
     *
     * @param string|null $requestId Optional request ID for tracking
     * @return self Request user context instance
     */
    public static function getInstance(?string $requestId = null): self
    {
        $requestId = $requestId ?? self::generateRequestId();

        if (!isset(self::$instances[$requestId])) {
            self::$instances[$requestId] = new self($requestId);
        }

        return self::$instances[$requestId];
    }

    /**
     * Initialize user context with authentication check
     *
     * @return self Fluent interface
     */
    public function initialize(): self
    {
        if ($this->authAttempted) {
            return $this;
        }

        $this->authAttempted = true;

        try {
            // Extract token once per request
            $this->token = TokenManager::extractTokenFromRequest();
            if ($this->token !== null) {
                // Get optimized session data with context-aware caching
                $context = [
                'request_id' => $this->requestId,
                'ip_address' => $this->requestMetadata['ip_address'],
                'user_agent' => $this->requestMetadata['user_agent']
                ];
                $sessionCacheManager = app(SessionCacheManager::class);
                $this->sessionData = $sessionCacheManager->getOptimizedSession($this->token, $context);
                if ($this->sessionData) {
                    // If session has complete user data, use it
                    if (isset($this->sessionData['user']) && isset($this->sessionData['user']['username'])) {
                        $this->user = User::fromArray($this->sessionData['user']);
                    } else {
                        // Extract user data from JWT token
                        $userData = $this->extractUserDataFromToken($this->token);
                        if ($userData !== null) {
                            $this->user = User::fromArray($userData);
                        }
                    }

                    if ($this->user !== null) {
                        $this->isAuthenticated = true;
                        // Pre-cache user capabilities with enhanced permission data
                        $this->loadUserCapabilities();
                    }
                }
            }
        } catch (\Exception $e) {
            // Log authentication error but don't throw
            error_log("RequestUserContext initialization failed: " . $e->getMessage());
            $this->isAuthenticated = false;
        }

        return $this;
    }

    /**
     * Get authenticated user
     *
     * @return User|null Authenticated user or null
     */
    public function getUser(): ?User
    {
        $this->initialize();
        return $this->user;
    }

    /**
     * Get user UUID
     *
     * @return string|null User UUID or null
     */
    public function getUserUuid(): ?string
    {
        return $this->getUser()?->uuid;
    }

    /**
     * Get authentication token
     *
     * @return string|null Authentication token or null
     */
    public function getToken(): ?string
    {
        $this->initialize();
        return $this->token;
    }

    /**
     * Get session data
     *
     * @return array<string, mixed>|null Session data or null
     */
    public function getSessionData(): ?array
    {
        $this->initialize();
        return $this->sessionData;
    }

    /**
     * Check if user is authenticated
     *
     * @return bool True if authenticated
     */
    public function isAuthenticated(): bool
    {
        $this->initialize();
        return $this->isAuthenticated;
    }

    /**
     * Check if user is admin (cached)
     *
     * Uses AuthenticationManager's permission-based admin check for proper validation
     *
     * @return bool True if user is admin
     */
    public function isAdmin(): bool
    {
        $cacheKey = 'is_admin';

        if (!isset($this->permissionCache[$cacheKey])) {
            $user = $this->getUser();
            if ($user === null) {
                $this->permissionCache[$cacheKey] = false;
            } else {
                // Use AuthenticationManager's proper admin check
                $userData = $user->toArray();

                // Get AuthenticationManager instance
                $authManager = \Glueful\Auth\AuthBootstrap::getManager();
                $this->permissionCache[$cacheKey] = $authManager->isAdmin($userData);
            }
        }

        return $this->permissionCache[$cacheKey];
    }

    /**
     * Check permission with caching
     *
     * @param string $permission Permission to check
     * @param string $resource Resource identifier
     * @param array<string, mixed> $context Additional context
     * @return bool True if user has permission
     */
    public function hasPermission(string $permission, string $resource = 'system', array $context = []): bool
    {
        $cacheKey = sprintf('permission:%s:%s:%s', $permission, $resource, md5(json_encode($context)));

        if (!isset($this->permissionCache[$cacheKey])) {
            $user = $this->getUser();

            if ($user === null) {
                $this->permissionCache[$cacheKey] = false;
            } elseif ($this->isAdmin()) {
                $this->permissionCache[$cacheKey] = true;
            } else {
                // Use session-cached permissions for fast checking
                $this->permissionCache[$cacheKey] = $this->checkSessionPermission($permission, $resource, $context);
            }
        }

        return $this->permissionCache[$cacheKey];
    }

    /**
     * Get user roles (cached)
     *
     * @return array<string> User roles
     */
    public function getUserRoles(): array
    {
        $cacheKey = 'user_roles';

        if (!isset($this->userCapabilities[$cacheKey])) {
            $user = $this->getUser();

            if ($user === null) {
                $this->userCapabilities[$cacheKey] = [];
            } else {
                // Load roles from session data (nested in user object)
                $this->userCapabilities[$cacheKey] = $this->sessionData['user']['roles'] ?? [];
            }
        }

        return $this->userCapabilities[$cacheKey];
    }

    /**
     * Get user permissions (cached)
     *
     * @return array<string> User permissions
     */
    public function getUserPermissions(): array
    {
        $cacheKey = 'user_permissions';

        if (!isset($this->userCapabilities[$cacheKey])) {
            $user = $this->getUser();

            if ($user === null) {
                $this->userCapabilities[$cacheKey] = [];
            } else {
                // Load permissions from session data (nested in user object)
                $this->userCapabilities[$cacheKey] = $this->sessionData['user']['permissions'] ?? [];
            }
        }

        return $this->userCapabilities[$cacheKey];
    }

    /**
     * Check permission using session-cached data with fallback
     *
     * Fast permission checking using pre-loaded session permissions with
     * fallback to PermissionHelper for authoritative validation.
     *
     * @param string $permission Permission to check
     * @param string $resource Resource identifier
     * @param array<string, mixed> $context Additional context
     * @return bool True if user has permission
     */
    private function checkSessionPermission(string $permission, string $resource, array $context): bool
    {
        // Get session-cached permissions
        $userPermissions = $this->getUserPermissions();

        // Try cached permissions first (fast path)
        if (count($userPermissions) > 0) {
            // Check for direct permission match
            if ($this->hasDirectPermission($userPermissions, $permission, $resource)) {
                return true;
            }

            // Check for wildcard permission match
            if ($this->hasWildcardPermission($userPermissions, $permission, $resource)) {
                return true;
            }

            // Check for role-based permissions
            if ($this->hasRoleBasedPermission($permission, $resource, $context)) {
                return true;
            }
        }

        // Fallback: Use PermissionHelper for authoritative check
        // This handles cases where:
        // - Session cache is incomplete
        // - Dynamic permissions not cached
        // - RBAC provider has additional permissions
        $userUuid = $this->getUserUuid();
        if ($userUuid === null) {
            return false;
        }

        try {
            // Create permission context for the check
            $permissionContext = new \Glueful\Permissions\PermissionContext(
                data: $context,
                ipAddress: $this->requestMetadata['ip_address'] ?? null,
                userAgent: $this->requestMetadata['user_agent'] ?? null,
                requestId: $this->requestId
            );

            return \Glueful\Permissions\Helpers\PermissionHelper::hasPermission(
                $userUuid,
                $permission,
                $resource,
                $permissionContext->toArray()
            );
        } catch (\Throwable $e) {
            // Log error but don't throw - fail securely
            error_log("RequestUserContext permission fallback failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check for direct permission match in session data
     *
     * @param array<mixed> $userPermissions User permissions from session
     * @param string $permission Permission to check
     * @param string $resource Resource identifier
     * @return bool True if direct match found
     */
    private function hasDirectPermission(array $userPermissions, string $permission, string $resource): bool
    {
        // Check resource-specific permissions
        if (isset($userPermissions[$resource]) && in_array($permission, $userPermissions[$resource], true)) {
            return true;
        }

        // Check system-wide permissions
        if (isset($userPermissions['system']) && in_array($permission, $userPermissions['system'], true)) {
            return true;
        }

        // Check flat permission array (backward compatibility)
        if (in_array($permission, $userPermissions, true)) {
            return true;
        }

        return false;
    }

    /**
     * Check for wildcard permission match
     *
     * @param array<mixed> $userPermissions User permissions from session
     * @param string $permission Permission to check
     * @param string $resource Resource identifier
     * @return bool True if wildcard match found
     */
    private function hasWildcardPermission(array $userPermissions, string $permission, string $resource): bool
    {
        $wildcardPermissions = array_merge(
            $userPermissions[$resource] ?? [],
            $userPermissions['system'] ?? [],
            $userPermissions
        );

        foreach ($wildcardPermissions as $userPerm) {
            if (fnmatch($userPerm, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for role-based permissions
     *
     * @param string $permission Permission to check
     * @param string $resource Resource identifier
     * @param array<string, mixed> $context Additional context
     * @return bool True if role grants permission
     */
    private function hasRoleBasedPermission(string $permission, string $resource, array $context): bool
    {
        // Ensure gate present (fallback lazy init with defaults)
        if ($this->gate === null) {
            $strategy = $this->permissionsConfig['strategy'] ?? 'affirmative';
            $allowOverride = (bool)($this->permissionsConfig['allow_deny_override'] ?? false);
            $this->gate = new \Glueful\Permissions\Gate($strategy, $allowOverride);
        }

        $identity = $this->buildUserIdentity();

        // Prepare Gate Context from available request attributes / provided context
        $tenantId   = $context['tenant_id']   ?? ($this->requestMetadata['tenant_id'] ?? null);
        $routeParams = $context['route_params'] ?? ($this->requestMetadata['route_params'] ?? []);
        $jwtClaims  = $context['jwt_claims']  ?? [];
        $extra      = $context['extra']       ?? [];
        if (isset($context['ownerId'])) {
            $extra['ownerId'] = $context['ownerId'];
        }

        $gateCtx = new \Glueful\Permissions\Context(
            tenantId: is_string($tenantId) ? $tenantId : null,
            routeParams: is_array($routeParams) ? $routeParams : [],
            jwtClaims: is_array($jwtClaims) ? $jwtClaims : [],
            extra: is_array($extra) ? $extra : []
        );

        // Resource object (if any) can be passed via context
        $resourceObj = $context['resource_obj'] ?? null;

        $decision = $this->gate->decide($identity, $permission, $resourceObj, $gateCtx);
        return $decision === \Glueful\Permissions\Vote::GRANT;
    }

    /**
     * Get request metadata for audit logging
     *
     * @return array<string, mixed> Request metadata
     */
    public function getRequestMetadata(): array
    {
        return $this->requestMetadata;
    }

    /**
     * Get audit context data
     *
     * @return array<string, mixed> Audit context with user and request data
     */
    public function getAuditContext(): array
    {
        $user = $this->getUser();
        error_log("Generating audit context for user: " . json_encode($user));
        return array_merge($this->requestMetadata, [
        'user_uuid' => $user?->uuid,
        'session_id' => $this->sessionData['session_id'] ?? null,
        'request_id' => $this->requestId,
        'is_authenticated' => $this->isAuthenticated(),
        'is_admin' => $this->isAdmin()
        ]);
    }

    /**
     * Cache custom user data
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @return self Fluent interface
     */
    public function cacheUserData(string $key, mixed $value): self
    {
        $this->userCapabilities[$key] = $value;
        return $this;
    }

    /**
     * Get cached user data
     *
     * @param string $key Cache key
     * @param mixed $default Default value if not found
     * @return mixed Cached value or default
     */
    public function getCachedUserData(string $key, mixed $default = null): mixed
    {
        return $this->userCapabilities[$key] ?? $default;
    }

    /**
     * Load user capabilities and roles
     *
     * @return void
     */
    private function loadUserCapabilities(): void
    {
        if ($this->user === null || $this->sessionData === null) {
            return;
        }

        // Pre-load common capabilities from session data
        // Note: permissions and roles are nested in the user object
        $capabilities = [
        'user_roles' => $this->sessionData['user']['roles'] ?? [],
        'user_permissions' => $this->sessionData['user']['permissions'] ?? [],
        'permission_hash' => $this->sessionData['user']['permission_hash'] ?? null
        ];

        $this->userCapabilities = array_merge($this->userCapabilities, $capabilities);
    }

    /**
     * Extract user data from JWT token
     *
     * @param string $token JWT token
     * @return array<string, mixed>|null User data or null if extraction fails
     */
    /**
     * @return array<string, mixed>|null
     */
    private function extractUserDataFromToken(string $token): ?array
    {
        try {
            $jwtPayload = \Glueful\Auth\JWTService::decode($token);
            if ($jwtPayload === null) {
                return null;
            }

            // Map JWT payload to User model fields
            return [
            'uuid' => $jwtPayload['uuid'],
            'id' => $jwtPayload['id'] ?? $jwtPayload['uuid'],
            'username' => $jwtPayload['username'],
            'email' => $jwtPayload['email'],
            'email_verified' => ($jwtPayload['email_verified_at'] ?? null) !== null,
            'locale' => 'en-US', // Default locale
            'name' => isset($jwtPayload['profile'])
                ? trim(($jwtPayload['profile']['first_name'] ?? '') . ' ' .
                        ($jwtPayload['profile']['last_name'] ?? ''))
                : null,
                'given_name' => $jwtPayload['profile']['first_name'] ?? null,
                'family_name' => $jwtPayload['profile']['last_name'] ?? null,
                'picture' => $jwtPayload['profile']['photo_url'] ?? null,
                'status' => $jwtPayload['status'] ?? 'active',
                'last_login' => $jwtPayload['last_login'] ?? null,
                'updated_at' => isset($jwtPayload['created_at']) ? strtotime($jwtPayload['created_at']) : time(),
                'remember_me' => $jwtPayload['remember_me'] ?? false,
                'created_at' => $jwtPayload['created_at'] ?? null,
                'profile' => $jwtPayload['profile'] ?? [],
                'roles' => $jwtPayload['roles'] ?? []
            ];
        } catch (\Exception $e) {
            error_log("Failed to extract user data from token: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate unique request ID
     *
     * @return string Request ID
     */
    private static function generateRequestId(): string
    {
        static $requestId = null;

        if ($requestId === null) {
            $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true);
        }
        return $requestId;
    }

    /**
     * Clear cached permission result
     *
     * @param string|null $key Specific cache key or null for all
     * @return self Fluent interface
     */
    public function clearPermissionCache(?string $key = null): self
    {
        if ($key === null) {
            $this->permissionCache = [];
        } else {
            unset($this->permissionCache[$key]);
        }

        return $this;
    }

    /**
     * Update token after refresh
     *
     * Updates the cached token and refreshes session data without losing
     * other cached information. This is used when tokens are refreshed
     * within the same request.
     *
     * @param string $newToken New access token
     * @return self Fluent interface
     */
    public function updateToken(string $newToken): self
    {
        // Update the cached token
        $this->token = $newToken;

        // Clear only authentication-related caches
        $this->sessionData = null;
        $this->user = null;

        // Keep permission cache since permissions haven't changed
        // Only clear the admin check since it may depend on session data
        unset($this->permissionCache['is_admin']);

        // Re-initialize with the new token
        $this->authAttempted = false;
        return $this->initialize();
    }

    /**
     * Refresh user data from session
     *
     * @return self Fluent interface
     */
    public function refresh(): self
    {
        $this->authAttempted = false;
        $this->permissionCache = [];
        $this->userCapabilities = [];
        $this->sessionData = null;
        $this->user = null;
        $this->token = null;
        $this->isAuthenticated = false;

        return $this->initialize();
    }

    /**
     * Get cached statistics
     *
     * @return array<string, mixed> Cache statistics
     */
    public function getCacheStats(): array
    {
        return [
        'permission_cache_size' => count($this->permissionCache),
        'capabilities_cache_size' => count($this->userCapabilities),
        'auth_attempted' => $this->authAttempted,
        'is_authenticated' => $this->isAuthenticated,
        'has_user' => $this->user !== null,
        'has_session' => $this->sessionData !== null,
        'request_id' => $this->requestId
        ];
    }

    /**
     * Cleanup request-scoped instances
     *
     * @return void
     */
    public static function cleanup(): void
    {
        self::$instances = [];
    }

    /**
     * Destructor to log cache performance
     */
    public function __destruct()
    {
        // Log cache performance for monitoring
        if (config('app.debug', false) === true) {
            $stats = $this->getCacheStats();
            error_log("RequestUserContext stats: " . json_encode($stats));
        }
    }


/** Inject the Gate (recommended via container) */
    public function setGate(\Glueful\Permissions\Gate $gate): void
    {
        $this->gate = $gate;
    }

/**
     * Inject permissions config (array from config/permissions.php)
     * @param array<string, mixed> $config
     */
    public function setPermissionsConfig(array $config): void
    {
        $this->permissionsConfig = $config;
    }

/** Build a lightweight UserIdentity from current request/session */
    private function buildUserIdentity(): \Glueful\Auth\UserIdentity
    {
        $uuid = $this->getUserUuid() ?? 'anonymous';
        $roles = $this->getUserRoles();
        $scopes = []; // add when you expose scopes in this context
        $attrs = [
        'permissions' => $this->getUserPermissions(),
        ];
        return new \Glueful\Auth\UserIdentity($uuid, $roles, $scopes, $attrs);
    }
}
