<?php

declare(strict_types=1);

namespace Glueful\Permissions;

use Glueful\Interfaces\Permission\PermissionManagerInterface;
use Glueful\Interfaces\Permission\PermissionProviderInterface;
use Glueful\Interfaces\Permission\PermissionStandards;
use Glueful\Permissions\Exceptions\PermissionException;
use Glueful\Permissions\Exceptions\ProviderNotFoundException;
use Glueful\Auth\AuthenticationService;

/**
 * Permission Manager
 *
 * Core implementation of the permission management system.
 * Acts as a facade to permission providers and handles provider
 * registration, configuration, and delegation of permission operations.
 *
 * This class provides a consistent API for the framework to interact
 * with permission systems regardless of which provider is active.
 *
 * Uses hybrid dependency injection approach:
 * - Singleton pattern for global access
 * - Optional dependencies for internal services
 * - Static provider registry for extensions
 *
 * @package Glueful\Permissions
 */
class PermissionManager implements PermissionManagerInterface
{
    /** @var PermissionProviderInterface|null Currently active permission provider */
    private static ?PermissionProviderInterface $activeProvider = null;

    /** @var array<string, PermissionProviderInterface> Registered providers */
    private static array $providers = [];

    /** @var bool Whether debug mode is enabled */
    private static bool $debugMode = false;

    /** @var array<string, mixed> Debug information collected during operations */
    private static array $debugInfo = [];

    /** @var \Glueful\Auth\SessionCacheManager|null Session cache manager instance */
    private ?\Glueful\Auth\SessionCacheManager $sessionCacheManager = null;


/** @var \Glueful\Permissions\Gate|null */
    private ?\Glueful\Permissions\Gate $gate = null;
/** @var array<string,mixed> */
    private array $permissionsConfig = [];
/** @var \Glueful\Http\RequestUserContext|null */
    private ?\Glueful\Http\RequestUserContext $requestUserContext = null;


