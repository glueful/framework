<?php

declare(strict_types=1);

namespace Glueful\Queue\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Notifications\Contracts\NotificationStoreInterface;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Queue\Job;

/**
 * Delivers notifications sent with a `schedule` once they fall due.
 *
 * Scheduled notifications live in the notifications tables, so without notification persistence
 * (`NOTIFICATIONS_DATABASE_STORE=false`) there is nothing to deliver and the job returns quietly.
 *
 * Schedule it from config/schedule.php:
 *   ['name' => 'scheduled_notifications', 'schedule' => '* * * * *',
 *    'handler_class' => \Glueful\Queue\Jobs\ScheduledNotificationsJob::class,
 *    'parameters' => ['options' => ['limit' => 50]]]
 */
class ScheduledNotificationsJob extends Job
{
    use ResolvesJobLogger;

    private const DEFAULT_LIMIT = 50;

    public function __construct(array $data = [], ?ApplicationContext $context = null)
    {
        parent::__construct($data, $context);
        $this->queue = 'notifications';
    }

    public function handle(): void
    {
        if ($this->context === null) {
            throw new \RuntimeException('ScheduledNotificationsJob needs the application context');
        }

        $container = container($this->context);
        $store = $container->has(NotificationStoreInterface::class)
            ? $container->get(NotificationStoreInterface::class)
            : null;
        if (!$store instanceof NotificationStoreInterface || !$store->isPersistent()) {
            return;
        }

        $service = $container->get(NotificationService::class);
        if (!$service instanceof NotificationService) {
            throw new \RuntimeException('NotificationService is not available from the container');
        }

        $options = (array) ($this->getData()['options'] ?? []);
        $limit = (int) ($options['limit'] ?? self::DEFAULT_LIMIT);

        $result = $service->processScheduledNotifications($limit > 0 ? $limit : self::DEFAULT_LIMIT);

        if (($result['processed'] ?? 0) > 0) {
            $this->jobLogger()->info('Scheduled notifications processed', $result);
        }
    }
}
