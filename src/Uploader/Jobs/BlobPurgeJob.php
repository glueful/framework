<?php

declare(strict_types=1);

namespace Glueful\Uploader\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\Job;
use Glueful\Queue\Jobs\ResolvesJobLogger;
use Glueful\Uploader\BlobPurger;

/**
 * Scheduled removal of deleted blobs past `uploads.purge_deleted_after_days`.
 *
 * Schedule it from config/schedule.php:
 *   ['name' => 'blob_purge', 'schedule' => '45 3 * * *',
 *    'handler_class' => \Glueful\Uploader\Jobs\BlobPurgeJob::class]
 */
class BlobPurgeJob extends Job
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
            throw new \RuntimeException('BlobPurgeJob needs the application context');
        }

        $result = BlobPurger::fromContext($this->context)
            ->purgeDeletedOlderThan(BlobPurger::graceDays($this->context));

        $this->jobLogger()->info('Deleted blobs purged', $result);
    }
}
