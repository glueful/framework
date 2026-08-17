<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\Migrations;

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Tests\Integration\Database\Migrations\Support\MigrationTestCase;

final class MigrationSourceColumnTest extends MigrationTestCase
{
    public function test_version_table_has_source_column(): void
    {
        // Lazy-ledger contract: migrate() (not construction) runs ensureVersionTable().
        (new MigrationManager($this->tempMigrationsDir(), null, $this->context()))->migrate();

        $schema = Connection::fromContext($this->context())->getSchemaBuilder();
        self::assertTrue($schema->hasColumn('migrations', 'source'));
    }
}
