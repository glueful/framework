<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Glueful\Routing\Router;
use Glueful\Routing\AttributeRouteLoader;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class RouterIntegrationTest extends TestCase
{
    private Router $router;
    private ContainerInterface $container;
    private string $tempCacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Minimal PSR-11 test container
        $this->container = new class implements ContainerInterface {
            /** @var array<string,mixed> */
            private array $services = [];
            public function has(string $id): bool { return array_key_exists($id, $this->services); }
            public function get(string $id): mixed {
                if ($this->has($id)) { return $this->services[$id]; }
                if (class_exists($id)) { return $this->services[$id] = new $id(); }
                throw new class("Service '".$id."' not found") extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
            }
            public function set(string $id, mixed $service): void { $this->services[$id] = $service; }
        };

        // Setup temp cache directory
        $this->tempCacheDir = sys_get_temp_dir() . '/router_test_' . uniqid();
        mkdir($this->tempCacheDir, 0755, true);

        // Clear route cache to ensure clean state for each test
        $cache = new \Glueful\Routing\RouteCache();
        $cache->clear();

        // Create router - cache will be empty since we cleared it
        $this->router = new Router($this->container);
    }

    protected function tearDown(): void
    {
        // Clean up temp cache directory
        if (is_dir($this->tempCacheDir)) {
            $this->removeDirectory($this->tempCacheDir);
        }

        parent::tearDown();
    }

    /**
     * Test complete router workflow without caching
     */
    public function testCompleteRouterWorkflow(): void
    {
        // Setup routes
        $this->setupTestRoutes();

        // Test route matching works
        $this->assertRouteMatching();
    }

    /**
     * Test router performance with many routes
     */
    public function testRouterPerformanceWithManyRoutes(): void
    {
        $startTime = microtime(true);

        // Register 1000 static routes
        for ($i = 0; $i < 1000; $i++) {
            $this->router->get("/route-$i", fn() => "Response $i");
        }

        $registrationTime = microtime(true) - $startTime;

        // Test matching performance
        $matchStart = microtime(true);

        // Test 100 random route matches
        for ($i = 0; $i < 100; $i++) {
            $routeNum = rand(0, 999);
            $request = Request::create("/route-$routeNum", 'GET');
            $match = $this->router->match($request);
            $this->assertNotNull($match, "Failed to match route-$routeNum");
        }

        $matchingTime = microtime(true) - $matchStart;

        // Performance assertions
        $this->assertLessThan(1.0, $registrationTime, 'Route registration took too long');
        $this->assertLessThan(0.1, $matchingTime, 'Route matching took too long');
    }

    /**
     * Test attribute-based routing integration
     */
    public function testAttributeBasedRoutingIntegration(): void
    {
        $loader = new AttributeRouteLoader($this->router);
        $loader->processClass(IntegrationTestController::class);

        // Test basic attribute route
        $request = Request::create('/integration/test', 'GET');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertEquals([IntegrationTestController::class, 'test'], $match['route']->getHandler());

        // Test parameterized route
        $request2 = Request::create('/integration/items/123', 'GET');
        $match2 = $this->router->match($request2);

        $this->assertNotNull($match2);
        $this->assertEquals(['id' => '123'], $match2['params']);
    }

    /**
     * Test error handling and edge cases
     */
    public function testErrorHandlingAndEdgeCases(): void
    {
        // Test 404
        $request = Request::create('/non-existent', 'GET');
        $response = $this->router->dispatch($request);
        $this->assertEquals(404, $response->getStatusCode());

        // Test 405
        $this->router->get('/method-test', fn() => 'get');
        $request2 = Request::create('/method-test', 'POST');
        $response2 = $this->router->dispatch($request2);
        $this->assertEquals(405, $response2->getStatusCode());
        $this->assertTrue($response2->headers->has('Allow'));
    }

    private function setupTestRoutes(): void
    {
        // Static routes
        $this->router->get('/', fn() => 'home');
        $this->router->get('/about', fn() => 'about');
        $this->router->post('/contact', fn() => 'contact form');

        // Dynamic routes
        $this->router->get('/users/{id}', fn($id) => "User $id")
            ->where('id', '\d+');
        $this->router->get('/posts/{slug}', fn($slug) => "Post $slug")
            ->where('slug', '[a-z0-9-]+');

        // Grouped routes
        $this->router->group(['prefix' => '/api', 'middleware' => 'api'], function ($router) {
            $router->get('/users', fn() => 'api users');
            $router->post('/users', fn() => 'create user');
        });
    }

    private function assertRouteMatching(): void
    {
        // Test static routes
        $this->assertRouteMatches('/', 'GET', []);
        $this->assertRouteMatches('/about', 'GET', []);
        $this->assertRouteMatches('/contact', 'POST', []);

        // Test dynamic routes
        $this->assertRouteMatches('/users/123', 'GET', ['id' => '123']);
        $this->assertRouteMatches('/posts/hello-world', 'GET', ['slug' => 'hello-world']);

        // Test grouped routes
        $this->assertRouteMatches('/api/users', 'GET', []);
        $this->assertRouteMatches('/api/users', 'POST', []);
    }

    private function assertRouteMatches(string $path, string $method, array $expectedParams): void
    {
        $request = Request::create($path, $method);
        $match = $this->router->match($request);

        $this->assertNotNull($match, "Route $method $path should match");
        $this->assertEquals($expectedParams, $match['params'], "Parameters don't match for $method $path");
    }


    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}

/**
 * Test controller for integration testing
 */
#[\Glueful\Routing\Attributes\Controller(prefix: '/integration')]
class IntegrationTestController // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
{
    #[\Glueful\Routing\Attributes\Get('/test')]
    public function test(): string
    {
        return 'integration test';
    }

    #[\Glueful\Routing\Attributes\Get('/items/{id}', where: ['id' => '\d+'])]
    public function item(int $id): string
    {
        return "Item $id";
    }
}
