<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Queue;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:retry', description: 'Put failed queue jobs back on their queue')]
class RetryCommand extends FailedJobsCommand
{
    protected function configure(): void
    {
        $this->setDescription('Put failed queue jobs back on their queue')
            ->addArgument('uuid', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Failed job uuid(s)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Retry every failed job')
            ->addOption('queue', null, InputOption::VALUE_REQUIRED, 'With --all, only this queue')
            ->addConnectionOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->failedStore($input);
        if ($store === null) {
            return self::FAILURE;
        }

        /** @var list<string> $uuids */
        $uuids = (array) $input->getArgument('uuid');
        if ((bool) $input->getOption('all')) {
            $queue = $input->getOption('queue');
            $uuids = array_column($store->failedJobs(is_string($queue) ? $queue : null, PHP_INT_MAX), 'uuid');
        }
        if ($uuids === []) {
            $this->error('Name a failed job uuid, or pass --all.');
            return self::FAILURE;
        }

        $failures = 0;
        foreach ($uuids as $uuid) {
            try {
                $newUuid = $store->retryFailed($uuid);
                if ($newUuid === null) {
                    $this->warning("No failed job {$uuid}.");
                    $failures++;
                    continue;
                }
                $this->info("Retried {$uuid} as {$newUuid}.");
            } catch (\Throwable $e) {
                $this->error("Could not retry {$uuid}: {$e->getMessage()}");
                $failures++;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
