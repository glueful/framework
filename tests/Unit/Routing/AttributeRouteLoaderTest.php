<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Router;
use Glueful\Routing\AttributeRouteLoader;
use Glueful\Routing\Attributes\{Controller, Get, Post, Put, Patch, Delete, Options, Middleware, Route};
use Glueful\Auth\Attributes\{RequiresPermission, RequiresRole};
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class AttributeRouteLoaderTest extends TestCase
{
    private Router $router;
    private AttributeRouteLoader $loader;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();
        // Minimal PSR-11 test container
        $this->container = new class implements ContainerInterface {
            /** @var array<string,mixed> */
            private array $services = [];
            public function has(string $id): bool { return array_key_exists($id, $this->services); }
            public function get(string $id): mixed {
                if (array_key_exists($id, $this->services)) { return $this->services[$id]; }
                throw new class("Service '".$id."' not found") extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
            }
            public function set(string $id, mixed $service): void { $this->services[$id] = $service; }
        };
        // Register ApplicationContext for Router
        $this->container->set(ApplicationContext::class, new ApplicationContext(__DIR__));
        $this->router = new Router($this->container);
        $this->loader = new AttributeRouteLoader($this->router);
    }

    /**
     * Test processing a controller with basic attributes
     */
    public function testBasicControllerProcessing(): void
    {
        $this->loader->processClass(TestApiController::class);

        // Test basic GET route
        $request = Request::create('/api/test/users', 'GET');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertArrayHasKey('route', $match);

        $route = $match['route'];
        $this->assertEquals('/api/test/users', $route->getPath());
        $this->assertEquals([TestApiController::class, 'index'], $route->getHandler());
    }

    /**
     * Test controller with middleware
     */
    public function testControllerWithMiddleware(): void
    {
        $this->loader->processClass(TestApiController::class);

        $request = Request::create('/api/test/users', 'GET');
        $match = $this->router->match($request);
        $route = $match['route'];

        // Should have api and throttle middleware from class level
        $middleware = $route->getMiddleware();
        $this->assertContains('api', $middleware);
        $this->assertContains('throttle:60,1', $middleware);
    }

    /**
     * A method-level #[RequiresPermission] must auto-attach the gate_permissions
     * middleware so the route is actually enforced. Without the attach the
     * attribute is decorative and the route fails open.
     */
    public function testRequiresPermissionAutoAttachesGateMiddleware(): void
    {
        $this->loader->processClass(GateAttrController::class);

        $route = $this->router->match(Request::create('/secured/with-permission', 'GET'))['route'];

        $this->assertContains('gate_permissions', $route->getMiddleware());
    }

    /**
     * A method-level #[RequiresRole] must likewise auto-attach gate_permissions.
     */
    public function testRequiresRoleAutoAttachesGateMiddleware(): void
    {
        $this->loader->processClass(GateAttrController::class);

        $route = $this->router->match(Request::create('/secured/with-role', 'GET'))['route'];

        $this->assertContains('gate_permissions', $route->getMiddleware());
    }

    /**
     * A route with neither attribute must NOT carry gate_permissions (no needless
     * permission check on unguarded routes).
     */
    public function testPlainRouteHasNoGateMiddleware(): void
    {
        $this->loader->processClass(GateAttrController::class);

        $route = $this->router->match(Request::create('/secured/plain', 'GET'))['route'];

        $this->assertNotContains('gate_permissions', $route->getMiddleware());
    }

    /**
     * A class-level #[RequiresPermission] must attach gate_permissions to every
     * route in the class — the GateAttributeMiddleware reflects class-level
     * attributes, so the auto-attach detection must too (the scope handler,
     * which only inspects methods, would miss this).
     */
    public function testClassLevelRequiresPermissionAttachesGateToAllRoutes(): void
    {
        $this->loader->processClass(ClassGateAttrController::class);

        $route = $this->router->match(Request::create('/class-secured/inherits', 'GET'))['route'];

        $this->assertContains('gate_permissions', $route->getMiddleware());
    }

    /**
     * Test route with parameters and constraints
     */
    public function testRouteWithParametersAndConstraints(): void
    {
        $this->loader->processClass(TestApiController::class);

        $request = Request::create('/api/test/users/123', 'GET');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertEquals(['id' => '123'], $match['params']);

        // Test invalid ID (should not match due to \d+ constraint)
        $request2 = Request::create('/api/test/users/abc', 'GET');
        $match2 = $this->router->match($request2);
        $this->assertNull($match2);
    }

    /**
     * Test different HTTP method attributes
     */
    public function testDifferentHttpMethods(): void
    {
        $this->loader->processClass(TestApiController::class);

        // Test GET
        $getRequest = Request::create('/api/test/users', 'GET');
        $getMatch = $this->router->match($getRequest);
        $this->assertNotNull($getMatch);
        $this->assertEquals([TestApiController::class, 'index'], $getMatch['route']->getHandler());

        // Test POST
        $postRequest = Request::create('/api/test/users', 'POST');
        $postMatch = $this->router->match($postRequest);
        $this->assertNotNull($postMatch);
        $this->assertEquals([TestApiController::class, 'store'], $postMatch['route']->getHandler());

        // Test PUT
        $putRequest = Request::create('/api/test/users/123', 'PUT');
        $putMatch = $this->router->match($putRequest);
        $this->assertNotNull($putMatch);
        $this->assertEquals([TestApiController::class, 'update'], $putMatch['route']->getHandler());

        // Test PATCH
        $patchRequest = Request::create('/api/test/users/123', 'PATCH');
        $patchMatch = $this->router->match($patchRequest);
        $this->assertNotNull($patchMatch);
        $this->assertEquals([TestApiController::class, 'patchUser'], $patchMatch['route']->getHandler());

        // Test DELETE
        $deleteRequest = Request::create('/api/test/users/123', 'DELETE');
        $deleteMatch = $this->router->match($deleteRequest);
        $this->assertNotNull($deleteMatch);
        $this->assertEquals([TestApiController::class, 'destroy'], $deleteMatch['route']->getHandler());

        // Test OPTIONS (explicit route registered via #[Options])
        $optionsRequest = Request::create('/api/test/users', 'OPTIONS');
        $optionsMatch = $this->router->match($optionsRequest);
        $this->assertNotNull($optionsMatch);
        $this->assertEquals([TestApiController::class, 'preflight'], $optionsMatch['route']->getHandler());
    }

    /**
     * The #[Route(methods: [...])] form must accept PATCH/OPTIONS/HEAD, not just
     * the original GET/POST/PUT/DELETE (which previously threw).
     */
    public function testRouteAttributeAcceptsExtendedMethods(): void
    {
        $this->loader->processClass(TestVerbController::class);

        $patchMatch = $this->router->match(Request::create('/verbs/item/5', 'PATCH'));
        $this->assertNotNull($patchMatch);
        $this->assertEquals([TestVerbController::class, 'patch'], $patchMatch['route']->getHandler());

        $optionsMatch = $this->router->match(Request::create('/verbs/item', 'OPTIONS'));
        $this->assertNotNull($optionsMatch);
        $this->assertEquals([TestVerbController::class, 'meta'], $optionsMatch['route']->getHandler());

        $headMatch = $this->router->match(Request::create('/verbs/item', 'HEAD'));
        $this->assertNotNull($headMatch);
        $this->assertEquals([TestVerbController::class, 'meta'], $headMatch['route']->getHandler());
    }

    /**
     * Test method-specific middleware
     */
    public function testMethodSpecificMiddleware(): void
    {
        $this->loader->processClass(TestApiController::class);

        // POST should have additional auth middleware
        $request = Request::create('/api/test/users', 'POST');
        $match = $this->router->match($request);
        $middleware = $match['route']->getMiddleware();

        $this->assertContains('api', $middleware);
        $this->assertContains('throttle:60,1', $middleware);
        $this->assertContains('auth:admin', $middleware);
    }

    /**
     * Test named routes from attributes
     */
    public function testNamedRoutes(): void
    {
        $this->loader->processClass(TestApiController::class);

        // Test URL generation from named route
        $url = $this->router->url('users.index');
        $this->assertEquals('/api/test/users', $url);

        $url2 = $this->router->url('users.show', ['id' => 456]);
        $this->assertEquals('/api/test/users/456', $url2);
    }

    /**
     * Test complex controller with nested prefixes
     */
    public function testNestedPrefixController(): void
    {
        $this->loader->processClass(TestNestedController::class);

        $request = Request::create('/api/v1/admin/dashboard', 'GET');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertEquals('/api/v1/admin/dashboard', $match['route']->getPath());
    }

    /**
     * Test controller discovery via directory scanning
     */
    public function testDirectoryScanning(): void
    {
        // Create temporary directory with test controllers
        $tempDir = sys_get_temp_dir() . '/routing_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        // Create test controller file
        $controllerContent = '<?php
namespace Test;
use Glueful\Routing\Attributes\{Controller, Get};

#[Controller(prefix: "/test")]
class ScanController {
    #[Get("/scan")]
    public function scan() {
        return "scanned";
    }
}';

        file_put_contents($tempDir . '/ScanController.php', $controllerContent);

        // This would require a more complex test setup to actually load the class
        // For now, we verify the method exists and would work
        $this->assertTrue(method_exists($this->loader, 'scanDirectory'));

        // Cleanup
        unlink($tempDir . '/ScanController.php');
        rmdir($tempDir);
    }

    /**
     * Test error handling for invalid controllers
     */
    public function testInvalidControllerHandling(): void
    {
        // Processing non-existent class should not throw
        $this->loader->processClass('NonExistentController');

        // Should handle it gracefully (no exception)
        $this->assertTrue(true);
    }
}

