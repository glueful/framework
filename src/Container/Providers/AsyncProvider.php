<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition};
use Glueful\Container\Autowire\AutowireDefinition;
use Glueful\Async\Contracts\Http\HttpClient;
use Glueful\Async\Contracts\Scheduler;
use Glueful\Async\Http\CurlMultiHttpClient;
use Glueful\Async\Instrumentation\Metrics;
use Glueful\Async\Instrumentation\NullMetrics;
use Glueful\Async\FiberScheduler;
use Glueful\Helpers\ConfigManager;

final class AsyncProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Bind Metrics interface to NullMetrics by default
        $defs[Metrics::class] = new AutowireDefinition(Metrics::class, NullMetrics::class, shared: true);

        // Scheduler with Metrics injection
        $defs[Scheduler::class] = new FactoryDefinition(
            Scheduler::class,
            /** @return Scheduler */
            function ($c) {
                $maxConc = (int) (ConfigManager::get('async.scheduler.max_concurrent_tasks', 0));
                $maxExec = (float) (ConfigManager::get('async.scheduler.max_task_execution_seconds', 0.0));
                return new FiberScheduler($c->get(Metrics::class), $maxConc, $maxExec);
            }
        );

        // HttpClient with Metrics injection
        $defs[HttpClient::class] = new FactoryDefinition(
            HttpClient::class,
            /** @return HttpClient */
            function ($c) {
                $poll = (float) (ConfigManager::get('async.http.poll_interval_seconds', 0.01));
                $retries = (int) (ConfigManager::get('async.http.max_retries', 0));
                $retryDelay = (float) (ConfigManager::get('async.http.retry_delay_seconds', 0.0));
                $retryOn = ConfigManager::get('async.http.retry_on_status', [429, 500, 502, 503, 504]);
                $maxConc = (int) (ConfigManager::get('async.http.max_concurrent', 0));
                return new CurlMultiHttpClient(
                    $c->get(Metrics::class),
                    $poll,
                    $retries,
                    $retryDelay,
                    is_array($retryOn) ? array_values(array_map('intval', $retryOn)) : [],
                    $maxConc
                );
            }
        );

        return $defs;
    }
}
