<?php

declare(strict_types=1);

namespace Glueful\Database\Migrations;

/**
 * An explicit migrate() argument named a file outside the global source policy — e.g. a disabled
 * extension's on_enable migration. The whole request is rejected before any DDL or receipt;
 * migrateSources() is the sole intentional scoped bypass (used by the enable executor).
 */
final class MigrationScopeException extends \RuntimeException
{
}
