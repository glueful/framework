<?php

declare(strict_types=1);

namespace Glueful\Http\ServiceProvider;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition};
use Glueful\Container\Providers\BaseServiceProvider;

final class HttpClientProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Symfony HttpClient
        $defs[\Symfony\Contracts\HttpClient\HttpClientInterface::class] = new FactoryDefinition(
            \Symfony\Contracts\HttpClient\HttpClientInterface::class,
            function () {
                $cfg = function_exists('config') ? (array) config('http.default', []) : [];
                return \Symfony\Component\HttpClient\HttpClient::create([
                    'timeout' => $cfg['timeout'] ?? 30,
                    'max_duration' => $cfg['max_duration'] ?? 60,
                    'max_redirects' => $cfg['max_redirects'] ?? 3,
                    'http_version' => $cfg['http_version'] ?? '2.0',
                    'verify_peer' => $cfg['verify_ssl'] ?? true,
                    'verify_host' => $cfg['verify_ssl'] ?? true,
                    'headers' => $cfg['default_headers'] ?? [],
                ]);
            }
        );

        // PSR-18 client wrapper
        $defs[\Psr\Http\Client\ClientInterface::class] = new FactoryDefinition(
            \Psr\Http\Client\ClientInterface::class,
            fn(\Psr\Container\ContainerInterface $c) =>
                new \Symfony\Component\HttpClient\Psr18Client(
                    $c->get(\Symfony\Contracts\HttpClient\HttpClientInterface::class)
                )
        );

        // Glueful HTTP client facade and helpers
        $defs[\Glueful\Http\Client::class] = new FactoryDefinition(
            \Glueful\Http\Client::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Http\Client(
                $c->get(\Symfony\Contracts\HttpClient\HttpClientInterface::class),
                $c->get('logger')
            )
        );

        $defs[\Glueful\Http\Factory\ScopedClientFactory::class] = new FactoryDefinition(
            \Glueful\Http\Factory\ScopedClientFactory::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Http\Factory\ScopedClientFactory(
                $c->get(\Glueful\Http\Client::class)
            )
        );

        $defs[\Glueful\Http\Services\WebhookDeliveryService::class] = new FactoryDefinition(
            \Glueful\Http\Services\WebhookDeliveryService::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Http\Services\WebhookDeliveryService(
                $c->get(\Glueful\Http\Client::class),
                $c->get('logger')
            )
        );

        $defs[\Glueful\Http\Services\ExternalApiService::class] = new FactoryDefinition(
            \Glueful\Http\Services\ExternalApiService::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Http\Services\ExternalApiService(
                $c->get(\Glueful\Http\Client::class),
                $c->get('logger')
            )
        );

        $defs[\Glueful\Http\Services\HealthCheckService::class] = new FactoryDefinition(
            \Glueful\Http\Services\HealthCheckService::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Http\Services\HealthCheckService(
                $c->get(\Glueful\Http\Client::class),
                $c->get('logger')
            )
        );

        // Specialized client builders
        $defs[\Glueful\Http\Builders\OAuthClientBuilder::class] =
            $this->autowire(\Glueful\Http\Builders\OAuthClientBuilder::class);
        $defs[\Glueful\Http\Builders\PaymentClientBuilder::class] =
            $this->autowire(\Glueful\Http\Builders\PaymentClientBuilder::class);
        $defs[\Glueful\Http\Builders\NotificationClientBuilder::class] =
            $this->autowire(\Glueful\Http\Builders\NotificationClientBuilder::class);

        return $defs;
    }
}
