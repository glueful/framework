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
                return new FiberScheduler($c->get(Metrics::class));
            }
        );

        // HttpClient with Metrics injection
        $defs[HttpClient::class] = new FactoryDefinition(
            HttpClient::class,
            /** @return HttpClient */
            function ($c) {
                return new CurlMultiHttpClient($c->get(Metrics::class));
            }
        );

        return $defs;
    }
}
