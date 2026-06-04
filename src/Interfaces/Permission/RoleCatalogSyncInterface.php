<?php

declare(strict_types=1);

namespace Glueful\Interfaces\Permission;

/**
 * Optional capability: a sync provider that persists roles (not just permissions) and can
 * report/prune managed roles. Opt-in so permission-only providers are unaffected.
 */
interface RoleCatalogSyncInterface
{
    /**
     * Persisted, extension/app-managed roles (managed_by IS NOT NULL) as slug => managed_by.
     * Hand-created rows are excluded — never stale/prunable.
     *
     * @return array<string, string>
     */
    public function getManagedRoles(): array;

    /**
     * Delete managed roles by slug (managed_by IS NOT NULL only). Hand-created rows never deleted.
     *
     * @param string[] $roleSlugs
     * @return int rows removed
     */
    public function pruneRoles(array $roleSlugs): int;
}
