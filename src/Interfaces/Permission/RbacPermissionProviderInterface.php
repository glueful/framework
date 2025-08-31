<?php

declare(strict_types=1);

namespace Glueful\Interfaces\Permission;

/**
 * Optional RBAC-specific provider capabilities.
 *
 * Extensions implementing role retrieval or batch permission loading
 * can implement this interface in addition to PermissionProviderInterface.
 */
interface RbacPermissionProviderInterface extends PermissionProviderInterface
{
    /**
     * Get all roles for a user.
     *
     * @return list<string>
     */
    public function getUserRoles(string $userUuid): array;

    /**
     * Batch load permissions for multiple users.
     *
     * @param list<string> $userUuids
     * @return array<string, array<string, list<string>>|list<string>>
     */
    public function batchGetUserPermissions(array $userUuids): array;
}

