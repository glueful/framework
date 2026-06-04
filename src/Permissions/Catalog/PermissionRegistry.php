<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

/**
 * In-memory, provider-agnostic catalog of declared permissions and roles.
 * Populated once per process by ExtensionManager::aggregatePermissionCatalog().
 */
final class PermissionRegistry
{
    /** @var array<string, Permission> slug => Permission */
    private array $permissions = [];
    /** @var array<string, string> slug => declaring package */
    private array $permissionSources = [];
    /** @var array<string, Role> slug => Role */
    private array $roles = [];
    /** @var array<string, string> slug => declaring package */
    private array $roleSources = [];

    public function register(Permission $perm, string $source): void
    {
        $slug = $perm->slug();
        if (isset($this->permissionSources[$slug]) && $this->permissionSources[$slug] !== $source) {
            throw new DuplicatePermissionException($slug, $this->permissionSources[$slug], $source);
        }
        $perm->managedBy($source);
        $this->permissions[$slug] = $perm;
        $this->permissionSources[$slug] = $source;
    }

    public function registerRole(Role $role, string $source): void
    {
        $slug = $role->slug();
        if (isset($this->roleSources[$slug]) && $this->roleSources[$slug] !== $source) {
            throw new DuplicatePermissionException($slug, $this->roleSources[$slug], $source);
        }
        $role->managedBy($source);
        $this->roles[$slug] = $role;
        $this->roleSources[$slug] = $source;
    }

    public function has(string $slug): bool
    {
        return isset($this->permissions[$slug]);
    }

    /** Declaring package for a permission slug, or null if not declared. */
    public function sourceOf(string $slug): ?string
    {
        return $this->permissionSources[$slug] ?? null;
    }

    /** @return string[] */
    public function permissionSlugs(): array
    {
        return array_keys($this->permissions);
    }

    /** @return string[] */
    public function roleSlugs(): array
    {
        return array_keys($this->roles);
    }

    /** @return array<string, Permission[]> category => permissions (uncategorized under '') */
    public function permissionsByCategory(): array
    {
        $grouped = [];
        foreach ($this->permissions as $perm) {
            $category = (string) ($perm->toArray()['category'] ?? '');
            $grouped[$category][] = $perm;
        }
        return $grouped;
    }

    /**
     * Clear all declarations. Makes a rebuild via aggregatePermissionCatalog() idempotent —
     * re-running (e.g. from the permissions:sync CLI after a boot-time build) reconstructs the
     * catalog from scratch instead of appending to an already-populated registry.
     */
    public function reset(): void
    {
        $this->permissions = [];
        $this->permissionSources = [];
        $this->roles = [];
        $this->roleSources = [];
    }

    /** @return Permission[] */
    public function permissions(): array
    {
        return array_values($this->permissions);
    }

    /** @return Role[] */
    public function roles(): array
    {
        return array_values($this->roles);
    }

    /** @return array<string, string[]> role slug => granted permission slugs */
    public function rolePermissionMap(): array
    {
        $map = [];
        foreach ($this->roles as $slug => $role) {
            $map[$slug] = $role->grantedPermissions();
        }
        return $map;
    }

    /** Fatal validation: every role grant must reference a declared permission (or "*"). */
    public function validate(): void
    {
        foreach ($this->roles as $role) {
            foreach ($role->grantedPermissions() as $permSlug) {
                if ($permSlug !== '*' && !$this->has($permSlug)) {
                    throw new DanglingGrantException($role->slug(), $permSlug);
                }
            }
        }
    }
}
