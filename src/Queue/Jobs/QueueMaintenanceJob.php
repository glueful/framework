<?php

declare(strict_types=1);

namespace Glueful\Queue\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\Job;

/**
 * Runs {@see QueueMaintenance} on the scheduler: prunes failed jobs older than
 * `queue.failed.retention_days` (or `queue.maintenance.failed_jobs_cleanup_days`) and cleans
 * stale worker records and metrics through the worker-monitor seam.
 *
 * Schedule it from config/schedule.php:
 *   ['name' => 'queue_maintenance', 'schedule' => '15 4 * * *',
 *    'handler_class' => \Glueful\Queue\Jobs\QueueMaintenanceJob::class]
 */
class QueueMaintenanceJob extends Job
{
    use ResolvesJobLogger;

    public function __construct(array $data = [], ?ApplicationContext $context = null)
    {
        parent::__construct($data, $context);
        $this->queue = 'maintenance';
    }

    public function handle(): void
    {
        if ($this->context === null) {
            throw new \RuntimeException('QueueMaintenanceJob needs the application context');
        }

        $maintenance = new QueueMaintenance($this->context);
        $maintenance->handle($this->getData());

        $stats = $maintenance->getStats();
        $errors = (array) ($stats['errors'] ?? []);
        if ($errors !== []) {
            $this->jobLogger()->warning('Queue maintenance finished with errors', ['errors' => $errors]);
        }
    }
}
