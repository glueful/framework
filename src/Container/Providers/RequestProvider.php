<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition};
use Symfony\Component\HttpFoundation\Request;

final class RequestProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Symfony Request
        $defs[Request::class] = new FactoryDefinition(
            Request::class,
            function (): Request {
                $this->configureTrustedProxies();
                return Request::createFromGlobals();
            }
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
        $this->tag(Request::class, 'http.request');

        return $defs;
    }

    private function configureTrustedProxies(): void
    {
        $trustedProxies = $this->trustedProxiesFromConfig();
        if ($trustedProxies === []) {
            Request::setTrustedProxies([], $this->trustedHeaderSet());
            return;
        }

        Request::setTrustedProxies($trustedProxies, $this->trustedHeaderSet());
    }

    /**
     * @return array<int, string>
     */
    private function trustedProxiesFromConfig(): array
    {
        $configured = config($this->context, 'security.trusted_proxies', []);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (!is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(mixed $proxy): string => trim((string) $proxy), $configured),
            static fn(string $proxy): bool => $proxy !== ''
        ));
    }

    private function trustedHeaderSet(): int
    {
        return Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PREFIX;
    }
}
