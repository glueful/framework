<?php

declare(strict_types=1);

namespace Glueful\Queue\Jobs;

use Glueful\Bootstrap\ApplicationContext;
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
        try {
            $container = $this->context !== null ? container($this->context) : null;
            if ($container !== null && $container->has(NotificationService::class)) {
                $service = $container->get(NotificationService::class);
                if ($service instanceof NotificationService) {
                    return $service;
                }
            }
        } catch (\Throwable) {
        }

        $channelManager = new \Glueful\Notifications\Services\ChannelManager();
        $events = null;
        try {
            $container = $this->context !== null ? container($this->context) : null;
            if ($container !== null && $container->has(\Glueful\Events\EventService::class)) {
                $events = $container->get(\Glueful\Events\EventService::class);
            }
        } catch (\Throwable) {
            $events = null;
        }

        $dispatcher = new \Glueful\Notifications\Services\NotificationDispatcher(
            $channelManager,
            null,
            [],
            $events instanceof \Glueful\Events\EventService ? $events : null
        );

        $emailProviderClass = '\\Glueful\\Extensions\\EmailNotification\\EmailNotificationProvider';
        if (class_exists($emailProviderClass)) {
            $emailProvider = new $emailProviderClass();
            $emailProvider->initialize();
            if (method_exists($emailProvider, 'register')) {
                $emailProvider->register($channelManager);
            }
            $name = method_exists($emailProvider, 'getExtensionName')
                ? $emailProvider->getExtensionName()
                : 'email_notification';
            if ($dispatcher->getExtension($name) === null) {
                $dispatcher->registerExtension($emailProvider);
            }
        }

        $repository = new \Glueful\Repository\NotificationRepository();
        return new NotificationService($dispatcher, $repository, context: $this->context);
    }
}
