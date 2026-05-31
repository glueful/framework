<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Pins the router's route-precedence model so refactors can't silently change
 * it. The model has three tiers (see Router::match()):
 *   1. Exact static routes win first.
 *   2. A literal-first-segment bucket is tried before the parameter ('*')
 *      bucket, so /users/{id} beats /{resource}/{id} — independent of order.
 *   3. WITHIN a bucket there is no specificity ranking: first registered that
 *      matches wins. Register the more specific overlapping pattern first.
 */
class RoutePrecedenceTest extends TestCase
{
    private function newRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/route_precedence_' . uniqid());
        (new RouteCache($context))->clear();

        $container = new class implements ContainerInterface {
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
                throw new class ("Service '" . $id . "' not found") extends \RuntimeException implements
                    \Psr\Container\NotFoundExceptionInterface {
                };
            }
            public function set(string $id, mixed $service): void
            {
                $this->services[$id] = $service;
            }
        };
        $container->set(ApplicationContext::class, $context);

        return new Router($container);
    }

    private function matchedPath(Router $router, string $path, string $method = 'GET'): ?string
    {
        $match = $router->match(Request::create($path, $method));
        if ($match === null || ($match['route'] ?? null) === null) {
            return null;
        }
        return $match['route']->getPath();
    }

    /**
     * Tier 1: a static route beats an overlapping dynamic route regardless of
     * the order they were registered in.
     */
    public function testStaticBeatsDynamicEitherRegistrationOrder(): void
    {
        // Dynamic registered first
        $a = $this->newRouter();
        $a->get('/users/{id}', fn($id) => "dynamic $id");
        $a->get('/users/me', fn() => 'static');
        $this->assertSame('/users/me', $this->matchedPath($a, '/users/me'));

        // Static registered first
        $b = $this->newRouter();
        $b->get('/users/me', fn() => 'static');
        $b->get('/users/{id}', fn($id) => "dynamic $id");
        $this->assertSame('/users/me', $this->matchedPath($b, '/users/me'));

        // The dynamic route still serves everything else
        $this->assertSame('/users/{id}', $this->matchedPath($b, '/users/42'));
    }

    /**
     * Tier 2: a route with a literal first segment is tried before one whose
     * first segment is a parameter ('*' bucket) — independent of order.
     */
    public function testLiteralFirstSegmentBeatsParameterBucketEitherOrder(): void
    {
        // Parameter-first-segment route registered first
        $a = $this->newRouter();
        $a->get('/{resource}/{id}', fn($resource, $id) => 'generic');
        $a->get('/users/{id}', fn($id) => 'users');
        $this->assertSame('/users/{id}', $this->matchedPath($a, '/users/5'));
        // A first segment with no literal bucket still falls through to '*'
        $this->assertSame('/{resource}/{id}', $this->matchedPath($a, '/posts/5'));

        // Literal-first-segment route registered first (order must not matter)
        $b = $this->newRouter();
        $b->get('/users/{id}', fn($id) => 'users');
        $b->get('/{resource}/{id}', fn($resource, $id) => 'generic');
        $this->assertSame('/users/{id}', $this->matchedPath($b, '/users/5'));
    }

    /**
     * Tier 3 (the footgun, pinned intentionally): two overlapping dynamic
     * patterns in the SAME bucket are resolved by registration order, NOT by
     * specificity. Whichever is registered first wins for the overlapping path.
     */
    public function testWithinBucketResolvesByRegistrationOrder(): void
    {
        // Specific registered first → specific wins (the recommended ordering)
        $a = $this->newRouter();
        $a->get('/posts/{id}/edit', fn($id) => 'edit');
        $a->get('/posts/{id}/{action}', fn($id, $action) => 'action');
        $this->assertSame('/posts/{id}/edit', $this->matchedPath($a, '/posts/5/edit'));
        $this->assertSame('/posts/{id}/{action}', $this->matchedPath($a, '/posts/5/delete'));

        // Generic registered first → generic shadows the specific route. This
        // documents that the router does NOT rank by specificity; it is a
        // deliberate assertion of current behavior, not desired behavior.
        $b = $this->newRouter();
        $b->get('/posts/{id}/{action}', fn($id, $action) => 'action');
        $b->get('/posts/{id}/edit', fn($id) => 'edit');
        $this->assertSame('/posts/{id}/{action}', $this->matchedPath($b, '/posts/5/edit'));
    }

    /**
     * Parameter constraints participate in matching: a constrained route that
     * fails its constraint falls through to the next candidate in the bucket.
     */
    public function testConstraintCausesFallthroughWithinBucket(): void
    {
        $router = $this->newRouter();
        $router->get('/users/{id}', fn($id) => 'numeric')->where('id', '\d+');
        $router->get('/users/{slug}', fn($slug) => 'slug');

        $this->assertSame('/users/{id}', $this->matchedPath($router, '/users/42'));
        $this->assertSame('/users/{slug}', $this->matchedPath($router, '/users/me'));
    }

    /**
     * Parameters match exactly one segment (default constraint [^/]+), so
     * routes of different segment counts never cross-match.
     */
    public function testParametersMatchSingleSegmentOnly(): void
    {
        $router = $this->newRouter();
        $router->get('/files/{name}', fn($name) => 'one');
        $router->get('/files/{dir}/{name}', fn($dir, $name) => 'two');

        $this->assertSame('/files/{name}', $this->matchedPath($router, '/files/report'));
        $this->assertSame('/files/{dir}/{name}', $this->matchedPath($router, '/files/2026/report'));
        // Three segments match neither
        $this->assertNull($this->matchedPath($router, '/files/2026/q2/report'));
    }

    /**
     * Trailing slashes are normalized before matching, for both static and
     * dynamic routes, and the root path resolves.
     */
    public function testTrailingSlashNormalization(): void
    {
        $router = $this->newRouter();
        $router->get('/', fn() => 'root');
        $router->get('/users', fn() => 'list');
        $router->get('/users/{id}', fn($id) => 'show');

        $this->assertSame('/', $this->matchedPath($router, '/'));
        $this->assertSame('/users', $this->matchedPath($router, '/users/'));
        $this->assertSame('/users/{id}', $this->matchedPath($router, '/users/5/'));
    }

    /**
     * Precedence never crosses HTTP methods: a path registered only for GET
     * yields a 405 (route null + allowed_methods) for POST, not a match.
     */
    public function testPrecedenceDoesNotCrossMethods(): void
    {
        $router = $this->newRouter();
        $router->get('/users/{id}', fn($id) => 'get');

        $getMatch = $router->match(Request::create('/users/5', 'GET'));
        $this->assertNotNull($getMatch);
        $this->assertNotNull($getMatch['route']);

        $postMatch = $router->match(Request::create('/users/5', 'POST'));
        $this->assertNotNull($postMatch);
        $this->assertNull($postMatch['route']);
        $this->assertArrayHasKey('allowed_methods', $postMatch);
        $this->assertContains('GET', $postMatch['allowed_methods']);
    }
}
