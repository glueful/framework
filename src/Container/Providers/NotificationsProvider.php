<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Definition\DefinitionInterface;

final class NotificationsProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Channel manager as a shared singleton, with the core `database` (in-app) channel
        // registered by default so the framework's default `['database']` channel resolves
        // end-to-end. Its availability tracks the `notifications` persistence capability.
        $defs[\Glueful\Notifications\Services\ChannelManager::class] = new FactoryDefinition(
            \Glueful\Notifications\Services\ChannelManager::class,
            function () {
                $manager = new \Glueful\Notifications\Services\ChannelManager();
                $persistenceEnabled = (bool) config(
                    $this->getContext(),
                    'capabilities.notifications',
                    true
                );
                $manager->registerChannel(
                    new \Glueful\Notifications\Channels\DatabaseChannel($persistenceEnabled)
                );

                return $manager;
            },
            true
        );

        // Notification dispatcher depends on ChannelManager and optional LogManager
        $defs[\Glueful\Notifications\Services\NotificationDispatcher::class] = new FactoryDefinition(
            \Glueful\Notifications\Services\NotificationDispatcher::class,
            /** @param \Psr\Container\ContainerInterface $c */
            function ($c) {
                $channelManager = $c->get(\Glueful\Notifications\Services\ChannelManager::class);
                $logger = null;
                if ($c->has(\Glueful\Logging\LogManager::class)) {
                    /** @var \Glueful\Logging\LogManager $logger */
                    $logger = $c->get(\Glueful\Logging\LogManager::class);
                }
                $events = $c->has(\Glueful\Events\EventService::class)
                    ? $c->get(\Glueful\Events\EventService::class)
                    : null;

                return new \Glueful\Notifications\Services\NotificationDispatcher(
                    $channelManager,
                    $logger,
                    [],
                    $events instanceof \Glueful\Events\EventService ? $events : null
                );
            },
            true
        );

        // Persistence seam: a real repository when the `notifications` capability is enabled,
        // or a NullNotificationStore that degrades explicitly when it is disabled.
        $defs[\Glueful\Notifications\Contracts\NotificationStoreInterface::class] = new FactoryDefinition(
            \Glueful\Notifications\Contracts\NotificationStoreInterface::class,
            function ($c) {
                $persistenceEnabled = (bool) config(
                    $this->getContext(),
                    'capabilities.notifications',
                    true
                );
                if (!$persistenceEnabled) {
                    return new \Glueful\Notifications\Stores\NullNotificationStore();
                }

                return $c->has(\Glueful\Repository\NotificationRepository::class)
                    ? $c->get(\Glueful\Repository\NotificationRepository::class)
                    : new \Glueful\Repository\NotificationRepository();
            },
            true
        );

        // Async-dispatch queue seam (wraps QueueManager); shared.
        $defs[\Glueful\Notifications\Contracts\NotificationQueueDispatcherInterface::class] = new FactoryDefinition(
            \Glueful\Notifications\Contracts\NotificationQueueDispatcherInterface::class,
            fn() => new \Glueful\Notifications\Queue\QueueManagerNotificationDispatcher($this->getContext()),
            true
        );

        $defs[\Glueful\Notifications\Services\NotificationService::class] = new FactoryDefinition(
            \Glueful\Notifications\Services\NotificationService::class,
            function ($c) {
                $dispatcher = $c->get(\Glueful\Notifications\Services\NotificationDispatcher::class);
                $store = $c->get(\Glueful\Notifications\Contracts\NotificationStoreInterface::class);
                $queueDispatcher = $c->get(
                    \Glueful\Notifications\Contracts\NotificationQueueDispatcherInterface::class
                );

                $templateManager = $c->has(\Glueful\Notifications\Templates\TemplateManager::class)
                    ? $c->get(\Glueful\Notifications\Templates\TemplateManager::class)
                    : null;

                $context = $this->getContext();
                $config = [];
                if (function_exists('loadConfigWithHierarchy')) {
                    $config = loadConfigWithHierarchy($context, 'notifications');
                }

                return new \Glueful\Notifications\Services\NotificationService(
                    $dispatcher,
                    $store,
                    $templateManager instanceof \Glueful\Notifications\Templates\TemplateManager
                        ? $templateManager
                        : null,
                    null,
                    $config,
                    $context,
                    $queueDispatcher instanceof \Glueful\Notifications\Contracts\NotificationQueueDispatcherInterface
                        ? $queueDispatcher
                        : null
                );
            },
            true
        );

        return $defs;
    }
}
