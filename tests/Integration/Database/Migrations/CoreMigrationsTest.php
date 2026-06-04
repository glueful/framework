<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\Migrations;

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Tests\Integration\Database\Migrations\Support\MigrationTestCase;

/**
 * Core ships its own foundation schema (auth_sessions, auth_refresh_tokens, api_keys) as
 * first-class migrations. The container factory auto-registers framework/migrations at FOUNDATION
 * priority, so a MigrationManager resolved FROM THE CONTAINER applies them — no extension, no app
 * migration, and crucially no lazy runtime DDL. (A directly-constructed MigrationManager stays
 * isolated; that path is exercised by MigrationOrderingTest/MigrationSourcesTest.)
 */
final class CoreMigrationsTest extends MigrationTestCase
{
    public function test_container_manager_applies_core_foundation_schema(): void
    {
        /** @var MigrationManager $mm */
        $mm = $this->app()->getContainer()->get(MigrationManager::class);
        $mm->migrate();

        $schema = Connection::fromContext($this->context())->getSchemaBuilder();
        foreach (['auth_sessions', 'auth_refresh_tokens', 'api_keys'] as $table) {
            self::assertTrue($schema->hasTable($table), "$table should be created by core migrations");
        }
        // Principal references are plain indexed UUID columns (no cross-package FK, §2).
        self::assertTrue($schema->hasColumn('auth_sessions', 'user_uuid'));
        self::assertTrue($schema->hasColumn('api_keys', 'user_uuid'));
        self::assertTrue($schema->hasColumn('auth_refresh_tokens', 'session_uuid'));
    }

    public function test_core_migrations_are_tracked_under_framework_source(): void
    {
        /** @var MigrationManager $mm */
        $mm = $this->app()->getContainer()->get(MigrationManager::class);
        $mm->migrate();

        // The version table records each core migration under source 'glueful/framework'
        // (Phase-1 source tracking). This is the per-source assertion Phase 5's E2E test relies on.
        $rows = Connection::fromContext($this->context())
            ->table('migrations')
            ->select(['migration', 'source'])
            ->get();
        $sourceByMigration = [];
        foreach ($rows as $row) {
            $sourceByMigration[(string) $row['migration']] = (string) $row['source'];
        }

        foreach (
            [
                '001_CreateAuthSessionsTable.php',
                '002_CreateAuthRefreshTokensTable.php',
                '003_CreateApiKeysTable.php',
            ] as $file
        ) {
            self::assertArrayHasKey($file, $sourceByMigration, "$file should be recorded");
            self::assertSame('glueful/framework', $sourceByMigration[$file], "$file source");
        }

        // And nothing is pending after migrate() — recorded, not re-applied.
        $pending = array_map('basename', $mm->getPendingMigrations());
        self::assertNotContains('001_CreateAuthSessionsTable.php', $pending);
    }
}
