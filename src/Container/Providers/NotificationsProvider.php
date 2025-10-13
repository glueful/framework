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
                return new \Glueful\Notifications\Services\NotificationDispatcher($channelManager, $logger);
            },
            true
        );

        return $defs;
    }
}
