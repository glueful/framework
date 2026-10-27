<?php

declare(strict_types=1);

namespace Glueful\Controllers\Traits;

use Glueful\Permissions\Exceptions\UnauthorizedException;
use Glueful\Permissions\PermissionManager;
use Glueful\Auth\UserIdentity;
use Glueful\Permissions\Helpers\PermissionHelper;
use Glueful\Permissions\PermissionContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authorization Trait
 *
 * Provides authorization and permission checking functionality for controllers.
 * Handles permission validation, admin checks, and user context extraction.
 *
 * Host requirements (provided by BaseController and CachedUserContextTrait):
 * @property \Glueful\Bootstrap\ApplicationContext $context
 * @property Request $request
 * @method ?UserIdentity getCachedUser()
 * @method ?string getCachedUserUuid()
 * @method bool isCachedAuthenticated()
 * @method bool isCachedAdmin()
 * @method bool hasCachedPermission(string $permission, string $resource = 'system', array<string, mixed> $context = [])
 * @method void requireCachedAuthentication(?string $message = null)
 *
 * @package Glueful\Controllers\Traits
 */
trait AuthorizationTrait
{
    /**
     * Check if current user has a specific permission
     *
     * @param string $permission Permission to check
     * @param string $resource Resource identifier (default: 'system')
     * @param array<string, mixed> $context Additional context for permission check
     * @return bool True if user has permission
     */
    protected function can(string $permission, string $resource = 'system', array $context = []): bool
    {
        // Use cached permission check from CachedUserContextTrait
        return $this->hasCachedPermission($permission, $resource, $context);
    }

    /**
     * Require specific permission for the current user
     *
     * @param string $permission Permission to check
     * @param string $resource Resource identifier (default: 'system')
     * @param array<string, mixed> $context Additional context for permission check
     * @throws UnauthorizedException If permission is denied
     */
    protected function requirePermission(
        string $permission,
        string $resource = 'system',
        array $context = []
    ): void {
        // Unauthenticated callers are refused before any permission decision (401 path).
        $this->requireCachedAuthentication();

        if (!$this->isPermitted($permission, $resource, $context)) {
            throw new UnauthorizedException(
                $this->getCachedUserUuid() ?? '',
                $permission,
                $resource,
                sprintf('You do not have permission to %s on %s', $permission, $resource)
            );
        }
    }

    /**
     * Fail-closed permission decision for requirePermission().
     *
     * The session/provider fast path (can()) is tried first. When no permission provider is
     * installed, the check falls through to PermissionManager::can(), which evaluates the Gate
     * (config-defined roles/voters) — so Gate-only apps work instead of being refused. With a
     * provider installed, can() already consulted it via PermissionManager, so a miss is final.
     *
     * @param array<string, mixed> $context
     */
    private function isPermitted(string $permission, string $resource, array $context): bool
    {
        if ($this->can($permission, $resource, $context)) {
            return true;
        }

        $manager = $this->permissionManager();
        if ($manager->hasActiveProvider()) {
            return false;
        }

        $user = $this->getCachedUser();
        $userUuid = $this->getCachedUserUuid();
        if ($user === null || $userUuid === null || $userUuid === '') {
            return false;
        }

        try {
            return $manager->can($userUuid, $permission, $resource, array_merge($context, [
                'roles' => $user->roles(),
                'scopes' => $user->scopes(),
            ]));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolve the container-configured PermissionManager (Gate + permissions config injected),
     * falling back to the process singleton when no container is reachable.
     */
    protected function permissionManager(): PermissionManager
    {
        try {
            if (isset($this->context)) {
                $container = container($this->context);
                $manager = $container->has(PermissionManager::class)
                    ? $container->get(PermissionManager::class)
                    : null;
                if ($manager instanceof PermissionManager) {
                    return $manager;
                }
            }
        } catch (\Throwable) {
            // Fall through to the singleton.
        }

        return PermissionManager::getInstance();
    }

    /**
     * Check if current user has any of the specified permissions
     *
     * @param array<string> $permissions Array of permissions to check
     * @param string $resource Resource identifier (default: 'system')
     * @param array<string, mixed> $context Additional context for permission check
     * @return bool True if user has at least one permission
     */
    protected function canAny(array $permissions, string $resource = 'system', array $context = []): bool
    {
        if (!$this->isCachedAuthenticated()) {
            return false;
        }

        // Admin users have all permissions
        if ($this->isCachedAdmin()) {
            return true;
        }

        // Check if permission provider is available
        if (!$this->hasPermissionProvider()) {
            return false;
        }

        $permissionContext = new PermissionContext(
            data: $context,
            ipAddress: $this->request->getClientIp(),
            userAgent: $this->request->headers->get('User-Agent'),
            requestId: $this->request->headers->get('X-Request-ID')
        );

        return PermissionHelper::hasAnyPermission(
            $this->getCachedUserUuid() ?? '',
            $permissions,
            $resource,
            $permissionContext->toArray()
        );
    }

    /**
     * Check if a permission provider is available
     *
     * @return bool True if provider is available, false otherwise
     */
    protected function hasPermissionProvider(): bool
    {
        $permissionManager = PermissionManager::getInstance();

        if (!$permissionManager->hasActiveProvider()) {
            return false;
        }

        return true;
    }

    /**
     * Check if the current user is an admin
     *
     * @return bool True if user is an admin
     */
    protected function isAdmin(): bool
    {
        // Use cached admin check from CachedUserContextTrait
        return $this->isCachedAdmin();
    }

    /**
     * Get current authenticated user data
     *
     * @return UserIdentity|null Current user data
     */
    protected function getCurrentUser(): ?UserIdentity
    {
        // Use cached user from CachedUserContextTrait for consistency
        return $this->getCachedUser();
    }

    /**
     * Get current authenticated user UUID
     *
     * @return string|null Current user UUID
     */
    protected function getCurrentUserUuid(): ?string
    {
        // Use cached UUID from CachedUserContextTrait for consistency
        return $this->getCachedUserUuid();
    }

    /**
     * Extract token from request
     *
     * @param Request $request
     * @return string|null
     */
    private function extractToken(Request $request): ?string
    {
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader === null || $authHeader === '') {
            return null;
        }

        return strpos($authHeader, 'Bearer ') === 0
            ? substr($authHeader, 7)
            : $authHeader;
    }
}
