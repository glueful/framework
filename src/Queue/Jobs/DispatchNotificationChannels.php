<?php

declare(strict_types=1);

namespace Glueful\Queue\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Notifications\Exceptions\NotificationContextRequiredException;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Queue\Job;

class DispatchNotificationChannels extends Job
{
    protected ?ApplicationContext $context;

    public function __construct(array $data = [], ?ApplicationContext $context = null)
    {
        parent::__construct($data, $context);
        $this->context = $context;
        $this->queue = 'notifications';
    }

    public function handle(): void
    {
        $data = $this->getData();
        $notificationUuid = (string)($data['notification_uuid'] ?? '');
        $channels = isset($data['channels']) && is_array($data['channels']) ? $data['channels'] : [];
        $options = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];

        if ($notificationUuid === '') {
            throw new \InvalidArgumentException('notification_uuid is required');
        }

        $service = $this->resolveNotificationService();
        $result = $service->dispatchStoredNotification($notificationUuid, $channels, $options);

        $failedChannels = isset($result['failed_channels']) && is_array($result['failed_channels'])
            ? $result['failed_channels']
            : [];
        if ($failedChannels !== []) {
            throw new \RuntimeException(
                'Async notification dispatch failed for channels: ' . implode(', ', $failedChannels)
            );
        }

        if (($result['status'] ?? 'failed') === 'failed') {
            $reason = (string)($result['reason'] ?? 'dispatch_failed');
            throw new \RuntimeException("Async notification dispatch failed: {$reason}");
        }
    }

    private function resolveNotificationService(): NotificationService
    {
        // Notification dispatch resolves through the container's shared service (whose dispatcher
        // carries core + extension-registered channels/hooks). No ad-hoc managers, and no
        // hardcoded extension wiring — extensions self-register via their ServiceProvider::boot().
        if ($this->context === null) {
            throw NotificationContextRequiredException::forConsumer(self::class);
        }

        $container = container($this->context);
        $service = $container->has(NotificationService::class)
            ? $container->get(NotificationService::class)
            : null;
        if (!$service instanceof NotificationService) {
            throw NotificationContextRequiredException::forConsumer(self::class);
        }

        return $service;
    }
}
