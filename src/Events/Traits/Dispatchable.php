<?php

declare(strict_types=1);

namespace Glueful\Events\Traits;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;

    /**
     * Dispatchable Trait
     *
     * Allows events to be dispatched using a static method.
     * Inspired by Laravel's Dispatchable trait.
     */
trait Dispatchable
{
    public static function dispatch(ApplicationContext $context, ...$args): static
    {
        $event = new static(...$args);
        app($context, EventService::class)->dispatch($event);
        return $event;
    }

    public static function dispatchIf(ApplicationContext $context, bool $condition, ...$args): ?static
    {
        if ($condition) {
            return static::dispatch($context, ...$args);
        }
        return null;
    }

    public static function dispatchUnless(ApplicationContext $context, bool $condition, ...$args): ?static
    {
        if (!$condition) {
            return static::dispatch($context, ...$args);
        }
        return null;
    }
}
