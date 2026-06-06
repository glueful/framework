<?php

declare(strict_types=1);

namespace Glueful\Tasks;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Logging\LogManager;
use Glueful\Notifications\Contracts\NotificationStoreInterface;
use Glueful\Notifications\Exceptions\NotificationContextRequiredException;
use Glueful\Notifications\Services\NotificationRetryService;
use Glueful\Notifications\Services\NotificationService;

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

        // Notification service: prefer an injected one, else resolve the container-wired service
        // (capability-aware store + the registered `database` channel + extension channels).
        // No ad-hoc fallback: dispatch requires a container context.
        if ($notificationService !== null) {
            $this->notificationService = $notificationService;
        } else {
            if ($context === null) {
                throw NotificationContextRequiredException::forConsumer(self::class);
            }
            $resolved = app($context, NotificationService::class);
            if (!$resolved instanceof NotificationService) {
                throw NotificationContextRequiredException::forConsumer(self::class);
            }
            $this->notificationService = $resolved;
        }

        // Retry service: prefer an injected one, else build with the capability-aware store (so it
        // throws cleanly when persistence is off). Also requires a context.
        if ($retryService !== null) {
            $this->retryService = $retryService;
        } else {
            if ($context === null) {
                throw NotificationContextRequiredException::forConsumer(self::class);
            }
            $store = app($context, NotificationStoreInterface::class);
            $this->retryService = new NotificationRetryService(
                $logger,
                $store instanceof NotificationStoreInterface ? $store : null,
                [],
                $context
            );
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
