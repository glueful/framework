<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Glueful\Routing\Router;
use Glueful\DI\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class RouterTest extends TestCase
{
    private Router $router;
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear route cache to ensure clean test state
        $cache = new \Glueful\Routing\RouteCache();
        $cache->clear();

        // Setup real container with Symfony ContainerBuilder
        $symfonyContainer = new ContainerBuilder();
        $this->container = new Container($symfonyContainer);
        $this->router = new Router($this->container);
    }

    /**
     * Test static route registration and matching
     */
    public function testStaticRouteMatching(): void
    {
        // Register a static route
        $this->router->get('/users', fn() => 'users list');

        // Create request
        $request = Request::create('/users', 'GET');

        // Match should succeed
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertArrayHasKey('route', $match);
        $this->assertArrayHasKey('params', $match);
        $this->assertEquals([], $match['params']);
    }

    /**
     * Test dynamic route with parameters
     */
    public function testDynamicRouteParameters(): void
    {
        // Register dynamic route
        $this->router->get('/users/{id}', fn($id) => "User $id")
            ->where('id', '\d+');

        $request = Request::create('/users/123', 'GET');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertEquals(['id' => '123'], $match['params']);
    }

    /**
     * Test route precedence - static should beat dynamic
     */
    public function testRoutePrecedenceStaticBeatsDynamic(): void
    {
        // Register in "wrong" order - dynamic first
        $this->router->get('/users/{id}', fn($id) => "User ID: $id");
        $this->router->get('/users/me', fn() => "Current user");

        // Static should still win
        $request = Request::create('/users/me', 'GET');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $route = $match['route'];
        $this->assertEquals('/users/me', $route->getPath());
    }

    /**
     * Test group middleware application
     */
    public function testGroupMiddleware(): void
    {

        $this->router->group(['middleware' => 'auth'], function ($router) {
            $router->get('/admin', fn() => 'admin panel');
            $router->get('/admin/users', fn() => 'admin users');
        });

        $request = Request::create('/admin', 'GET');
        $match = $this->router->match($request);
        $route = $match['route'];

        $this->assertContains('auth', $route->getMiddleware());
    }

    /**
     * Test nested groups with prefix
     */
    public function testNestedGroupsWithPrefix(): void
    {
        $this->router->group(['prefix' => '/api'], function ($router) {
            $router->group(['prefix' => '/v1'], function ($router) {
                $router->get('/users', fn() => 'api v1 users');
            });
        });

        $request = Request::create('/api/v1/users', 'GET');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertArrayHasKey('route', $match);
    }

    /**
     * Test 404 Not Found
     */
    public function testNotFoundRoute(): void
    {
        $this->router->get('/exists', fn() => 'exists');

        $request = Request::create('/does-not-exist', 'GET');
        $match = $this->router->match($request);

        $this->assertNull($match);
    }

    /**
     * Test 405 Method Not Allowed
     */
    public function testMethodNotAllowed(): void
    {
        $this->router->get('/users', fn() => 'get users');
        $this->router->post('/users', fn() => 'create user');

        $request = Request::create('/users', 'DELETE');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertNull($match['route']);
        $this->assertArrayHasKey('allowed_methods', $match);
        $this->assertContains('GET', $match['allowed_methods']);
        $this->assertContains('POST', $match['allowed_methods']);
    }

    /**
     * Test named routes and URL generation
     */
    public function testNamedRoutesAndUrlGeneration(): void
    {
        $this->router->get('/users/{id}', fn($id) => "User $id")
            ->where('id', '\d+')
            ->name('users.show');

        // Generate URL from named route
        $url = $this->router->url('users.show', ['id' => 123]);

        $this->assertEquals('/users/123', $url);

        // Test with query parameters
        $urlWithQuery = $this->router->url('users.show', ['id' => 456], ['filter' => 'active']);
        $this->assertEquals('/users/456?filter=active', $urlWithQuery);
    }

    /**
     * Test duplicate route detection
     */
    public function testDuplicateRouteDetection(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Route already defined');

        $this->router->get('/users', fn() => 'first');
        $this->router->get('/users', fn() => 'second'); // Should throw
    }

    /**
     * Test multiple dynamic parameters
     */
    public function testMultipleDynamicParameters(): void
    {
        $this->router->get(
            '/posts/{year}/{month}/{slug}',
            fn($year, $month, $slug) => "Post: $year/$month/$slug"
        )
            ->where(['year' => '\d{4}', 'month' => '\d{2}', 'slug' => '[a-z-]+']);

        $request = Request::create('/posts/2024/01/hello-world', 'GET');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertEquals([
            'year' => '2024',
            'month' => '01',
            'slug' => 'hello-world'
        ], $match['params']);
    }

    /**
     * Test HEAD request mapping to GET
     */
    public function testHeadRequestMapping(): void
    {
        $this->router->get('/resource', fn() => 'resource content');

        $request = Request::create('/resource', 'HEAD');
        $response = $this->router->dispatch($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('', $response->getContent()); // HEAD should have empty body
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test OPTIONS request for CORS
     */
    public function testOptionsRequestForCors(): void
    {
        $this->router->get('/api/users', fn() => 'users');
        $this->router->post('/api/users', fn() => 'create');

        $request = Request::create('/api/users', 'OPTIONS');
        $response = $this->router->dispatch($request);

        $this->assertEquals(204, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Allow'));
        $allowedMethods = $response->headers->get('Allow');
        $this->assertStringContainsString('GET', $allowedMethods);
        $this->assertStringContainsString('POST', $allowedMethods);
    }

    /**
     * Test controller array syntax
     */
    public function testControllerArraySyntax(): void
    {
        // Mock controller class
        $controllerClass = TestController::class;

        // Register the test controller in the container
        $this->container->set($controllerClass, new TestController());

        $this->router->get('/test', [$controllerClass, 'index']);

        $request = Request::create('/test', 'GET');
        $response = $this->router->dispatch($request);

        $this->assertEquals('index response', $response->getContent());
    }

    /**
     * Test response normalization
     */
    public function testResponseNormalization(): void
    {
        // String response
        $this->router->get('/string', fn() => 'plain text');

        // Array response (should become JSON)
        $this->router->get('/array', fn() => ['key' => 'value']);

        // Already a Response object
        $this->router->get('/response', fn() => new Response('response', 201));

        // Test string
        $response1 = $this->router->dispatch(Request::create('/string', 'GET'));
        $this->assertInstanceOf(Response::class, $response1);
        $this->assertEquals('plain text', $response1->getContent());

        // Test array -> JSON
        $response2 = $this->router->dispatch(Request::create('/array', 'GET'));
        $this->assertInstanceOf(JsonResponse::class, $response2);
        $this->assertEquals('{"key":"value"}', $response2->getContent());

        // Test Response passthrough
        $response3 = $this->router->dispatch(Request::create('/response', 'GET'));
        $this->assertEquals(201, $response3->getStatusCode());
    }

    /**
     * Test route parameter constraints validation
     */
    public function testRouteParameterConstraints(): void
    {
        $this->router->get('/items/{id}', fn($id) => "Item: $id")
            ->where('id', '\d+'); // Numbers only

        // Valid numeric ID
        $match1 = $this->router->match(Request::create('/items/123', 'GET'));
        $this->assertNotNull($match1);

        // Invalid non-numeric ID
        $match2 = $this->router->match(Request::create('/items/abc', 'GET'));
        $this->assertNull($match2);
    }

    /**
     * Test all HTTP methods
     */
    public function testAllHttpMethods(): void
    {
        $this->router->get('/resource', fn() => 'GET');
        $this->router->post('/resource', fn() => 'POST');
        $this->router->put('/resource', fn() => 'PUT');
        $this->router->delete('/resource', fn() => 'DELETE');

        $methods = ['GET', 'POST', 'PUT', 'DELETE'];

        foreach ($methods as $method) {
            $request = Request::create('/resource', $method);
            $response = $this->router->dispatch($request);
            $this->assertEquals($method, $response->getContent());
        }
    }

    /**
     * Test Request injection into controller
     */
    public function testRequestInjection(): void
    {
        $this->router->get('/request-test', function (Request $request) {
            return $request->getMethod();
        });

        $request = Request::create('/request-test', 'GET');
        $response = $this->router->dispatch($request);

        $this->assertEquals('GET', $response->getContent());
    }

    /**
     * Test parameter type casting
     */
    public function testParameterTypeCasting(): void
    {
        $this->router->get('/cast/{id}/{active}', function (int $id, bool $active) {
            return json_encode(['id' => $id, 'active' => $active, 'types' => [
                'id' => gettype($id),
                'active' => gettype($active)
            ]]);
        });

        $request = Request::create('/cast/123/true', 'GET');
        $response = $this->router->dispatch($request);

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(123, $data['id']);
        $this->assertTrue($data['active']);
        $this->assertEquals('integer', $data['types']['id']);
        $this->assertEquals('boolean', $data['types']['active']);
    }
}

/**
 * Test controller for array syntax testing
 */
class TestController // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
{
    public function index(): string
    {
        return 'index response';
    }
}
