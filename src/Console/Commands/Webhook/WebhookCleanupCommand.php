<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Webhook;

use Glueful\Api\Webhooks\Webhook;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'webhook:cleanup',
    description: 'Delete webhook delivery records past their retention'
)]
class WebhookCleanupCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Delete webhook delivery records past their retention')
            ->setHelp(
                'Removes delivered records older than api.webhooks.cleanup.keep_successful_days and '
                . 'failed records older than keep_failed_days. Pending and retrying deliveries stay.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Webhook::setContext($this->getContext());
        $removed = Webhook::cleanup();

        $this->success(sprintf(
            'Removed %d delivered and %d failed delivery records.',
            $removed['delivered'],
            $removed['failed']
        ));

        return self::SUCCESS;
    }
}
