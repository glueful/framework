<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Webhook;

use Glueful\Api\Webhooks\WebhookSubscription;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'webhook:list',
    description: 'List webhook subscriptions',
    aliases: ['webhooks']
)]
class WebhookListCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('List webhook subscriptions')
            ->setHelp('This command displays all webhook subscriptions with their status and events.')
            ->addOption(
                'active',
                'a',
                InputOption::VALUE_NONE,
                'Show only active subscriptions'
            )
            ->addOption(
                'inactive',
                'i',
                InputOption::VALUE_NONE,
                'Show only inactive subscriptions'
            )
            ->addOption(
                'stats',
                's',
                InputOption::VALUE_NONE,
                'Include delivery statistics'
            )
            ->addOption(
                'json',
                'j',
                InputOption::VALUE_NONE,
                'Output in JSON format'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $activeOnly = (bool) $input->getOption('active');
        $inactiveOnly = (bool) $input->getOption('inactive');
        $showStats = (bool) $input->getOption('stats');
        $jsonOutput = (bool) $input->getOption('json');

        $query = WebhookSubscription::query();

        if ($activeOnly) {
            $query->where('is_active', true);
        } elseif ($inactiveOnly) {
            $query->where('is_active', false);
        }

        $results = $query->get();

        $subscriptions = [];
        /** @var WebhookSubscription $subscription */
        foreach ($results as $subscription) {
            $data = [
                'uuid' => $subscription->uuid,
                'url' => $subscription->url,
                'events' => $subscription->events,
                'is_active' => $subscription->is_active,
                'created_at' => $subscription->created_at,
            ];

            if ($showStats) {
                $stats = $subscription->stats(30);
                $data['stats'] = $stats;
                $data['success_rate'] = $subscription->successRate(30);
            }

            $subscriptions[] = $data;
        }

        if ($jsonOutput) {
            $this->line(json_encode(['subscriptions' => $subscriptions], JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        if (count($subscriptions) === 0) {
            $this->info('No webhook subscriptions found.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->line('<info>Webhook Subscriptions</info>');
        $this->line('');

        foreach ($subscriptions as $sub) {
            $status = $sub['is_active'] ? '<fg=green>active</>' : '<fg=red>inactive</>';
            $this->line("  <comment>{$sub['uuid']}</comment>");
            $this->line("    URL:     {$sub['url']}");
            $this->line("    Status:  {$status}");
            $this->line("    Events:  " . implode(', ', $sub['events']));
            $this->line("    Created: {$sub['created_at']}");

            if ($showStats && isset($sub['stats'])) {
                $delivered = $sub['stats']['delivered'];
                $total = $sub['stats']['total'];
                $rate = $sub['success_rate'];
                $this->line("    Stats (30d): {$delivered}/{$total} delivered ({$rate}%)");
            }

            $this->line('');
        }

        $this->line("Total: " . count($subscriptions) . " subscription(s)");

        return self::SUCCESS;
    }
}
