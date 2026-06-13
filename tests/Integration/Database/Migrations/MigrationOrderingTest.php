<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\Migrations;

use Glueful\Database\Connection;
use Glueful\Database\Migrations\{MigrationManager, MigrationPriority};
use Glueful\Tests\Integration\Database\Migrations\Support\MigrationTestCase;

final class MigrationOrderingTest extends MigrationTestCase
{
    public function test_lower_priority_source_runs_before_higher_even_with_larger_basenames(): void
    {
        // App migration with a LOWER-sorting basename but DEFAULT (0) priority.
        $this->writeFixture($this->tempMigrationsDir(), '001_app_table.php', 'app_table');

        // Package migration with a HIGHER-sorting basename but IDENTITY (-100) priority.
        $pkgDir = $this->tempMigrationsDir() . '/../users';
        $this->writeFixture($pkgDir, '900_users.php', 'users');

        $mm = new MigrationManager($this->tempMigrationsDir(), null, $this->context());
        $mm->addMigrationPath($pkgDir, MigrationPriority::IDENTITY, 'glueful/users');

        $pending = array_map('basename', $mm->getPendingMigrations());

        self::assertSame(['900_users.php', '001_app_table.php'], $pending);
    }

    public function test_same_basename_from_two_sources_both_apply(): void
    {
        $this->writeFixture($this->tempMigrationsDir(), '001_create_tables.php', 'app_tbl');
        $pkgDir = $this->tempMigrationsDir() . '/../pkg';
        $this->writeFixture($pkgDir, '001_create_tables.php', 'pkg_tbl');

        $mm = new MigrationManager($this->tempMigrationsDir(), null, $this->context());
        $mm->addMigrationPath($pkgDir, MigrationPriority::DEPENDENT, 'glueful/pkg');

        $mm->migrate();

        $schema = Connection::fromContext($this->context())->getSchemaBuilder();
        self::assertTrue($schema->hasTable('app_tbl'));
        self::assertTrue($schema->hasTable('pkg_tbl'));
    }

    public function test_applied_row_records_the_owning_source(): void
    {
        $pkgDir = $this->tempMigrationsDir() . '/../users';
        $this->writeFixture($pkgDir, '001_users.php', 'users');

        $mm = new MigrationManager($this->tempMigrationsDir(), null, $this->context());
        $mm->addMigrationPath($pkgDir, MigrationPriority::IDENTITY, 'glueful/users');
        $mm->migrate();

        $row = Connection::fromContext($this->context())
            ->table('migrations')->where('migration', '001_users.php')->first();

        self::assertSame('glueful/users', $row['source']);
    }

    public function test_rollback_is_source_scoped_for_duplicate_basenames(): void
    {
        $this->writeFixture($this->tempMigrationsDir(), '001_create_tables.php', 'app_tbl');
        $pkgDir = $this->tempMigrationsDir() . '/../pkg';
        $this->writeFixture($pkgDir, '001_create_tables.php', 'pkg_tbl');

        $mm = new MigrationManager($this->tempMigrationsDir(), null, $this->context());
        $mm->addMigrationPath($pkgDir, MigrationPriority::DEPENDENT, 'glueful/pkg');
        $mm->migrate(); // pkg runs last (DEPENDENT), so it is the most-recent applied row

        $mm->rollback(1); // must revert ONLY the pkg copy

        $db = Connection::fromContext($this->context());
        $schema = $db->getSchemaBuilder();

        self::assertFalse($schema->hasTable('pkg_tbl'), 'pkg migration should be rolled back');
        self::assertTrue($schema->hasTable('app_tbl'), 'app migration must remain');

        $appRow = $db->table('migrations')
            ->where('migration', '001_create_tables.php')->where('source', 'app')->first();
        $pkgRow = $db->table('migrations')
            ->where('migration', '001_create_tables.php')->where('source', 'glueful/pkg')->first();
        self::assertNotNull($appRow, 'app history row must remain');
        self::assertNull($pkgRow, 'pkg history row must be deleted by (source, migration)');
    }

    public function test_rollback_rejects_traversal_filename_from_history(): void
    {
        $outside = dirname($this->tempMigrationsDir()) . '/evil.php';
        $this->writeFixture(dirname($outside), basename($outside), 'evil_tbl');

        $mm = new MigrationManager($this->tempMigrationsDir(), null, $this->context());
        $db = Connection::fromContext($this->context());
        $db->table('migrations')->insert([
            'migration' => '../evil.php',
            'source' => 'app',
            'batch' => 1,
            'checksum' => hash_file('sha256', $outside),
            'description' => 'evil',
        ]);

        $result = $mm->rollback(1);

        self::assertSame([], $result['reverted']);
        self::assertSame(['../evil.php'], $result['failed']);
    }
}
