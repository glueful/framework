<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

final class DanglingGrantException extends PermissionCatalogException
{
    public function __construct(string $roleSlug, string $permissionSlug)
    {
        parent::__construct(sprintf(
            'Role "%s" grants permission "%s", which is not declared by any enabled provider.',
            $roleSlug,
            $permissionSlug
        ));
    }
}
