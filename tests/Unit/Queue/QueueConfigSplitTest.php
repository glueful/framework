<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;

/**
 * Guards the WS5b queue-ops config split. The ops blocks
 * (process/auto_scaling/resource_limits/resource_thresholds/supervisor) and the
 * per-queue ops keys (workers/max_workers/auto_scale) moved out of core
 * `config/queue.php` to the `glueful/queue-ops` extension (queue_ops.*).
 *
 * What MUST remain in core: `workers.performance.*` (read by the lean
 * QueueWorker) and the per-queue `priority`/`memory_limit`/`timeout`/`max_jobs`.
 *
 * Loads the shipped config file directly so the assertions track the real file,
 * not a synthetic fixture.
 */
final class QueueConfigSplitTest extends TestCase
{
    /** @return array<string, mixed> */
    private function queueConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../config/queue.php';
        return $config;
    }

    public function testPerformanceBlockIsKept(): void
    {
        $config = $this->queueConfig();

        self::assertArrayHasKey('performance', $config['workers']);
        self::assertSame(2, $config['workers']['performance']['backoff_base']);
        self::assertSame('exponential', $config['workers']['performance']['backoff_strategy']);
        self::assertSame(3600, $config['workers']['performance']['max_backoff']);
    }

    public function testPerQueueKeptKeysRemain(): void
    {
        $default = $this->queueConfig()['workers']['queues']['default'];

        self::assertSame(1, $default['priority']);
        self::assertSame(128, $default['memory_limit']);
        self::assertSame(60, $default['timeout']);
        self::assertSame(1000, $default['max_jobs']);
    }

    public function testMovedOpsBlocksAreGone(): void
    {
        $workers = $this->queueConfig()['workers'];

        foreach (['process', 'auto_scaling', 'resource_limits', 'resource_thresholds', 'supervisor'] as $moved) {
            self::assertArrayNotHasKey($moved, $workers, "{$moved} must move to queue_ops.*");
        }
        // workers now contains exactly the kept structure.
        self::assertSame(['queues', 'performance'], array_keys($workers));
    }

    public function testMovedPerQueueKeysAreStripped(): void
    {
        $critical = $this->queueConfig()['workers']['queues']['critical'];

        foreach (['workers', 'max_workers', 'auto_scale'] as $moved) {
            self::assertArrayNotHasKey($moved, $critical, "per-queue {$moved} must move to queue_ops.*");
        }
    }
}
