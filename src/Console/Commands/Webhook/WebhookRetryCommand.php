<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Webhook;

use Glueful\Api\Webhooks\Webhook;
use Glueful\Api\Webhooks\WebhookDelivery;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'webhook:retry',
    description: 'Retry failed webhook deliveries'
)]
class WebhookRetryCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Retry failed webhook deliveries')
            ->setHelp('This command retries failed webhook deliveries, either specific ones or in bulk.')
            ->addArgument(
                'uuid',
                InputArgument::OPTIONAL,
                'The specific delivery UUID to retry'
            )
            ->addOption(
                'failed',
                'f',
                InputOption::VALUE_NONE,
                'Retry all failed deliveries'
            )
            ->addOption(
                'since',
                's',
                InputOption::VALUE_REQUIRED,
                'Only retry failures since this time (e.g., "1 hour ago", "2024-01-01")'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum number of deliveries to retry',
                100
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be retried without actually retrying'
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
        $uuid = $input->getArgument('uuid');
        $retryFailed = (bool) $input->getOption('failed');
        $since = $input->getOption('since');
        $limit = (int) $input->getOption('limit');
        $dryRun = (bool) $input->getOption('dry-run');
        $jsonOutput = (bool) $input->getOption('json');

        // Single delivery retry
        if ($uuid !== null) {
            return $this->retrySingle($uuid, $dryRun, $jsonOutput);
        }

        // Bulk retry
        if ($retryFailed) {
            return $this->retryBulk($since, $limit, $dryRun, $jsonOutput);
        }

        if (!$jsonOutput) {
            $this->error('Please specify either a delivery UUID or use --failed for bulk retry');
        } else {
            $this->line(json_encode(['error' => 'No delivery specified']));
        }

        return self::FAILURE;
    }

    private function retrySingle(string $uuid, bool $dryRun, bool $jsonOutput): int
    {
        $delivery = Webhook::findDelivery($uuid);

        if ($delivery === null) {
            if ($jsonOutput) {
                $this->line(json_encode(['error' => 'Delivery not found']));
            } else {
                $this->error('Delivery not found: ' . $uuid);
            }
            return self::FAILURE;
        }

        if (!$delivery->isFailed() && !$delivery->isRetrying()) {
            if ($jsonOutput) {
                $response = ['error' => 'Delivery is not in a failed state', 'status' => $delivery->status];
                $this->line(json_encode($response));
            } else {
                $this->error("Delivery is not in a failed state (current: {$delivery->status})");
            }
            return self::FAILURE;
        }

        if ($dryRun) {
            if ($jsonOutput) {
                $this->line(json_encode(['dry_run' => true, 'would_retry' => $uuid]));
            } else {
                $this->info("Would retry delivery: {$uuid}");
            }
            return self::SUCCESS;
        }

        $success = Webhook::retry($uuid);

        if ($jsonOutput) {
            $this->line(json_encode([
                'success' => $success,
                'uuid' => $uuid,
                'queued' => $success,
            ]));
        } else {
            if ($success) {
                $this->success("Delivery {$uuid} queued for retry");
            } else {
                $this->error("Failed to queue retry for {$uuid}");
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }

    private function retryBulk(?string $since, int $limit, bool $dryRun, bool $jsonOutput): int
    {
        $sinceDate = null;
        if ($since !== null) {
            $timestamp = strtotime($since);
            if ($timestamp === false) {
                if ($jsonOutput) {
                    $this->line(json_encode(['error' => 'Invalid date format']));
                } else {
                    $this->error('Invalid date format: ' . $since);
                }
                return self::FAILURE;
            }
            $sinceDate = date('Y-m-d H:i:s', $timestamp);
        }

        $deliveries = Webhook::failedDeliveries($limit, $sinceDate);

        if (count($deliveries) === 0) {
            if ($jsonOutput) {
                $this->line(json_encode(['message' => 'No failed deliveries found', 'count' => 0]));
            } else {
                $this->info('No failed deliveries found');
            }
            return self::SUCCESS;
        }

        if ($dryRun) {
            $uuids = array_map(fn(WebhookDelivery $d) => $d->uuid, $deliveries);
            if ($jsonOutput) {
                $this->line(json_encode([
                    'dry_run' => true,
                    'would_retry' => count($deliveries),
                    'deliveries' => $uuids,
                ], JSON_PRETTY_PRINT));
            } else {
                $this->info('Would retry ' . count($deliveries) . ' delivery(ies):');
                foreach ($uuids as $deliveryUuid) {
                    $this->line("  - {$deliveryUuid}");
                }
            }
            return self::SUCCESS;
        }

        $queued = 0;
        $failed = 0;
        $results = [];

        foreach ($deliveries as $delivery) {
            $success = Webhook::retry($delivery->uuid);
            if ($success) {
                $queued++;
                $results[] = ['uuid' => $delivery->uuid, 'queued' => true];
            } else {
                $failed++;
                $results[] = ['uuid' => $delivery->uuid, 'queued' => false];
            }
        }

        if ($jsonOutput) {
            $this->line(json_encode([
                'total' => count($deliveries),
                'queued' => $queued,
                'failed' => $failed,
                'results' => $results,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->line('');
            $this->line("<info>Retry Results:</info>");
            $this->line("  Total:   " . count($deliveries));
            $this->line("  Queued:  <fg=green>{$queued}</>");
            if ($failed > 0) {
                $this->line("  Failed:  <fg=red>{$failed}</>");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
