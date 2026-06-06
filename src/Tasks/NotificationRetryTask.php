<?php

declare(strict_types=1);

namespace Glueful\Tasks;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Logging\LogManager;
use Glueful\Notifications\Contracts\NotificationStoreInterface;
use Glueful\Notifications\Services\NotificationRetryService;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Notifications\Services\ChannelManager;
use Glueful\Repository\NotificationRepository;

/**
 * NotificationRetryProcessor
 *
 * Processes pending notification retries.
 * Intended to be run on a schedule from the scheduler.
 *
 * @package Glueful\Cron
 */
class NotificationRetryTask
{
    /**
     * @var NotificationRetryService Notification retry service
     */
    private NotificationRetryService $retryService;

    /**
     * @var NotificationService Notification service
     */
    private NotificationService $notificationService;

    /**
     * @var LogManager|null Logger instance
     */
    private ?LogManager $logger;

    /**
     * Constructor
     *
     * @param NotificationRetryService|null $retryService Notification retry service
     * @param NotificationService|null $notificationService Notification service
     * @param LogManager|null $logger Logger instance
     */
    public function __construct(
        ?NotificationRetryService $retryService = null,
        ?NotificationService $notificationService = null,
        ?LogManager $logger = null,
        ?ApplicationContext $context = null
    ) {
        $this->logger = $logger;

        // Notification service: prefer an injected one, then the container-wired service
        // (capability-aware store + the registered `database` channel); fall back to a minimal
        // build only when no container context is available.
        if ($notificationService !== null) {
            $this->notificationService = $notificationService;
        } else {
            $resolved = $context !== null ? app($context, NotificationService::class) : null;
            if ($resolved instanceof NotificationService) {
                $this->notificationService = $resolved;
            } else {
                $dispatcher = new NotificationDispatcher(new ChannelManager(), $logger, [], null);
                $this->notificationService = new NotificationService($dispatcher, new NotificationRepository());
            }
        }

        // Retry service: build with the capability-aware store when context is available, so it
        // throws cleanly (rather than hitting a gated table) when persistence is disabled.
        if ($retryService !== null) {
            $this->retryService = $retryService;
        } elseif ($context !== null) {
            $store = app($context, NotificationStoreInterface::class);
            $this->retryService = new NotificationRetryService(
                $logger,
                $store instanceof NotificationStoreInterface ? $store : null,
                [],
                $context
            );
        } else {
            $this->retryService = new NotificationRetryService($logger);
        }
    }

    /**
     * Handle the scheduled job execution
     *
     * @param array<string, mixed> $params Command parameters
     * @return array<string, int> Result of the execution
     */
    public function handle(array $params = []): array
    {
        $limit = $params['limit'] ?? 50;

        // Process due retries
        $results = $this->retryService->processDueRetries($limit, $this->notificationService);

        // Log the results
        if ($this->logger !== null) {
            $this->logger->info("Notification retry processing completed", [
                'processed' => $results['processed'],
                'successful' => $results['successful'],
                'failed' => $results['failed'],
                'removed' => $results['removed']
            ]);
        }

        return $results;
    }
}
