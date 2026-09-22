<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Queue;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:forget', description: 'Delete a failed queue job')]
class ForgetCommand extends FailedJobsCommand
{
    protected function configure(): void
    {
        $this->setDescription('Delete a failed queue job')
            ->addArgument('uuid', InputArgument::REQUIRED, 'Failed job uuid')
            ->addConnectionOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->failedStore($input);
        if ($store === null) {
            return self::FAILURE;
        }

        $uuid = (string) $input->getArgument('uuid');
        if (!$store->forgetFailed($uuid)) {
            $this->error("No failed job {$uuid}.");
            return self::FAILURE;
        }
        $this->info("Deleted failed job {$uuid}.");

        return self::SUCCESS;
    }
}
