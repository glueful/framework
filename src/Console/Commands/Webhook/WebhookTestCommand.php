<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Webhook;

use Glueful\Api\Webhooks\Webhook;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'webhook:test',
    description: 'Send a test webhook to an endpoint'
)]
class WebhookTestCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Send a test webhook to an endpoint')
            ->setHelp('This command sends a test webhook to verify the endpoint is working correctly.')
            ->addArgument(
                'url',
                InputArgument::REQUIRED,
                'The webhook endpoint URL to test'
            )
            ->addOption(
                'event',
                'e',
                InputOption::VALUE_REQUIRED,
                'The event name to use',
                'webhook.test'
            )
            ->addOption(
                'secret',
                's',
                InputOption::VALUE_REQUIRED,
                'The secret to use for signing'
            )
            ->addOption(
                'subscription',
                'S',
                InputOption::VALUE_REQUIRED,
                'Use the URL and secret from an existing subscription UUID'
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
        $url = $input->getArgument('url');
        $event = $input->getOption('event');
        $secret = $input->getOption('secret');
        $subscriptionUuid = $input->getOption('subscription');
        $jsonOutput = (bool) $input->getOption('json');

        // If subscription UUID is provided, use its URL and secret
        if ($subscriptionUuid !== null) {
            $subscription = Webhook::findSubscription($subscriptionUuid);
            if ($subscription === null) {
                if ($jsonOutput) {
                    $this->line(json_encode(['error' => 'Subscription not found']));
                } else {
                    $this->error('Subscription not found: ' . $subscriptionUuid);
                }
                return self::FAILURE;
            }
            $url = $subscription->url;
            $secret = $subscription->secret;
        }

        if (!$jsonOutput) {
            $this->line('');
            $this->line("<info>Testing webhook endpoint:</info> {$url}");
            $this->line("<info>Event:</info> {$event}");
            $this->line('');
        }

        $result = Webhook::test($url, $event, $secret);

        if ($jsonOutput) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } else {
            if ($result['success']) {
                $this->success('Webhook test successful!');
                $this->line("  Status Code: {$result['status_code']}");
                if (isset($result['response']) && $result['response'] !== '') {
                    $responsePreview = substr($result['response'], 0, 500);
                    if (strlen($result['response']) > 500) {
                        $responsePreview .= '... (truncated)';
                    }
                    $this->line("  Response: {$responsePreview}");
                }
            } else {
                $this->error('Webhook test failed!');
                if (isset($result['status_code'])) {
                    $this->line("  Status Code: {$result['status_code']}");
                }
                if (isset($result['error'])) {
                    $this->line("  Error: {$result['error']}");
                }
            }
        }

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }
}
