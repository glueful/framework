<?php

declare(strict_types=1);

namespace Glueful\Queue\Jobs;

use Glueful\Logging\LogManager;

/**
 * The logger a scheduled framework job reports through: the application's `LogManager` when the
 * job runs with a context whose container binds one, the static instance otherwise. The
 * container does not bind `LogManager` in every application (a skeleton install does not), so
 * the lookup is guarded — an unguarded `get()` failed the whole scheduler tick.
 */
trait ResolvesJobLogger
{
    protected function jobLogger(): LogManager
    {
        if ($this->context !== null && $this->context->hasContainer()) {
            $container = $this->context->getContainer();
            if ($container->has(LogManager::class)) {
                $logger = $container->get(LogManager::class);
                if ($logger instanceof LogManager) {
                    return $logger;
                }
            }
        }
        return LogManager::getInstance();
    }
}
