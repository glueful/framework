<?php

declare(strict_types=1);

namespace Glueful\Tests\Core;

use PHPUnit\Framework\TestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use Glueful\Tests\Fixtures\RouteCacheTestController;
use Glueful\Tests\Fixtures\TestContainer;
use Symfony\Component\HttpFoundation\Request;

final class RouteCacheTest extends TestCase
{
    /**
     * Regression test: Dynamic routes must work after cache load.
     *
     * Before the fix, Router::match() used $routeBuckets for dynamic routes,
     * but buckets were only populated in add(). When cached routes were loaded,
     * $dynamicRoutes was rebuilt but $routeBuckets was not, causing 404s for
     * all dynamic routes in production (when cache is used).
     */
    public function testDynamicRoutesWorkAfterCacheLoad(): void
    {
        $testDir = sys_get_temp_dir() . '/route_cache_test_' . uniqid();
        mkdir($testDir, 0755, true);
        mkdir($testDir . '/storage/cache', 0755, true);

        // Create test container with controller
        $container = new TestContainer();
        $context = new ApplicationContext($testDir);
        $container->set(ApplicationContext::class, $context);
        $container->set(RouteCacheTestController::class, new RouteCacheTestController());

        // Clear any existing cache
        $cache = new RouteCache($context);
        $cache->clear();

        // Step 1: Create router and register dynamic routes (using controller, not closures)
        $router1 = new Router($container);
        $router1->get('/users/{id}', [RouteCacheTestController::class, 'show'])
            ->where('id', '\d+');
        $router1->get('/posts/{slug}', [RouteCacheTestController::class, 'showPost']);
        $router1->get('/api/{version}/resource/{id}', [RouteCacheTestController::class, 'resource']);

        // Verify routes work before caching
        $match1 = $router1->match(Request::create('/users/123', 'GET'));
        $this->assertNotNull($match1, 'Dynamic route should match before caching');
        $this->assertEquals(['id' => '123'], $match1['params']);

        // Step 2: Save to cache
        $this->assertTrue($cache->save($router1));

        // Step 3: Create NEW router instance (simulates fresh request in production)
        $router2 = new Router($container);

        // Step 4: Verify dynamic routes work after cache load
        $match2 = $router2->match(Request::create('/users/456', 'GET'));
        $this->assertNotNull($match2, 'Dynamic route /users/{id} should match after cache load');
        $this->assertEquals(['id' => '456'], $match2['params']);

        $match3 = $router2->match(Request::create('/posts/hello-world', 'GET'));
        $this->assertNotNull($match3, 'Dynamic route /posts/{slug} should match after cache load');
        $this->assertEquals(['slug' => 'hello-world'], $match3['params']);

        $match4 = $router2->match(Request::create('/api/v2/resource/999', 'GET'));
        $this->assertNotNull($match4, 'Multi-param dynamic route should match after cache load');
        $this->assertEquals(['version' => 'v2', 'id' => '999'], $match4['params']);

        // Step 5: Verify dispatch also works (full roundtrip)
        $response = $router2->dispatch(Request::create('/users/789', 'GET'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertJson($response->getContent());

        // Cleanup
        $cache->clear();
        @rmdir($testDir . '/storage/cache');
        @rmdir($testDir . '/storage');
        @rmdir($testDir);
    }

    /**
     * Test that route buckets are correctly rebuilt for wildcard routes.
     * Routes without a static first segment go into the '*' bucket.
     */
    public function testWildcardBucketRoutesWorkAfterCacheLoad(): void
    {
        $testDir = sys_get_temp_dir() . '/route_cache_wildcard_' . uniqid();
        mkdir($testDir, 0755, true);
        mkdir($testDir . '/storage/cache', 0755, true);

        $container = new TestContainer();
        $context = new ApplicationContext($testDir);
        $container->set(ApplicationContext::class, $context);
        $container->set(RouteCacheTestController::class, new RouteCacheTestController());

        $cache = new RouteCache($context);
        $cache->clear();

        // Register route where first segment is dynamic (goes to '*' bucket)
        $router1 = new Router($container);
        $router1->get('/{locale}/page/{id}', [RouteCacheTestController::class, 'localized']);

        $cache->save($router1);

        // New router loads from cache
        $router2 = new Router($container);

        $match = $router2->match(Request::create('/en/page/42', 'GET'));
        $this->assertNotNull($match, 'Wildcard bucket route should match after cache load');
        $this->assertEquals(['locale' => 'en', 'id' => '42'], $match['params']);

        // Cleanup
        $cache->clear();
        @rmdir($testDir . '/storage/cache');
        @rmdir($testDir . '/storage');
        @rmdir($testDir);
    }

    /**
     * Test static routes still work after cache load (baseline).
     */
    public function testStaticRoutesWorkAfterCacheLoad(): void
    {
        $testDir = sys_get_temp_dir() . '/route_cache_static_' . uniqid();
        mkdir($testDir, 0755, true);
        mkdir($testDir . '/storage/cache', 0755, true);

        $container = new TestContainer();
        $context = new ApplicationContext($testDir);
        $container->set(ApplicationContext::class, $context);
        $container->set(RouteCacheTestController::class, new RouteCacheTestController());

        $cache = new RouteCache($context);
        $cache->clear();

        $router1 = new Router($container);
        $router1->get('/health', [RouteCacheTestController::class, 'index']);

        $cache->save($router1);

        $router2 = new Router($container);

        $match = $router2->match(Request::create('/health', 'GET'));
        $this->assertNotNull($match, 'Static route should match after cache load');

        $response = $router2->dispatch(Request::create('/health', 'GET'));
        $this->assertSame(200, $response->getStatusCode());

        // Cleanup
        $cache->clear();
        @rmdir($testDir . '/storage/cache');
        @rmdir($testDir . '/storage');
        @rmdir($testDir);
    }

    public function testCacheInvalidatesWhenRouteSourcesChange(): void
    {
        $testDir = sys_get_temp_dir() . '/route_cache_sig_' . uniqid();
        mkdir($testDir, 0755, true);
        mkdir($testDir . '/storage/cache', 0755, true);
        mkdir($testDir . '/routes', 0755, true);

        $routeFile = $testDir . '/routes/api.php';
        file_put_contents($routeFile, "<?php\n// initial routes\n");

        $container = new TestContainer();
        $context = new ApplicationContext($testDir);
        $container->set(ApplicationContext::class, $context);
        $container->set(RouteCacheTestController::class, new RouteCacheTestController());

        $cache = new RouteCache($context);
        $cache->clear();

        $router = new Router($container);
        $router->get('/users/{id}', [RouteCacheTestController::class, 'show']);

        $sig1 = $cache->getSignature();
        $this->assertTrue($cache->save($router));
        $this->assertNotNull($cache->load(), 'Cache should load when signature matches');

        // Change a route source file to invalidate signature
        file_put_contents($routeFile, "\n// changed\n", FILE_APPEND);
        touch($routeFile, time() + 2);

        $sig2 = $cache->getSignature();
        $this->assertNotSame($sig1, $sig2, 'Signature should change when route sources change');
        $this->assertNull($cache->load(), 'Cache should be invalidated when signature changes');

        // Cleanup
        $cache->clear();
        @unlink($routeFile);
        @rmdir($testDir . '/routes');
        @rmdir($testDir . '/storage/cache');
        @rmdir($testDir . '/storage');
        @rmdir($testDir);
    }
}
