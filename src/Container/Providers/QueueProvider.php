<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\{DefinitionInterface};

final class QueueProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Registry and plugin manager
        $defs[\Glueful\Queue\Registry\DriverRegistry::class] =
            $this->autowire(\Glueful\Queue\Registry\DriverRegistry::class);
        $defs[\Glueful\Queue\Plugins\PluginManager::class] =
            $this->autowire(\Glueful\Queue\Plugins\PluginManager::class);

        // Manager and helpers
        $defs[\Glueful\Queue\QueueManager::class] =
            $this->autowire(\Glueful\Queue\QueueManager::class);
        $defs[\Glueful\Queue\Monitoring\WorkerMonitor::class] =
            $this->autowire(\Glueful\Queue\Monitoring\WorkerMonitor::class);
        $defs[\Glueful\Queue\Failed\FailedJobProvider::class] =
            $this->autowire(\Glueful\Queue\Failed\FailedJobProvider::class);
        $defs[\Glueful\Scheduler\JobScheduler::class] =
            $this->autowire(\Glueful\Scheduler\JobScheduler::class);

        // String aliases for convenience
        $this->tag('queue', 'service.alias');

        return $defs;
    }
}
