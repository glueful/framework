<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;

/**
 * A scheduled job receives its `parameters` as its data. LogCleanupJob and NotificationRetryJob
 * read their settings from `options`, but the default schedule passed `retentionDays` and `limit`
 * at the top level, so LOG_RETENTION_DAYS and the retry limit were silently ignored.
 */
final class ScheduleConfigParametersTest extends TestCase
{
    public function testEachDefaultJobPassesItsSettingsWhereTheJobReadsThem(): void
    {
        $saved = $_ENV['LOG_RETENTION_DAYS'] ?? null;
        $_ENV['LOG_RETENTION_DAYS'] = '9';
        try {
            $config = require dirname(__DIR__, 3) . '/config/schedule.php';
        } finally {
            if ($saved === null) {
                unset($_ENV['LOG_RETENTION_DAYS']);
            } else {
                $_ENV['LOG_RETENTION_DAYS'] = $saved;
            }
        }
        $jobs = array_column($config['jobs'], null, 'name');

        self::assertSame(9, (int) ($jobs['log_cleanup']['parameters']['options']['retention_days'] ?? 0));
        self::assertSame(50, (int) ($jobs['notification_retry_processor']['parameters']['options']['limit'] ?? 0));
        foreach ($jobs as $name => $job) {
            self::assertSame(
                [],
                array_diff(array_keys((array) ($job['parameters'] ?? [])), [
                    'options', 'cleanupType', 'operation', 'retryType', 'backupType',
                ]),
                "{$name} passes a parameter its job never reads"
            );
        }
    }
}
