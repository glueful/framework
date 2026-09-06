<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue\Jobs;

use Glueful\Queue\Jobs\SessionCleanupJob;
use PHPUnit\Framework\TestCase;

/**
 * The job's completion log must report what SessionCleanupTask actually removed. The task
 * returns four per-category counts and never a `cleaned_count`, so the previous
 * `$result['cleaned_count'] ?? 0` logged zero sessions cleaned on every run.
 */
final class SessionCleanupJobTest extends TestCase
{
    public function testSummaryTotalsEveryCategoryTheTaskRemoved(): void
    {
        $summary = SessionCleanupJob::summarizeStats([
            'expired_access' => 3,
            'expired_refresh' => 2,
            'old_revoked' => 4,
            'old_refresh_rows' => 1,
            'errors' => [],
        ]);

        self::assertSame(10, $summary['sessions_cleaned']);
        self::assertSame([], $summary['errors']);
    }

    public function testSummaryPassesTaskErrorsThrough(): void
    {
        $summary = SessionCleanupJob::summarizeStats([
            'expired_access' => 0,
            'expired_refresh' => 0,
            'old_revoked' => 0,
            'old_refresh_rows' => 0,
            'errors' => ['refresh table locked'],
        ]);

        self::assertSame(0, $summary['sessions_cleaned']);
        self::assertSame(['refresh table locked'], $summary['errors']);
    }
}
