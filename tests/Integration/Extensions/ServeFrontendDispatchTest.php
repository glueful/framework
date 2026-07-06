<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Glueful\Routing\FrontendMountRegistry;
use Glueful\Routing\RouteCache;
use Glueful\Routing\RouteCompiler;
use Glueful\Routing\Router;
use Glueful\Routing\SpaMountController;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ServeFrontendDispatchTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/serve_frontend_dispatch_' . uniqid();
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/index.html', '<!doctype html><title>App</title>');
        file_put_contents($this->dir . '/style.css', 'body{}');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/index.html');
        @unlink($this->dir . '/style.css');
        @rmdir($this->dir);
    }

    /** Build a REAL Router with the bundle mounted at /admin. */
    private function mountedRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/sfd_ctx_' . uniqid());
        (new RouteCache($context))->clear();

        $container = new class implements ContainerInterface {
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
                throw new class ("Service '$id' not found")
                    extends \RuntimeException
                    implements \Psr\Container\NotFoundExceptionInterface {
                };
            }
        };
        $container->services[ApplicationContext::class] = $context;
        $router = new Router($container);
        $container->services[Router::class] = $router;

        // serveFrontend populates the registry (boot) and SpaMountController reads it
        // (dispatch); both resolve from the container as shared singletons in production.
        $registry = new FrontendMountRegistry();
        $container->services[FrontendMountRegistry::class] = $registry;
        $container->services[SpaMountController::class] = new SpaMountController($registry);

        $provider = new class ($container) extends ServiceProvider {
            public function expose(string $path, string $dir): void
            {
                $this->serveFrontend($path, $dir);
            }
        };
        $provider->expose('/admin', $this->dir);

        return $router;
    }

    public function testHeadOnIndexDoesNotThrowAndHasEmptyBody(): void
    {
        $router = $this->mountedRouter();
        $resp = $router->dispatch(Request::create('/admin', 'HEAD'));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('', (string) $resp->getContent());
        self::assertNotEmpty($resp->headers->get('Content-Type'));
    }

    public function testHeadOnAssetDoesNotThrow(): void
    {
        $router = $this->mountedRouter();
        $resp = $router->dispatch(Request::create('/admin/style.css', 'HEAD'));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('', (string) $resp->getContent());
    }

    public function testRequestTrailingSlashServesIndex(): void
    {
        $router = $this->mountedRouter();
        // Router rtrims the request path before matching, so /admin/ hits the root route.
        $resp = $router->dispatch(Request::create('/admin/', 'GET'));

        self::assertSame(200, $resp->getStatusCode());
        // Index is a BinaryFileResponse (getContent() === false); assert via Content-Type.
        self::assertStringContainsString('text/html', (string) $resp->headers->get('Content-Type'));
    }

    public function testStaticConfigRouteIsNotShadowedBySpaCatchAll(): void
    {
        $router = $this->mountedRouter();
        // A real static sibling route under the mount prefix must win over /admin/{rest}.
        $router->get('/admin/config.json', static fn (): Response => new Response('CONFIG', 200));

        $resp = $router->dispatch(Request::create('/admin/config.json', 'GET'));
        self::assertSame('CONFIG', (string) $resp->getContent());
    }

    /**
     * Regression: mounting an SPA via serveFrontend() must NOT introduce closure
     * handlers, because a single closure makes RouteCache reject the ENTIRE route
     * table (disabling route caching app-wide and logging a warning).
     *
     * `RouteCompiler::hasClosures()` is the exact predicate `RouteCache::save()`
     * checks to decide rejection (save() returns false when it is true), so
     * asserting it directly proves the table stays cacheable — without depending on
     * save()'s environment-specific OPcache warm step.
     */
    public function testMountedRouteTableHasNoClosures(): void
    {
        $router = $this->mountedRouter();

        $compiler = new RouteCompiler();
        $issues = $compiler->validateHandlers($router);
        self::assertFalse(
            $compiler->hasClosures($issues),
            'serveFrontend() must register controller handlers, not closures: '
                . implode(', ', $compiler->getClosureRoutes($issues)),
        );
    }
}
