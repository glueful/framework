<?php

declare(strict_types=1);

namespace Glueful\Queue\ServiceProvider;

use Glueful\Container\Definition\{DefinitionInterface};
use Glueful\Container\Providers\BaseServiceProvider;

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
        // queue:restart's signal, kept in the cache. Without a cache store nothing is signalled.
        $defs[\Glueful\Queue\RestartSignal::class] = new \Glueful\Container\Definition\FactoryDefinition(
            \Glueful\Queue\RestartSignal::class,
            static function (\Psr\Container\ContainerInterface $c): \Glueful\Queue\RestartSignal {
                try {
                    return new \Glueful\Queue\RestartSignal($c->get(\Glueful\Cache\CacheStore::class));
                } catch (\Throwable) {
                    return new \Glueful\Queue\RestartSignal(null);
                }
            }
        );
                // Lean job-draining worker (the only worker loop in core).
        $defs[\Glueful\Queue\QueueWorker::class] =
            $this->autowire(\Glueful\Queue\QueueWorker::class);
        // Interface seam → no-op NullWorkerMonitor by default. Real persistence
        // lives in the queue-ops capability, which overrides this binding.
        $defs[\Glueful\Queue\Contracts\WorkerMonitorInterface::class] =
            $this->autowire(\Glueful\Queue\Monitoring\NullWorkerMonitor::class);
        // Built from config/queue.php `failed.*` (autowiring always took the 5/30 defaults).
        $defs[\Glueful\Queue\Failed\FailedJobProvider::class] = new \Glueful\Container\Definition\FactoryDefinition(
            \Glueful\Queue\Failed\FailedJobProvider::class,
            static function (\Psr\Container\ContainerInterface $c): \Glueful\Queue\Failed\FailedJobProvider {
                $context = $c->has(\Glueful\Bootstrap\ApplicationContext::class)
                    ? $c->get(\Glueful\Bootstrap\ApplicationContext::class)
                    : null;
                $failed = $context !== null ? (array) config($context, 'queue.failed', []) : [];

                return new \Glueful\Queue\Failed\FailedJobProvider(
                    $c->has(\Glueful\Database\Connection::class) ? $c->get(\Glueful\Database\Connection::class) : null,
                    (string) ($failed['table'] ?? 'queue_failed_jobs'),
                    (int) ($failed['max_retries'] ?? 5),
                    (int) ($failed['retention_days'] ?? 30),
                    $context
                );
            }
        );
        $defs[\Glueful\Scheduler\JobScheduler::class] =
            $this->autowire(\Glueful\Scheduler\JobScheduler::class);

        // String aliases for convenience
        $this->tag('queue', 'service.alias');

        return $defs;
    }
}
