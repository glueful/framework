<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition};

final class RequestProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Symfony Request
        $defs[\Symfony\Component\HttpFoundation\Request::class] = new FactoryDefinition(
            \Symfony\Component\HttpFoundation\Request::class,
            fn() => \Symfony\Component\HttpFoundation\Request::createFromGlobals()
        );

        // PSR-7 ServerRequest
        $defs[\Psr\Http\Message\ServerRequestInterface::class] = new FactoryDefinition(
            \Psr\Http\Message\ServerRequestInterface::class,
            fn() => \Glueful\Http\ServerRequestFactory::fromGlobals()
        );

        // Context helpers
        $defs[\Glueful\Http\RequestContext::class] = new FactoryDefinition(
            \Glueful\Http\RequestContext::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Http\RequestContext(
                $c->get(\Psr\Http\Message\ServerRequestInterface::class)
            )
        );
        $defs[\Glueful\Http\SessionContext::class] = $this->autowire(\Glueful\Http\SessionContext::class);
        $defs[\Glueful\Http\EnvironmentContext::class] = $this->autowire(\Glueful\Http\EnvironmentContext::class);

        // Convenience alias
        $this->tag(\Symfony\Component\HttpFoundation\Request::class, 'http.request');

        return $defs;
    }
}
