<?php

namespace Glueful\Console\Commands\Cache;

use Glueful\Console\BaseCommand;
use Glueful\Queue\QueueManager;
use Glueful\Queue\Jobs\CacheMaintenanceJob;
use Glueful\Tasks\CacheMaintenanceTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:maintenance', description: 'Run or queue cache maintenance tasks')]
class MaintenanceCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('operation', 'o', InputOption::VALUE_REQUIRED, 'Operation to perform', 'clearExpiredKeys')
             ->addOption('queue', 'q', InputOption::VALUE_NONE, 'Enqueue the operation instead of running immediately');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $operation = (string) ($input->getOption('operation') ?? 'clearExpiredKeys');
        $queueFlag = (bool) $input->getOption('queue');

        if ($queueFlag === true) {
            /** @var QueueManager $queue */
            $queue = app(QueueManager::class);
            $queue->push(CacheMaintenanceJob::class, ['operation' => $operation], 'maintenance');
            $this->info("Queued cache maintenance job: {$operation}");
            return self::SUCCESS;
        }

        /** @var CacheMaintenanceTask $task */
        $task = app(CacheMaintenanceTask::class);
        match ($operation) {
            'clearExpiredKeys' => $task->clearExpiredKeys(),
            'optimizeCache' => $task->optimizeCache(),
            'fullCleanup' => $task->handle(),
            default => $task->handle(),
        };

        $this->info('Cache maintenance completed');
        return self::SUCCESS;
    }
}
