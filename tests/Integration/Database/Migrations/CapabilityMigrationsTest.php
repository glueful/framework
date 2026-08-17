<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\Migrations;

use Glueful\Database\Migrations\MigrationManager;
use Glueful\Tests\Integration\Database\Migrations\Support\MigrationTestCase;

/**
 * The CoreProvider factory registers core migrations as explicit leaf subdirs — never the parent
 * migrations/ (findMigrations() recurses, which would slurp every capability under the wrong
 * source). Since the schema policy (spec 2026-08-17, B2) every leaf registers UNCONDITIONALLY:
 * configuration flags govern runtime behavior, not schema presence — the old config-gated
 * registration was a second policy and is gone.
 */
final class CapabilityMigrationsTest extends MigrationTestCase
{
    /** @return array<string, string> source => registered path */
    private function registeredSources(MigrationManager $mm): array
    {
        $ref = new \ReflectionMethod($mm, 'allSources');
        $ref->setAccessible(true);
        $bySource = [];
        foreach ($ref->invoke($mm) as $entry) {
            $bySource[$entry['source']] = $entry['path'];
        }
        return $bySource;
    }

    public function test_factory_registers_auth_from_its_subdir_not_the_parent(): void
    {
        $mm = $this->app()->getContainer()->get(MigrationManager::class);
        $bySource = $this->registeredSources($mm);

        self::assertArrayHasKey('glueful/framework', $bySource);
        self::assertStringEndsWith('/migrations/auth', rtrim($bySource['glueful/framework'], '/'));
    }

    public function test_every_core_leaf_registers_unconditionally_with_gates_off(): void
    {
        // The default test config leaves every old gate OFF (file locks, sync queue, …); the
        // leaves must register regardless — config governs runtime behavior, not schema presence.
        $mm = $this->app()->getContainer()->get(MigrationManager::class);
        $bySource = $this->registeredSources($mm);

        foreach (['locks', 'metrics', 'notifications', 'queue', 'scheduler', 'uploads'] as $leaf) {
            self::assertArrayHasKey(
                'glueful/framework:' . $leaf,
                $bySource,
                "core leaf {$leaf} must register unconditionally"
            );
            self::assertStringEndsWith('/migrations/' . $leaf, rtrim($bySource['glueful/framework:' . $leaf], '/'));
        }
    }

    public function test_migrate_creates_capability_tables_regardless_of_runtime_gates(): void
    {
        $mm = $this->app()->getContainer()->get(MigrationManager::class);
        $mm->migrate();

        $schema = \Glueful\Database\Connection::fromContext($this->context())->getSchemaBuilder();
        foreach (['locks', 'queue_jobs'] as $table) {
            self::assertTrue($schema->hasTable($table), "{$table} must exist after a full migrate");
        }
    }
}
