<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Tasks;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Tasks\DatabaseBackupTask;
use PHPUnit\Framework\TestCase;

/**
 * DatabaseBackupTask read flat `driver`/`database`/`username`/`password` keys the stock
 * config/database.php does not have (it is `engine` plus a nested `pgsql { db, user, pass }`),
 * so it fell back to mysqldump with empty credentials on every stock site, swallowed the failure
 * and logged "completed". The PostgreSQL password it prepared was never handed to pg_dump either.
 */
final class DatabaseBackupTaskTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/glueful_backup_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/database', 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @param array<string, mixed> $database the stock `database` config shape */
    private function task(array $database): DatabaseBackupTask
    {
        return new DatabaseBackupTask(ApplicationContext::forTesting($this->root), $database);
    }

    public function testAStockSqliteConfigIsBackedUpIntoTheSitesBackupDirectory(): void
    {
        $file = $this->root . '/storage/database/app.sqlite';
        file_put_contents($file, 'sqlite bytes');
        $task = $this->task(['engine' => 'sqlite', 'sqlite' => ['driver' => 'sqlite', 'primary' => $file]]);

        $stats = $task->handle(['retention_days' => 7]);

        self::assertSame([], $stats['errors']);
        self::assertTrue($stats['backup_created']);
        $backups = glob($this->root . '/storage/backups/backup_*.sql') ?: [];
        self::assertCount(1, $backups);
        self::assertSame('sqlite bytes', file_get_contents($backups[0]));
    }

    public function testAStockPostgresConfigBuildsAPgDumpThatCarriesItsCredentials(): void
    {
        $task = $this->task(['engine' => 'pgsql', 'pgsql' => [
            'driver' => 'pgsql', 'host' => 'db.internal', 'port' => 6543, 'db' => 'site',
            'user' => 'site_user', 'pass' => 's3cret', 'sslmode' => 'require',
        ]]);

        $dump = (new \ReflectionMethod(DatabaseBackupTask::class, 'dumpCommand'))
            ->invoke($task, '/backups/b.sql');

        self::assertSame('pg_dump', $dump['command'][0]);
        self::assertContains('--host=db.internal', $dump['command']);
        self::assertContains('--port=6543', $dump['command']);
        self::assertContains('--username=site_user', $dump['command']);
        self::assertContains('--file=/backups/b.sql', $dump['command']);
        self::assertSame('site', end($dump['command']));
        self::assertSame('s3cret', $dump['env']['PGPASSWORD']);
        self::assertSame('require', $dump['env']['PGSSLMODE']);
        self::assertNotContains('s3cret', $dump['command'], 'the password never reaches the process list');
    }

    public function testAFailedBackupIsLoggedAsFailedNotCompleted(): void
    {
        $task = $this->task(['engine' => 'sqlite', 'sqlite' => ['driver' => 'sqlite', 'primary' => '/nope.sqlite']]);

        $stats = $task->handle();

        self::assertFalse($stats['backup_created']);
        $log = (string) file_get_contents($this->root . '/storage/logs/database-backup.log');
        self::assertStringContainsString('Database backup failed', $log);
        self::assertStringNotContainsString('Database backup completed', $log);
    }
}
