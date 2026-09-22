<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Queue;

use Glueful\Queue\Contracts\FailedJobStore;
use Glueful\Queue\QueueManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared ground for the failed-job commands. They work on the named queue connection (default: the
 * configured one) when its driver keeps failures, which the database and Redis drivers do.
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

    protected function failedStore(InputInterface $input): ?FailedJobStore
    {
        $name = $input->getOption('connection');
        $driver = $this->getContainer()->get(QueueManager::class)
            ->connection(is_string($name) && $name !== '' ? $name : null);

        if (!$driver instanceof FailedJobStore) {
            $this->error('This queue connection\'s driver does not keep failed jobs.');
            return null;
        }

        return $driver;
    }
}