/**
 * Test controller for attribute testing
 */
#[Controller(prefix: '/api/test')]
#[Middleware(['api', 'throttle:60,1'])]
class TestApiController
{
    #[Get('/users', name: 'users.index')]
    public function index(): JsonResponse
    {
        return new JsonResponse(['users' => []]);
    }

    #[Get('/users/{id}', name: 'users.show', where: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        return new JsonResponse(['user' => $id]);
    }

    #[Post('/users', name: 'users.store')]
    #[Middleware('auth:admin')]
    public function store(Request $request): JsonResponse
    {
        return new JsonResponse(['created' => true], 201);
    }

    #[Put('/users/{id}', name: 'users.update', where: ['id' => '\d+'])]
    #[Middleware('auth:admin')]
    public function update(Request $request, int $id): JsonResponse
    {
        return new JsonResponse(['updated' => $id]);
    }

    #[Patch('/users/{id}', name: 'users.patch', where: ['id' => '\d+'])]
    #[Middleware('auth:admin')]
    public function patchUser(int $id): JsonResponse
    {
        return new JsonResponse(['patched' => $id]);
    }

    #[Delete('/users/{id}', name: 'users.destroy', where: ['id' => '\d+'])]
    #[Middleware('auth:admin')]
    public function destroy(int $id): JsonResponse
    {
        return new JsonResponse(['deleted' => $id]);
    }

    #[Options('/users')]
    public function preflight(): JsonResponse
    {
        return new JsonResponse(['options' => true]);
    }
}

