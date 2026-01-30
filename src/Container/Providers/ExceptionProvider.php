<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Container\Definition\DefinitionInterface;
use Glueful\Http\Exceptions\Handler;
use Glueful\Http\Exceptions\Contracts\ExceptionHandlerInterface;
use Glueful\Http\Middleware\ExceptionMiddleware;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Exception Handler Service Provider
 *
 * Registers the exception handler and middleware services for
 * centralized exception handling throughout the application.
 *
 * Services registered:
 * - Handler: Main exception handler with HTTP mapping
 * - ExceptionHandlerInterface: Interface alias to Handler
 * - ExceptionMiddleware: Middleware for catching exceptions
 * - 'exception': String alias for middleware
 */
final class ExceptionProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Register the main exception handler
        $defs[Handler::class] = new FactoryDefinition(
            Handler::class,
            function (ContainerInterface $c): Handler {
                // Get logger if available
                $logger = null;
                if ($c->has(LoggerInterface::class)) {
                    $logger = $c->get(LoggerInterface::class);
                }

                // Determine debug mode from environment
                $debug = false;
                if (function_exists('env')) {
                    $appDebug = env('APP_DEBUG', false);
                    $appEnv = env('APP_ENV', 'production');
                    $debug = $appDebug === true || $appEnv === 'development';
                }

                $events = $c->has(\Glueful\Events\EventService::class)
                    ? $c->get(\Glueful\Events\EventService::class)
                    : null;

                return new Handler($logger, $debug, $events instanceof \Glueful\Events\EventService ? $events : null);
            }
        );

        // Alias the interface to the concrete implementation
        $defs[ExceptionHandlerInterface::class] = new AliasDefinition(
            ExceptionHandlerInterface::class,
            Handler::class
        );

        // Register the exception middleware
        $defs[ExceptionMiddleware::class] = new FactoryDefinition(
            ExceptionMiddleware::class,
            fn(ContainerInterface $c): ExceptionMiddleware => new ExceptionMiddleware(
                $c->get(ExceptionHandlerInterface::class)
            )
        );

        // String alias for middleware convenience
        $defs['exception'] = new AliasDefinition(
            'exception',
            ExceptionMiddleware::class
        );

        // Also register common alias
        $defs['exception_handler'] = new AliasDefinition(
            'exception_handler',
            Handler::class
        );

        return $defs;
    }
}
