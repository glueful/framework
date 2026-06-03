<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

/** Base for fatal catalog-build errors. These must NOT be swallowed at boot. */
class PermissionCatalogException extends \RuntimeException
{
}
