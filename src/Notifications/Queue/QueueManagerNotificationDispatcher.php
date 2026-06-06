<?php

declare(strict_types=1);

namespace Glueful\Notifications\Queue;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Notifications\Contracts\NotificationQueueDispatcherInterface;
use Glueful\Queue\QueueManager;

/**
 * Default {@see NotificationQueueDispatcherInterface} implementation backed by `QueueManager`.
 *
 * Mirrors the previous inline behaviour of `NotificationService::queueAsyncDispatch()`: builds a
 * context-configured `QueueManager` when a context is available, otherwise the default manager,
 * and swallows failures (best-effort async dispatch must not break the originating request).
 *
 * @package Glueful\Notifications\Queue
 */
final class QueueManagerNotificationDispatcher implements NotificationQueueDispatcherInterface
{
    public function __construct(private readonly ?ApplicationContext $context = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $job, array $payload, ?string $queue = null): ?string
    {
        try {
            return $this->resolveManager()->push($job, $payload, $queue);
        } catch (\Throwable $e) {
            error_log('[NotificationQueueDispatcher] Failed to queue dispatch: ' . $e->getMessage());
            return null;
        }
    }

    private function resolveManager(): QueueManager
    {
        if ($this->context !== null) {
            $queueConfig = function_exists('loadConfigWithHierarchy')
                ? loadConfigWithHierarchy($this->context, 'queue')
                : [];

            return new QueueManager($queueConfig, $this->context);
        }

        return QueueManager::createDefault();
    }
}
