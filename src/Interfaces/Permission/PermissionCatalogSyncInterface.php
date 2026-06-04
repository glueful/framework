<?php

declare(strict_types=1);

namespace Glueful\Interfaces\Permission;

use Glueful\Permissions\Catalog\SyncResult;

/**
 * Optional capability: a permission provider that can persist the declarative catalog.
 * Implemented by providers like Aegis. Sync is idempotent and CLI-driven.
 */
interface PermissionCatalogSyncInterface
{
    /**
     * Upsert the declared catalog into the provider's store, by slug.
     *
     * @param array<int, array<string, mixed>> $permissions each Permission::toArray()
     * @param array<int, array<string, mixed>> $roles       each Role::toArray()
     */
    public function syncCatalog(array $permissions, array $roles): SyncResult;

    /**
     * Return persisted, extension/app-managed permissions (managed_by IS NOT NULL)
     * as slug => managed_by. Hand-created rows (managed_by NULL) are excluded.
     *
     * @return array<string, string>
     */
    public function getManagedCatalog(): array;
}
