<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use Glueful\Api\RateLimiting\Attributes\RateLimit;
use Glueful\Api\RateLimiting\Attributes\RateLimitCost;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\AttributeRouteLoader;
use Glueful\Routing\Attributes\{Fields, Get, RequireScope};
use Glueful\Routing\Route;
use Glueful\Routing\RouteCache;
use Glueful\Routing\RouteMiddleware;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The compiled route cache (storage/cache/routes_{env}.php) must carry every piece of per-route
 * config that request-time middleware reads off the Route object. Previously only handler,
 * middleware, path and constraints were written, so a route hydrated from the cache silently
 * lost its rate limits (no `rate_limit` auto-attach), its #[Fields] policy (no `field_selection`
 * auto-attach) and its #[RequireScope] groups (the `require_scope` middleware then saw no config
 * and let the request through).
 */
final class RouteCacheConfigRoundTripTest extends TestCase
{
    private ApplicationContext $context;
    private ContainerInterface $container;
    private CacheCountingMiddleware $rateLimit;
    private CacheCountingMiddleware $fieldSelection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new ApplicationContext(sys_get_temp_dir() . '/route_cache_rt_' . uniqid('', true));
        (new RouteCache($this->context))->clear();

        $this->container = new class implements ContainerInterface {
            /** @var array<string,mixed> */
            private array $services = [];

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }

            public function get(string $id): mixed
            {
                if ($this->has($id)) {
                    return $this->services[$id];
                }

                throw new class (
                    "Service '" . $id . "' not found"
                ) extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                };
            }

            public function set(string $id, mixed $service): void
            {
                $this->services[$id] = $service;
            }
        };
        $this->container->set(ApplicationContext::class, $this->context);

        $this->rateLimit = new CacheCountingMiddleware();
        $this->container->set('rate_limit', $this->rateLimit);
        $this->fieldSelection = new CacheCountingMiddleware();
        $this->container->set('field_selection', $this->fieldSelection);
        $this->container->set('require_scope', new CacheCountingMiddleware());
        $this->container->set(CacheFixtureController::class, new CacheFixtureController());
    }

    protected function tearDown(): void
    {
        (new RouteCache($this->context))->clear();
        parent::tearDown();
    }

    public function testStaticRouteConfigSurvivesCacheRoundTrip(): void
    {
        $restored = $this->roundTrip(function (Router $router): void {
            $router->get('/cached/static', [CacheFixtureController::class, 'plain'])
                ->rateLimit(5, 2, 'pro', 'fixed', 'user')
                ->setFieldsConfig(['allowed' => ['id', 'name'], 'strict' => true])
                ->setRateLimitCost(3)
                ->setRequireScopeConfig([['read:posts', 'admin:posts'], ['publish:posts']])
                ->setVersionConfig(['versions' => ['1', '2']]);
        });

        $route = $restored->getStaticRoutes()['GET:/cached/static'] ?? null;
        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame([[
            'attempts' => 5,
            'decaySeconds' => 120,
            'tier' => 'pro',
            'algorithm' => 'fixed',
            'by' => 'user',
        ]], $route->getRateLimitConfig());
        $this->assertSame(['allowed' => ['id', 'name'], 'strict' => true], $route->getFieldsConfig());
        $this->assertSame(3, $route->getRateLimitCost());
        $this->assertSame([['read:posts', 'admin:posts'], ['publish:posts']], $route->getRequireScopeConfig());
        $this->assertSame(['versions' => ['1', '2']], $route->getVersionConfig());
    }

    public function testDynamicRouteConfigSurvivesCacheRoundTrip(): void
    {
        $restored = $this->roundTrip(function (Router $router): void {
            $router->get('/cached/items/{id}', [CacheFixtureController::class, 'plain'])
                ->where('id', '\d+')
                ->rateLimit(7)
                ->setFieldsConfig([])
                ->setRateLimitCost(2)
                ->setRequireScopeConfig([['read:items']]);
        });

        $route = $restored->getDynamicRoutes()['GET'][0] ?? null;
        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame(7, $route->getRateLimitConfig()[0]['attempts'] ?? null);
        // A bare #[Fields] stores an empty (non-null) config, which must stay non-null.
        $this->assertSame([], $route->getFieldsConfig());
        $this->assertSame(2, $route->getRateLimitCost());
        $this->assertSame([['read:items']], $route->getRequireScopeConfig());
    }

    public function testRouteWithoutConfigRestoresWithoutConfig(): void
    {
        $restored = $this->roundTrip(function (Router $router): void {
            $router->get('/cached/open', [CacheFixtureController::class, 'plain']);
        });

        $route = $restored->getStaticRoutes()['GET:/cached/open'] ?? null;
        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame([], $route->getRateLimitConfig());
        $this->assertNull($route->getFieldsConfig());
        $this->assertNull($route->getRateLimitCost());
        $this->assertSame([], $route->getRequireScopeConfig());
        $this->assertNull($route->getVersionConfig());
    }

    public function testCachedAttributeRoutesStillAutoAttachRateLimitAndFieldSelection(): void
    {
        $restored = $this->roundTrip(function (Router $router): void {
            (new AttributeRouteLoader($router))->processClass(CacheFixtureController::class);
        });

        $restored->dispatch(Request::create('/cached/attr/limited', 'GET'));
        $this->assertSame(1, $this->rateLimit->calls, 'rate_limit must run for a cache-hydrated #[RateLimit]');
        $this->assertSame(0, $this->fieldSelection->calls);

        $restored->dispatch(Request::create('/cached/attr/fields/9', 'GET'));
        $this->assertSame(1, $this->fieldSelection->calls, 'field_selection must run for a cache-hydrated #[Fields]');
        $this->assertSame(1, $this->rateLimit->calls);

        $scoped = $restored->getStaticRoutes()['GET:/cached/attr/scoped'] ?? null;
        $this->assertInstanceOf(Route::class, $scoped);
        $this->assertSame([['write:posts']], $scoped->getRequireScopeConfig());
        $this->assertSame(4, $scoped->getRateLimitCost());
    }

    /**
     * Register routes on a fresh router, write the compiled cache, then build a second router
     * that hydrates from that cache (the production boot path).
     */
    private function roundTrip(callable $register): Router
    {
        $writer = new Router($this->container);
        $this->assertFalse($writer->wasLoadedFromCache(), 'precondition: no cache yet');
        $register($writer);
        $this->assertTrue((new RouteCache($this->context))->save($writer), 'cache written');

        $restored = new Router($this->container);
        $this->assertTrue($restored->wasLoadedFromCache(), 'precondition: router hydrated from cache');

        return $restored;
    }
}

final class CacheCountingMiddleware implements RouteMiddleware
{
    public int $calls = 0;

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $this->calls++;
        return $next($request);
    }
}

final class CacheFixtureController
{
    public function plain(): Response
    {
        return new Response('ok');
    }

    #[Get('/cached/attr/limited')]
    #[RateLimit(attempts: 10)]
    public function limited(): Response
    {
        return new Response('ok');
    }

    #[Get('/cached/attr/fields/{id}')]
    #[Fields(allowed: ['id', 'name'], strict: true)]
    public function fields(string $id): Response
    {
        return new Response('ok ' . $id);
    }

    #[Get('/cached/attr/scoped')]
    #[RequireScope('write:posts')]
    #[RateLimitCost(4)]
    public function scoped(): Response
    {
        return new Response('ok');
    }
}
