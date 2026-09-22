<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Queue;

use Glueful\Queue\Drivers\DatabaseQueue;
use Glueful\Queue\QueueManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared ground for the failed-job commands. Failed jobs are stored by the database queue
 * connection (queue_failed_jobs); the commands work on the named connection, default the
 * configured one.
 */
abstract class FailedJobsCommand extends BaseQueueCommand
{
    protected function addConnectionOption(): static
    {
        return $this->addOption(
            'connection',
            null,
            InputOption::VALUE_REQUIRED,
            'Queue connection that stored the failures (default: the configured connection)'
        );
    }

    protected function failedStore(InputInterface $input): ?DatabaseQueue
    {
        $name = $input->getOption('connection');
        $driver = $this->getContainer()->get(QueueManager::class)
            ->connection(is_string($name) && $name !== '' ? $name : null);

        if (!$driver instanceof DatabaseQueue) {
            $this->error('Failed jobs are kept by the database queue connection; this connection is not one.');
            return null;
        }

        return $driver;
    }
}
