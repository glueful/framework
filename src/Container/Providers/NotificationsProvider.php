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

        // Channel manager as a shared singleton
        $defs[\Glueful\Notifications\Services\ChannelManager::class] =
            $this->autowire(\Glueful\Notifications\Services\ChannelManager::class, shared: true);

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

        $defs[\Glueful\Notifications\Services\NotificationService::class] = new FactoryDefinition(
            \Glueful\Notifications\Services\NotificationService::class,
            function ($c) {
                $dispatcher = $c->get(\Glueful\Notifications\Services\NotificationDispatcher::class);
                $repository = $c->has(\Glueful\Repository\NotificationRepository::class)
                    ? $c->get(\Glueful\Repository\NotificationRepository::class)
                    : new \Glueful\Repository\NotificationRepository();

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
                    $repository,
                    $templateManager instanceof \Glueful\Notifications\Templates\TemplateManager
                        ? $templateManager
                        : null,
                    null,
                    $config,
                    $context
                );
            },
            true
        );

        return $defs;
    }
}
