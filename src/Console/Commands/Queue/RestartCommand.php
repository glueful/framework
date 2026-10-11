<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Queue;

use Glueful\Console\BaseCommand;
use Glueful\Queue\RestartSignal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:restart', description: 'Ask every queue worker to exit after its current job')]
final class RestartCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var RestartSignal $signal */
        $signal = $this->getService(RestartSignal::class);
        $signal->signal(time());
        $this->info('Workers will exit after their current job; your supervisor starts them again.');
        return self::SUCCESS;
    }
}
