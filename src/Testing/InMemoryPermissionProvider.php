<?php

declare(strict_types=1);

namespace Glueful\Testing;

use Glueful\Interfaces\Permission\{PermissionProviderInterface, PermissionStandards};

/**
 * Test-only permission provider that grants a fixed set of permission slugs per user.
 * Satisfies CORE_PERMISSIONS so PermissionManager::setProvider() accepts it.
 */
final class InMemoryPermissionProvider implements PermissionProviderInterface
{
    /** @param array<string, string[]> $grantsByUser userUuid => permission slugs ('*' grants everything to that user) */
    public function __construct(private array $grantsByUser = [])
    {
    }

    public function initialize(array $config = []): void
    {
    }

    public function can(string $userUuid, string $permission, string $resource, array $context = []): bool
    {
        $granted = $this->grantsByUser[$userUuid] ?? [];
        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    /** @return array<string, string[]> resource => permissions[] (provider contract shape) */
    public function getUserPermissions(string $userUuid): array
    {
        $granted = array_values(array_filter(
            $this->grantsByUser[$userUuid] ?? [],
            fn($p) => $p !== '*'
        ));
        return $granted === [] ? [] : ['system' => $granted];
    }

    public function assignPermission(string $userUuid, string $permission, string $resource, array $options = []): bool
    {
        $this->grantsByUser[$userUuid][] = $permission;
        return true;
    }

    public function revokePermission(string $userUuid, string $permission, string $resource): bool
    {
        if (isset($this->grantsByUser[$userUuid])) {
            $this->grantsByUser[$userUuid] = array_values(array_filter(
                $this->grantsByUser[$userUuid],
                fn($p) => $p !== $permission
            ));
        }
        return true;
    }

    /** @return array<string, string> */
    public function getAvailablePermissions(): array
    {
        $available = [];
        foreach (PermissionStandards::CORE_PERMISSIONS as $core) {
            $available[$core] = $core;
        }
        foreach ($this->grantsByUser as $slugs) {
            foreach ($slugs as $slug) {
                if ($slug !== '*') {
                    $available[$slug] = $slug;
                }
            }
        }
        return $available;
    }

    /** @return array<string, string> */
    public function getAvailableResources(): array
    {
        return [];
    }

    public function batchAssignPermissions(string $userUuid, array $permissions, array $options = []): bool
    {
        foreach ($permissions as $perm) {
            $this->grantsByUser[$userUuid][] = is_array($perm) ? ($perm['permission'] ?? '') : $perm;
        }
        return true;
    }

    public function batchRevokePermissions(string $userUuid, array $permissions): bool
    {
        foreach ($permissions as $perm) {
            $slug = is_array($perm) ? ($perm['permission'] ?? '') : $perm;
            $this->revokePermission($userUuid, $slug, 'system');
        }
        return true;
    }

    public function assignRole(string $userUuid, string $roleSlug, array $options = []): bool
    {
        return true;
    }

    public function revokeRole(string $userUuid, string $roleSlug): bool
    {
        return true;
    }

    public function invalidateUserCache(string $userUuid): void
    {
    }

    public function invalidateAllCache(): void
    {
    }

    /** @return array{name: string, version: string, description: string, capabilities: string[], author: string} */
    public function getProviderInfo(): array
    {
        return [
            'name' => 'in-memory',
            'version' => 'test',
            'description' => 'In-memory permission provider for tests',
            'capabilities' => ['permissions'],
            'author' => 'Glueful',
        ];
    }

    /** @return array{status: string, healthy: bool, details: array<string, mixed>} */
    public function healthCheck(): array
    {
        return ['status' => 'ok', 'healthy' => true, 'details' => []];
    }
}