    /**
     * Set the active permission provider
     *
     * @param PermissionProviderInterface $provider The provider to activate
     * @param array<string, mixed> $config Configuration for the provider
     * @return void
     * @throws PermissionException If provider initialization fails or doesn't implement core permissions
     */
    public function setProvider(PermissionProviderInterface $provider, array $config = []): void
    {
        try {
            // Initialize the provider with configuration
            $provider->initialize($config);

            // Validate that provider implements core permissions
            $this->validateCorePermissions($provider);

            // Set as active provider
            self::$activeProvider = $provider;

            // Store provider info for debugging
            $providerInfo = $provider->getProviderInfo();
            self::$providers[$providerInfo['name'] ?? 'unknown'] = $provider;

            if (self::$debugMode) {
                self::$debugInfo[] = [
                    'action' => 'provider_set',
                    'provider' => $providerInfo['name'] ?? 'unknown',
                    'timestamp' => time(),
                    'config' => $config,
                    'core_permissions_validated' => true
                ];
            }
        } catch (\Exception $e) {
            throw new PermissionException("Failed to initialize permission provider: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the current active permission provider
     *
     * @return PermissionProviderInterface|null Current provider or null if none set
     */
    public function getProvider(): ?PermissionProviderInterface
    {
        return self::$activeProvider;
    }

    /**
     * Check if a user has permission
     *
     * @param string $userUuid User UUID to check permissions for
     * @param string $permission Permission name
     * @param string $resource Resource identifier
     * @param array<string, mixed> $context Additional context for permission check
     * @return bool True if user has permission, false otherwise
     * @throws ProviderNotFoundException If no provider is registered
     */

    public function can(string $userUuid, string $permission, string $resource, array $context = []): bool
    {
        $mode = $this->permissionsConfig['provider_mode'] ?? 'replace';

        // If provider exists and mode is 'replace', short-circuit to provider
        if (self::$activeProvider !== null && $mode === 'replace') {
            try {
                $startTime = microtime(true);
                $result = self::$activeProvider->can($userUuid, $permission, $resource, $context);
                $endTime = microtime(true);
                if (self::$debugMode) {
                    self::$debugInfo[] = [
                        'action' => 'permission_check',
                        'via' => 'provider',
                        'user' => $userUuid,
                        'permission' => $permission,
                        'resource' => $resource,
                        'context' => $context,
                        'result' => $result,
                        'duration_ms' => ($endTime - $startTime) * 1000,
                        'timestamp' => time()
                    ];
                }
                return $result;
            } catch (\Exception $e) {
                if (self::$debugMode) {
                    self::$debugInfo[] = [
                        'action' => 'permission_check_error',
                        'via' => 'provider',
                        'user' => $userUuid,
                        'permission' => $permission,
                        'resource' => $resource,
                        'context' => $context,
                        'error' => $e->getMessage(),
                        'timestamp' => time()
                    ];
                }
                throw new PermissionException("Permission check failed: " . $e->getMessage(), 0, $e);
            }
        }

        // Otherwise, evaluate via Gate (gate-only or combine mode)
        if ($this->gate === null) {
            // Lazy gate init with defaults if not injected
            $strategy = $this->permissionsConfig['strategy'] ?? 'affirmative';
            $allowOverride = (bool)($this->permissionsConfig['allow_deny_override'] ?? false);
            $this->gate = new \Glueful\Permissions\Gate($strategy, $allowOverride);
        }

        // Build UserIdentity via RequestUserContext if available
        $roles = $context['roles'] ?? [];
        $scopes = $context['scopes'] ?? [];
        $attrs = $context['attributes'] ?? [];

        if ($this->requestUserContext !== null) {
            $roles = $this->requestUserContext->getUserRoles();
            // No dedicated scopes method; prefer JWT claim if provided by caller
            $attrs['permissions'] = $this->requestUserContext->getUserPermissions();
        }

        $identity = new \Glueful\Auth\UserIdentity($userUuid, $roles, $scopes, $attrs);

        // Prepare Context (JWT/route/tenant if present in $context)
        $gateCtx = new \Glueful\Permissions\Context(
            tenantId: $context['tenant_id'] ?? null,
            routeParams: $context['route_params'] ?? [],
            jwtClaims: $context['jwt_claims'] ?? [],
            extra: $context['extra'] ?? []
        );

        // Provider decide hook for 'combine' mode (provider true=GRANT, false=ABSTAIN)
        $providerDecide = null;
        if (self::$activeProvider !== null && $mode === 'combine') {
            $providerDecide = function () use ($userUuid, $permission, $resource, $context) {
                try {
                    return self::$activeProvider->can($userUuid, $permission, $resource, $context)
                        ? \Glueful\Permissions\Vote::GRANT
                        : \Glueful\Permissions\Vote::ABSTAIN;
                } catch (\Throwable) {
                    return \Glueful\Permissions\Vote::ABSTAIN;
                }
            };
        }

        $startTime = microtime(true);
        $decision = $this->gate->decide(
            $identity,
            $permission,
            $context['resource_obj'] ?? null,
            $gateCtx,
            $providerDecide
        );
        $endTime = microtime(true);

        if (self::$debugMode) {
            self::$debugInfo[] = [
                'action' => 'permission_check',
                'via' => ($mode === 'combine' && self::$activeProvider !== null) ? 'provider+gate' : 'gate',
                'user' => $userUuid,
                'permission' => $permission,
                'resource' => $resource,
                'context' => $context,
                'result' => $decision,
                'duration_ms' => ($endTime - $startTime) * 1000,
                'timestamp' => time()
            ];
        }

        return $decision === \Glueful\Permissions\Vote::GRANT;
    }

    /**
     * Check if a user has permission using token
     *
     * @param string $token Authentication token
     * @param string $permission Permission name
     * @param string $resource Resource identifier
     * @param array<string, mixed> $context Additional context
     * @return bool True if user has permission, false otherwise
     * @throws \Exception If token is invalid or permission check fails
     */
    public function canWithToken(string $token, string $permission, string $resource, array $context = []): bool
    {
        try {
            // Extract user UUID from token
            $userUuid = $this->getUserUuidFromToken($token);
            if ($userUuid === null) {
                return false;
            }

            // Add token context
            $context['token'] = $token;
            $context['auth_method'] = 'token';

            return $this->can($userUuid, $permission, $resource, $context);
        } catch (\Exception $e) {
            if (self::$debugMode) {
                self::$debugInfo[] = [
                    'action' => 'token_permission_check_error',
                    'permission' => $permission,
                    'resource' => $resource,
                    'error' => $e->getMessage(),
                    'timestamp' => time()
                ];
            }
            throw new PermissionException("Token permission check failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get all permissions for a user
     *
     * @param string $userUuid User UUID to get permissions for
     * @return array<string, mixed> User's permissions
     * @throws ProviderNotFoundException If no provider is registered
     */
    public function getUserPermissions(string $userUuid): array
    {
        if (self::$activeProvider === null) {
            throw new ProviderNotFoundException("No permission provider is registered");
        }

        try {
            return self::$activeProvider->getUserPermissions($userUuid);
        } catch (\Exception $e) {
            throw new PermissionException("Failed to get user permissions: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Assign permission to user
     *
     * @param string $userUuid User UUID
     * @param string $permission Permission name
     * @param string $resource Resource identifier
     * @param array<string, mixed> $options Assignment options
     * @return bool Success status
     * @throws ProviderNotFoundException If no provider is registered
     */
    public function assignPermission(string $userUuid, string $permission, string $resource, array $options = []): bool
    {
        if (self::$activeProvider === null) {
            throw new ProviderNotFoundException("No permission provider is registered");
        }

        try {
            $result = self::$activeProvider->assignPermission($userUuid, $permission, $resource, $options);

            if (self::$debugMode) {
                self::$debugInfo[] = [
                    'action' => 'permission_assigned',
                    'user' => $userUuid,
                    'permission' => $permission,
                    'resource' => $resource,
                    'options' => $options,
                    'result' => $result,
                    'timestamp' => time()
                ];
            }

            return $result;
        } catch (\Exception $e) {
            throw new PermissionException("Failed to assign permission: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Revoke permission from user
     *
     * @param string $userUuid User UUID
     * @param string $permission Permission name
     * @param string $resource Resource identifier
     * @return bool Success status
     * @throws ProviderNotFoundException If no provider is registered
     */
    public function revokePermission(string $userUuid, string $permission, string $resource): bool
    {
        if (self::$activeProvider === null) {
            throw new ProviderNotFoundException("No permission provider is registered");
        }

        try {
            $result = self::$activeProvider->revokePermission($userUuid, $permission, $resource);

            if (self::$debugMode) {
                self::$debugInfo[] = [
                    'action' => 'permission_revoked',
                    'user' => $userUuid,
                    'permission' => $permission,
                    'resource' => $resource,
                    'result' => $result,
                    'timestamp' => time()
                ];
            }

            return $result;
        } catch (\Exception $e) {
            throw new PermissionException("Failed to revoke permission: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Assign role to user
     *
     * Facade method for role assignment. Delegates to the active provider's
     * assignRole method for role-based permission systems.
     *
     * @param string $userUuid User UUID
     * @param string $roleSlug Role identifier/slug
     * @param array<string, mixed> $options Assignment options
     * @return bool Success status
     * @throws ProviderNotFoundException If no provider is registered
     */
    public function assignRole(string $userUuid, string $roleSlug, array $options = []): bool
    {
        if (self::$activeProvider === null) {
            throw new ProviderNotFoundException("No permission provider is registered");
        }

        try {
            $result = self::$activeProvider->assignRole($userUuid, $roleSlug, $options);

            if (self::$debugMode) {
                self::$debugInfo[] = [
                    'action' => 'role_assigned',
                    'user' => $userUuid,
                    'role' => $roleSlug,
                    'options' => $options,
                    'result' => $result,
                    'timestamp' => time()
                ];
            }

            return $result;
        } catch (\Exception $e) {
            throw new PermissionException("Failed to assign role: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Revoke role from user
     *
     * Facade method for role revocation. Delegates to the active provider's
     * revokeRole method.
     *
     * @param string $userUuid User UUID
     * @param string $roleSlug Role identifier/slug
     * @return bool Success status
     * @throws ProviderNotFoundException If no provider is registered
     */
    public function revokeRole(string $userUuid, string $roleSlug): bool
    {
        if (self::$activeProvider === null) {
            throw new ProviderNotFoundException("No permission provider is registered");
        }

        try {
            $result = self::$activeProvider->revokeRole($userUuid, $roleSlug);

            if (self::$debugMode) {
                self::$debugInfo[] = [
                    'action' => 'role_revoked',
                    'user' => $userUuid,
                    'role' => $roleSlug,
                    'result' => $result,
                    'timestamp' => time()
                ];
            }

            return $result;
        } catch (\Exception $e) {
            throw new PermissionException("Failed to revoke role: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if the permission system is available
     *
     * @return bool True if permission system is available (either provider or Gate is registered)
     */
    public function isAvailable(): bool
    {
        return self::$activeProvider !== null || $this->gate !== null;
    }

    /**
     * Get system information
     *
     * @return array{provider: string|null, available: bool, health: string, cache_enabled: bool} System information
     */
    public function getSystemInfo(): array
    {
        $providerName = null;
        $health = 'unknown';
        if (self::$activeProvider !== null) {
            $providerInfo = self::$activeProvider->getProviderInfo();
            $providerName = $providerInfo['name'] ?? get_class(self::$activeProvider);
            $healthCheck = self::$activeProvider->healthCheck();
            $health = $healthCheck['status'] ?? 'unknown';
        }

        return [
            'provider' => $providerName,
            'available' => self::$activeProvider !== null,
            'health' => $health,
            'cache_enabled' => true // Assuming cache is always enabled for permissions
        ];
    }

    /**
     * Invalidate user permission cache
     *
     * @param string $userUuid User UUID to invalidate
     * @return void
     */
    public function invalidateUserCache(string $userUuid): void
    {
        if (self::$activeProvider !== null) {
            self::$activeProvider->invalidateUserCache($userUuid);
        }
    }

    /**
     * Invalidate all permission caches
     *
     * @return void
     */
    public function invalidateAllCache(): void
    {
        if (self::$activeProvider !== null) {
            self::$activeProvider->invalidateAllCache();
        }
    }

    /**
     * Enable debug mode
     *
     * @param bool $enabled Whether to enable debug mode
     * @return void
     */
    public function setDebugMode(bool $enabled): void
    {
        self::$debugMode = $enabled;
        if (!$enabled) {
            self::$debugInfo = [];
        }
    }

    /**
     * Get debug information
     *
     * @return array<string, mixed> Debug information
     */
    public function getDebugInfo(): array
    {
        return self::$debugInfo;
    }

    /**
     * Perform health check
     *
     * @return array{status: string, provider: bool, cache: bool, errors: string[]} Health check results
     */
    public function healthCheck(): array
    {
        $errors = [];
        $providerHealthy = false;
        $cacheHealthy = true; // Assume cache is healthy unless proven otherwise
        // Check if provider is available
        if (self::$activeProvider === null) {
            $errors[] = 'No permission provider is registered';
        } else {
            // Check provider health
            try {
                $providerHealth = self::$activeProvider->healthCheck();
                $providerHealthy = ($providerHealth['status'] ?? 'fail') === 'healthy';
                if (!$providerHealthy) {
                    $errors[] = 'Permission provider is not healthy';
                }
            } catch (\Exception $e) {
                $errors[] = 'Provider health check failed: ' . $e->getMessage();
            }
        }

        // Determine overall status
        $status = count($errors) === 0 ? 'healthy' : 'unhealthy';

        return [
            'status' => $status,
            'provider' => $providerHealthy,
            'cache' => $cacheHealthy,
            'errors' => $errors
        ];
    }

    /**
     * Register multiple providers
     *
     * @param PermissionProviderInterface[] $providers Array of provider instances
     * @return void
     */
    public function registerProviders(array $providers): void
    {
        foreach ($providers as $name => $provider) {
            if ($provider instanceof PermissionProviderInterface) {
                $providerInfo = $provider->getProviderInfo();
                $providerName = is_string($name) ? $name : ($providerInfo['name'] ?? 'unknown');
                self::$providers[$providerName] = $provider;
            }
        }
    }

    /**
     * Switch active provider
     *
     * @param string $providerName Name of registered provider to activate
     * @param array<string, mixed> $config Configuration for the new provider
     * @return bool True if switch successful
     */
    public function switchProvider(string $providerName, array $config = []): bool
    {
        if (!isset(self::$providers[$providerName])) {
            return false;
        }

        try {
            $this->setProvider(self::$providers[$providerName], $config);
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Get available providers
     *
     * @return string[] List of available providers
     */
    public function getAvailableProviders(): array
    {
        return array_keys(self::$providers);
    }

    /**
     * Extract user UUID from authentication token
     *
     * @param string $token Authentication token
     * @return string|null User UUID or null if extraction fails
     */
    private function getUserUuidFromToken(string $token): ?string
    {
        try {
            // Try to get session from SessionStore
            /** @var \Glueful\Auth\Interfaces\SessionStoreInterface $store */
            $store = container()->get(\Glueful\Auth\Interfaces\SessionStoreInterface::class);
            $sessionData = $store->getByAccessToken($token);
            if ($sessionData !== null && isset($sessionData['user_uuid'])) {
                return $sessionData['user_uuid'];
            }

            // Fallback: try direct token validation
            $user = AuthenticationService::validateAccessToken($token);
            if ($user !== null && isset($user['uuid'])) {
                return $user['uuid'];
            }

            return null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Validate that a provider implements all core permissions
     *
     * @param PermissionProviderInterface $provider The provider to validate
     * @return void
     * @throws PermissionException If provider doesn't implement required permissions
     */
    private function validateCorePermissions(PermissionProviderInterface $provider): void
    {
        $providerInfo = $provider->getProviderInfo();
        $providerName = $providerInfo['name'] ?? 'Unknown Provider';

        // Get available permissions from the provider
        $availablePermissions = $provider->getAvailablePermissions();

        // Extract permission slugs from the available permissions
        $availablePermissionSlugs = array_keys($availablePermissions);

        // Check each core permission
        $missingPermissions = [];
        foreach (PermissionStandards::CORE_PERMISSIONS as $corePermission) {
            if (!in_array($corePermission, $availablePermissionSlugs, true)) {
                $missingPermissions[] = $corePermission;
            }
        }

        // If any core permissions are missing, throw exception
        if (count($missingPermissions) > 0) {
            $missingList = implode(', ', $missingPermissions);
            throw new PermissionException(
                "Permission provider '{$providerName}' does not implement required core permissions: {$missingList}. " .
                "All permission providers must implement the following permissions: " .
                implode(', ', PermissionStandards::CORE_PERMISSIONS)
            );
        }

        // Log successful validation if in debug mode
        if (self::$debugMode) {
            self::$debugInfo[] = [
                'action' => 'core_permissions_validated',
                'provider' => $providerName,
                'core_permissions' => PermissionStandards::CORE_PERMISSIONS,
                'provider_permissions' => $availablePermissionSlugs,
                'timestamp' => time()
            ];
        }
    }

    /**
     * Check if active provider has a specific permission
     *
     * @param string $permission Permission to check for
     * @return bool True if permission exists in provider
     */
    public function hasPermission(string $permission): bool
    {
        if (self::$activeProvider === null) {
            return false;
        }

        try {
            $availablePermissions = self::$activeProvider->getAvailablePermissions();
            return isset($availablePermissions[$permission]);
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Check if the permission system has an active provider
     *
     * @return bool True if an active provider is set
     */
    public function hasActiveProvider(): bool
    {
        return self::$activeProvider !== null;
    }

    /**
     * Constructor with optional dependency injection
     *
     * @param \Glueful\Auth\SessionCacheManager|null $sessionCacheManager Optional session cache manager
     */
    public function __construct(?\Glueful\Auth\SessionCacheManager $sessionCacheManager = null)
    {
        $this->sessionCacheManager = $sessionCacheManager;
    }

    /**
     * Get singleton instance of PermissionManager
     *
     * @param \Glueful\Auth\SessionCacheManager|null $sessionCacheManager Optional session cache manager
     *                                                                    for new instance
     * @return self
     */
    public static function getInstance(?\Glueful\Auth\SessionCacheManager $sessionCacheManager = null): self
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new self($sessionCacheManager);
        }
        return $instance;
    }

    /** Inject Gate instance (optional if you bind via container) */
    public function setGate(\Glueful\Permissions\Gate $gate): void
    {
        $this->gate = $gate;
    }

    /**
     * Inject permissions config array (from config/permissions.php)
     * @param array<string, mixed> $config
     */
    public function setPermissionsConfig(array $config): void
    {
        $this->permissionsConfig = $config;
    }

    /** Inject RequestUserContext to resolve current user identity */
    public function setRequestUserContext(\Glueful\Http\RequestUserContext $ctx): void
    {
        $this->requestUserContext = $ctx;
    }
}