/**
 * Test controller exercising the #[Route(methods: [...])] array form with the
 * extended verb set (PATCH/OPTIONS/HEAD).
 */
#[Controller(prefix: '/verbs')]
class TestVerbController
{
    #[Route('/item/{id}', methods: ['PATCH'], where: ['id' => '\d+'])]
    public function patch(int $id): JsonResponse
    {
        return new JsonResponse(['patched' => $id]);
    }

    #[Route('/item', methods: ['OPTIONS', 'HEAD'])]
    public function meta(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}

/**
 * Test controller for nested prefix testing
 */
#[Route('/api/v1')]
class TestNestedController
{
    #[Get('/admin/dashboard')]
    public function dashboard(): string
    {
        return 'admin dashboard';
    }
}

/**
 * Exercises method-level #[RequiresPermission]/#[RequiresRole] auto-attach.
 */
#[Controller(prefix: '/secured')]
class GateAttrController
{
    #[Get('/with-permission')]
    #[RequiresPermission('posts.read')]
    public function withPermission(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    #[Get('/with-role')]
    #[RequiresRole('admin')]
    public function withRole(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    #[Get('/plain')]
    public function plain(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}

/**
 * Exercises class-level #[RequiresPermission] propagating to all routes.
 */
#[Controller(prefix: '/class-secured')]
#[RequiresPermission('admin.access')]
class ClassGateAttrController
{
    #[Get('/inherits')]
    public function inherits(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
