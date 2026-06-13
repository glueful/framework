<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container\Providers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Providers\RequestProvider;
use Glueful\Container\Providers\TagCollector;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class RequestProviderTrustedProxyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        Request::setTrustedProxies([], $this->trustedHeaderSet());
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        Request::setTrustedProxies([], $this->trustedHeaderSet());
        parent::tearDown();
    }

    public function testSymfonyRequestFactoryAppliesConfiguredTrustedProxies(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/limited',
            'SERVER_NAME' => 'example.test',
            'SERVER_PORT' => '80',
            'HTTP_HOST' => 'example.test',
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.25',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];

        $context = ApplicationContext::forTesting(dirname(__DIR__, 4));
        $context->mergeConfigDefaults('security', [
            'trusted_proxies' => ['10.0.0.1'],
        ]);

        $provider = new RequestProvider(new TagCollector(), $context);
        $defs = $provider->defs();
        $definition = $defs[Request::class] ?? null;

        $this->assertInstanceOf(FactoryDefinition::class, $definition);

        $request = $definition->resolve($this->container());

        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame('198.51.100.25', $request->getClientIp());
        $this->assertSame('https', $request->getScheme());
    }

    private function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Unexpected service lookup: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
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
