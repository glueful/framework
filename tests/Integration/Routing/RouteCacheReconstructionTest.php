<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Routing;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\RouteCache;
use Glueful\Routing\RouteCompiler;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Route-cache round-trip fidelity: a route reconstructed from the compiled cache
 * must match identically to the freshly-registered route — including its param
 * extraction. Reconstructing the path by reverse-engineering the compiled regex
 * (patternToPath) desynced the capture-group count from the param names whenever a
 * where() constraint contained parentheses (e.g. a non-capturing '(?:…)' group),
 * which raised a ValueError from array_combine() on the first request.
 */
class RouteCacheReconstructionTest extends TestCase
{
    private function container(ApplicationContext $context): ContainerInterface
    {
        $c = new class implements ContainerInterface {
            /** @var array<string, mixed> */
            public array $services = [];
            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
            public function get(string $id): mixed
            {
                if ($this->has($id)) {
                    return $this->services[$id];
                }
                throw new class ("no $id")
                    extends \RuntimeException
                    implements \Psr\Container\NotFoundExceptionInterface {
                };
            }
        };
        $c->services[ApplicationContext::class] = $context;
        return $c;
    }

    public function testRouteWithNonCapturingConstraintSurvivesCacheRoundTrip(): void
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/rcr_' . uniqid());
        $cache = new RouteCache($context);
        $cache->clear();

        $container = $this->container($context);
        $router = new Router($container);

        // Controller-array handler keeps the table cacheable (no closures). The
        // constraint carries a non-capturing group — exactly the render asset route
        // that regressed once 1.66.2 enabled route caching.
        $router->get('/assets/{path}', ['App\\SomeController', 'serve'])
            ->where('path', '.+\.(?:css|js|json)');

        // Write the cache file directly from the compiler output, bypassing
        // RouteCache::save()'s OPcache warm step (environment-dependent under CLI/CI).
        $code = (new RouteCompiler())->compile($router, $cache->getSignature());
        file_put_contents($cache->getCacheFilePath(), $code);

        // A fresh Router reconstructs the whole table from the cache file.
        $cachedRouter = new Router($container);
        self::assertTrue($cachedRouter->wasLoadedFromCache(), 'precondition: routes loaded from cache');

        $route = $cachedRouter->getDynamicRoutes()['GET'][0] ?? null;
        self::assertNotNull($route, 'the dynamic route must survive reconstruction');

        // Before the fix this threw ValueError: array_combine() keys/values mismatch.
        self::assertSame(['path' => 'blocks.css'], $route->match('/assets/blocks.css'));

        $cache->clear();
    }
}
