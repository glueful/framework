<?php

declare(strict_types=1);

namespace Glueful\Interfaces\Permission;

/**
 * Optional capability: a sync provider that can delete managed permissions.
 * Separate from PermissionCatalogSyncInterface so adding prune never breaks existing
 * permission-only sync providers (interface segregation).
 */
interface CatalogPruneInterface
{
    /**
     * Delete managed permissions by slug (managed_by IS NOT NULL only). Hand-created rows never deleted.
     *
     * @param string[] $slugs
     * @return int rows removed
     */
    public function pruneCatalog(array $slugs): int;
}
