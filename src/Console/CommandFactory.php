<?php

declare(strict_types=1);

namespace Glueful\Console;

use Glueful\Bootstrap\ApplicationContext;
use Psr\Container\ContainerInterface;

/**
 * Builds a command class the container does not define. A BaseCommand gets the app's own booted
 * container and context: with no arguments it builds a fresh context and a never-booted
 * container — a parallel world where extension boot() never ran — and silently works on different
 * state than the app.
 */
final class CommandFactory
{
    public static function make(ContainerInterface $container, string $class): object
    {
        if ($container->has($class)) {
            return $container->get($class);
        }
        if (is_subclass_of($class, BaseCommand::class)) {
            $context = $container->has(ApplicationContext::class)
                ? $container->get(ApplicationContext::class)
                : null;
            return new $class($container, $context instanceof ApplicationContext ? $context : null);
        }
        return new $class();
    }
}
