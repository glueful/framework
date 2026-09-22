<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Queue;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:failed', description: 'List failed queue jobs')]
class FailedCommand extends FailedJobsCommand
{
    protected function configure(): void
    {
        $this->setDescription('List failed queue jobs')
            ->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Only this queue')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many to show', '50')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON')
            ->addConnectionOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->failedStore($input);
        if ($store === null) {
            return self::FAILURE;
        }

        $queue = $input->getOption('queue');
        $failed = $store->failedJobs(is_string($queue) ? $queue : null, max(1, (int) $input->getOption('limit')));

        if ((bool) $input->getOption('json')) {
            $this->displayJson($failed);
            return self::SUCCESS;
        }
        if ($failed === []) {
            $this->info('No failed jobs.');
            return self::SUCCESS;
        }

        $this->table(['UUID', 'Queue', 'Job', 'Failed at', 'Error'], array_map(
            static fn(array $f): array => [
                $f['uuid'],
                $f['queue'],
                $f['job'],
                $f['failed_at'],
                mb_strimwidth(strtok($f['exception'], "\n") ?: '', 0, 80, '…'),
            ],
            $failed
        ));
        $this->line('Retry one with: php glueful queue:retry <uuid>');

        return self::SUCCESS;
    }
}
