<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Queue;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:flush', description: 'Delete all failed queue jobs')]
class FlushCommand extends FailedJobsCommand
{
    protected function configure(): void
    {
        $this->setDescription('Delete all failed queue jobs')
            ->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Only this queue')
            ->addConnectionOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->failedStore($input);
        if ($store === null) {
            return self::FAILURE;
        }

        $queue = $input->getOption('queue');
        $removed = $store->flushFailed(is_string($queue) ? $queue : null);
        $this->info("Deleted {$removed} failed job(s).");

        return self::SUCCESS;
    }
}
