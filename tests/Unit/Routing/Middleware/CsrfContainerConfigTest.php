<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing\Middleware;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Providers\CoreProvider;
use Glueful\Container\Providers\TagCollector;
use Glueful\Routing\Middleware\CSRFMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The container-built `csrf` middleware must honour config/security.php's `csrf` block
 * (enabled, tokenLifetime, useDoubleSubmit, exemptRoutes) rather than running with the
 * constructor defaults (7200s, no exemptions).
 */
final class CsrfContainerConfigTest extends TestCase
{
    private function resolve(ApplicationContext $context): CSRFMiddleware
    {
        $provider = new CoreProvider(new TagCollector(), $context);
        $container = new Container($provider->defs());

        $middleware = $container->get('csrf');
        self::assertInstanceOf(CSRFMiddleware::class, $middleware);

        return $middleware;
    }

    private function prop(CSRFMiddleware $middleware, string $name): mixed
    {
        $property = new \ReflectionProperty($middleware, $name);
        $property->setAccessible(true);

        return $property->getValue($middleware);
    }

    public function test_container_instance_applies_shipped_config_block(): void
    {
        // tests/Unit/Routing/Middleware -> framework root is dirname(__DIR__, 4)
        /** @var array<string, mixed> $security */
        $security = require dirname(__DIR__, 4) . '/config/security.php';
        $context = new ApplicationContext(__DIR__);
        $context->mergeConfigDefaults('security', $security);
        $middleware = $this->resolve($context);

        self::assertSame(3600, $this->prop($middleware, 'tokenLifetime'));
        self::assertFalse($this->prop($middleware, 'useDoubleSubmit'));
        self::assertTrue($this->prop($middleware, 'enabled'));
        self::assertContains('auth/login', $this->prop($middleware, 'exemptRoutes'));
        self::assertContains('webhooks/*', $this->prop($middleware, 'exemptRoutes'));
    }

    public function test_container_instance_applies_overridden_values(): void
    {
        $context = new ApplicationContext(__DIR__);
        $context->mergeConfigDefaults('security', ['csrf' => [
            'enabled' => 'false',
            'tokenLifetime' => '900',
            'useDoubleSubmit' => 'true',
            'exemptRoutes' => ['/hooks/*'],
        ]]);
        $middleware = $this->resolve($context);

        self::assertSame(900, $this->prop($middleware, 'tokenLifetime'));
        self::assertTrue($this->prop($middleware, 'useDoubleSubmit'));
        self::assertFalse($this->prop($middleware, 'enabled'));
        self::assertSame(['hooks/*'], $this->prop($middleware, 'exemptRoutes'));
    }

    public function test_absent_keys_fall_back_to_constructor_defaults(): void
    {
        $middleware = $this->resolve(new ApplicationContext(__DIR__));

        self::assertSame(7200, $this->prop($middleware, 'tokenLifetime'));
        self::assertFalse($this->prop($middleware, 'useDoubleSubmit'));
        self::assertTrue($this->prop($middleware, 'enabled'));
        self::assertSame([], $this->prop($middleware, 'exemptRoutes'));
    }

    public function test_configured_exempt_route_skips_csrf_on_unsafe_request(): void
    {
        $context = new ApplicationContext(__DIR__);
        $context->mergeConfigDefaults('security', ['csrf' => ['exemptRoutes' => ['webhooks/*']]]);
        $middleware = $this->resolve($context);

        $response = $middleware->handle(
            Request::create('/webhooks/stripe', 'POST'),
            static fn(): Response => new Response('passed', 200)
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('passed', $response->getContent());
    }
}
