<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\Migrations;

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Tests\Integration\Database\Migrations\Support\MigrationTestCase;

/**
 * The CoreProvider factory registers core migrations as explicit leaf subdirs — auth (always) plus
 * config-gated capability subdirs — never the parent migrations/ (findMigrations() recurses, which
 * would slurp every capability under the wrong source and bypass the gates).
 */
final class CapabilityMigrationsTest extends MigrationTestCase
{
    /** @var array<string,bool> env keys set during a test, cleared in tearDown */
    private array $setEnv = [];

    protected function tearDown(): void
    {
        foreach (array_keys($this->setEnv) as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        $this->setEnv = [];
        parent::tearDown();
    }

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

    /**
     * Set gate env vars then re-boot (gates are resolved at boot), returning the container manager.
     * @param array<string,string> $env
     */
    private function bootWithEnv(array $env): MigrationManager
    {
        foreach ($env as $k => $v) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
            $this->setEnv[$k] = true;
        }
        $this->refreshApplication();
        return $this->app()->getContainer()->get(MigrationManager::class);
    }

    private function hasTable(string $table): bool
    {
        return Connection::fromContext($this->context())->getSchemaBuilder()->hasTable($table);
    }

    /**
     * Asserts a capability registers + creates its table(s) only when its gate is on.
     * @param array<string,string> $gateOn  env that turns the gate ON
     * @param list<string> $tables  tables the capability creates
     */
    private function assertGatedCapability(string $source, array $gateOn, array $tables): void
    {
        // ON: source registered + tables created after migrate.
        $mm = $this->bootWithEnv($gateOn);
        self::assertArrayHasKey($source, $this->registeredSources($mm), "$source should register when gated on");
        $mm->migrate();
        foreach ($tables as $t) {
            self::assertTrue($this->hasTable($t), "$t should be created when $source is on");
        }
    }

    public function test_factory_registers_auth_from_its_subdir_not_the_parent(): void
    {
        $mm = $this->app()->getContainer()->get(MigrationManager::class);
        $bySource = $this->registeredSources($mm);

        // FAILS before implementation: the old factory points glueful/framework at the parent
        // migrations/; it must register the auth/ leaf subdir.
        self::assertArrayHasKey('glueful/framework', $bySource);
        self::assertStringEndsWith('/migrations/auth', rtrim($bySource['glueful/framework'], '/'));
    }

    public function test_locks_capability_gated_on_database_driver(): void
    {
        // OFF (default lock driver is 'file'): the locks source is not registered.
        $off = $this->app()->getContainer()->get(MigrationManager::class);
        self::assertArrayNotHasKey('glueful/framework:locks', $this->registeredSources($off));

        // ON: lock.default = database → registered + table created.
        $this->assertGatedCapability('glueful/framework:locks', ['LOCK_DRIVER' => 'database'], ['locks']);
    }

    public function test_uploads_capability_gated_on_uploads_enabled(): void
    {
        // ON by default (uploads.enabled=true): registered + blobs created.
        $on = $this->app()->getContainer()->get(MigrationManager::class);
        self::assertArrayHasKey('glueful/framework:uploads', $this->registeredSources($on));
        $on->migrate();
        self::assertTrue($this->hasTable('blobs'));

        // OFF: UPLOADS_ENABLED=false → not registered.
        $off = $this->bootWithEnv(['UPLOADS_ENABLED' => 'false']);
        self::assertArrayNotHasKey('glueful/framework:uploads', $this->registeredSources($off));
    }

    public function test_queue_capability_gated_on_database_connection(): void
    {
        // OFF: sync driver → not registered.
        $off = $this->bootWithEnv(['QUEUE_CONNECTION' => 'sync']);
        self::assertArrayNotHasKey('glueful/framework:queue', $this->registeredSources($off));

        // ON: database driver → registered + the three queue tables created.
        $this->assertGatedCapability(
            'glueful/framework:queue',
            ['QUEUE_CONNECTION' => 'database'],
            ['queue_jobs', 'queue_failed_jobs', 'queue_batches']
        );
    }

    public function test_databasequeue_has_no_runtime_ddl(): void
    {
        // The lazy table-creation path is gone — schema is owned by the queue migration.
        self::assertFalse(
            method_exists(\Glueful\Queue\Drivers\DatabaseQueue::class, 'ensureQueueTables'),
            'DatabaseQueue must not create tables at runtime'
        );
    }

    public function test_scheduler_capability_gated_on_database_store(): void
    {
        // ON by default (schedule.database_store=true): registered + tables created.
        $on = $this->app()->getContainer()->get(MigrationManager::class);
        self::assertArrayHasKey('glueful/framework:scheduler', $this->registeredSources($on));
        $on->migrate();
        self::assertTrue($this->hasTable('scheduled_jobs'));
        self::assertTrue($this->hasTable('job_executions'));

        // OFF: SCHEDULE_DATABASE_STORE=false → not registered.
        $off = $this->bootWithEnv(['SCHEDULE_DATABASE_STORE' => 'false']);
        self::assertArrayNotHasKey('glueful/framework:scheduler', $this->registeredSources($off));
    }

    public function test_jobscheduler_has_no_runtime_ddl(): void
    {
        self::assertFalse(
            method_exists(\Glueful\Scheduler\JobScheduler::class, 'ensureTablesExist'),
            'JobScheduler must not create tables at runtime'
        );
    }

    public function test_notifications_capability_gated_on_database_store(): void
    {
        // ON by default: registered + all 5 tables (incl. the formerly-runtime retry_queue).
        $on = $this->app()->getContainer()->get(MigrationManager::class);
        self::assertArrayHasKey('glueful/framework:notifications', $this->registeredSources($on));
        $on->migrate();
        foreach (
            [
                'notifications',
                'notification_deliveries',
                'notification_preferences',
                'notification_templates',
                'notification_retry_queue',
            ] as $t
        ) {
            self::assertTrue($this->hasTable($t), "$t should be created");
        }

        // OFF: NOTIFICATIONS_DATABASE_STORE=false → not registered.
        $off = $this->bootWithEnv(['NOTIFICATIONS_DATABASE_STORE' => 'false']);
        self::assertArrayNotHasKey('glueful/framework:notifications', $this->registeredSources($off));
    }

    public function test_notificationretryservice_has_no_runtime_ddl(): void
    {
        self::assertFalse(
            method_exists(
                \Glueful\Notifications\Services\NotificationRetryService::class,
                'ensureRetryQueueTableExists'
            ),
            'NotificationRetryService must not create tables at runtime'
        );
    }

    public function test_metrics_capability_gated_on_database_store(): void
    {
        // ON by default (metrics.database_store=true): registered + tables created.
        $on = $this->app()->getContainer()->get(MigrationManager::class);
        self::assertArrayHasKey('glueful/framework:metrics', $this->registeredSources($on));
        $on->migrate();
        foreach (['api_metrics', 'api_metrics_daily', 'api_rate_limits'] as $t) {
            self::assertTrue($this->hasTable($t), "$t should be created");
        }

        // OFF: METRICS_DATABASE_STORE=false → not registered.
        $off = $this->bootWithEnv(['METRICS_DATABASE_STORE' => 'false']);
        self::assertArrayNotHasKey('glueful/framework:metrics', $this->registeredSources($off));
    }

    public function test_apimetricsservice_has_no_runtime_ddl(): void
    {
        self::assertFalse(
            method_exists(\Glueful\Services\ApiMetricsService::class, 'ensureTablesExist'),
            'ApiMetricsService must not create tables at runtime'
        );
    }
}
