<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

final class DuplicatePermissionException extends PermissionCatalogException
{
    public function __construct(string $slug, string $existingSource, string $newSource)
    {
        parent::__construct(sprintf(
            'Duplicate permission slug "%s" declared by both "%s" and "%s". '
            . 'Prefix slugs per package to avoid collisions.',
            $slug,
            $existingSource,
            $newSource
        ));
    }
}
