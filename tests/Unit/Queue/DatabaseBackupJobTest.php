<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\Jobs\DatabaseBackupJob;
use PHPUnit\Framework\TestCase;

/**
 * The backup job built its task without the context (so it read static config and wrote relative
 * to the working directory) and reported success whatever the task returned. A backup that was
 * not made now fails the job, so the queue records it and failed() logs it as critical.
 */
final class DatabaseBackupJobTest extends TestCase
{
    public function testABackupThatWasNotMadeFailsTheJob(): void
    {
        $root = sys_get_temp_dir() . '/glueful_backup_job_' . bin2hex(random_bytes(4));
        mkdir($root, 0755, true);
        try {
            // No database config under this root: the task has no database to back up.
            $job = new DatabaseBackupJob(['backupType' => 'full'], ApplicationContext::forTesting($root));

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Database backup was not created');
            $job->handle();
        } finally {
            self::assertFileExists($root . '/storage/logs/database-backup.log', 'written under the site, not the cwd');
            exec('rm -rf ' . escapeshellarg($root));
        }
    }
}
