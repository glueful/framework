<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Extensions;

use Glueful\Extensions\ServiceProvider;
use Glueful\Routing\FrontendMountRegistry;
use Glueful\Routing\Route;
use Glueful\Routing\Router;
use Glueful\Routing\SpaMountController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * serveFrontend() REGISTRATION behaviour: path validation, no-op guards, and the
 * controller-array handlers it registers (never closures — see the "no closures"
 * assertion below and the RouteCache regression in ServeFrontendDispatchTest).
 * The asset/index SERVING behaviour now lives in {@see SpaMountController} and is
 * covered by SpaMountControllerTest.
 */
class ServeFrontendTest extends TestCase
{
    private string $dir;
    private ContainerInterface&MockObject $container;
    private FrontendMountRegistry $registry;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/serve_frontend_' . uniqid();
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/index.html', '<!doctype html><title>App</title>');

        $this->registry = new FrontendMountRegistry();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('has')->willReturnCallback(
            static fn (string $id): bool => $id === Router::class,
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dir);
        }
    }

    /**
     * Mount and capture the two registered route handlers.
     *
     * @param  array<string, mixed> $options
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function mount(string $path, string $dir, array $options = []): array
    {
        $routes = [];
        $calls = 0;
        $router = $this->createMock(Router::class);
        $router->method('get')->willReturnCallback(function ($p, $handler) use (&$routes, &$calls) {
            $routes[$p] = $handler;
            $calls++;
            $route = $this->createMock(Route::class);
            $route->method('where')->willReturnSelf();
            return $route;
        });
        $this->container->method('get')->willReturnCallback(
            fn (string $id): mixed => match ($id) {
                Router::class => $router,
                FrontendMountRegistry::class => $this->registry,
                default => throw new \RuntimeException("unexpected get($id)"),
            },
        );

        $provider = new class ($this->container) extends ServiceProvider {
            /** @param array<string, mixed> $options */
            public function expose(string $path, string $dir, array $options): void
            {
                $this->serveFrontend($path, $dir, $options);
            }
        };
        $provider->expose($path, $dir, $options);

        return [$routes, $calls];
    }

    public function testInvalidMountPathThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mount('admin', $this->dir); // no leading slash
    }

    public function testTrailingSlashMountArgumentThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mount('/admin/', $this->dir);
    }

    public function testMissingDirIsNoOp(): void
    {
        [, $calls] = $this->mount('/admin', $this->dir . '/does-not-exist');
        self::assertSame(0, $calls);
        self::assertSame([], $this->registry->all());
    }

    public function testMissingIndexWithSpaFallbackIsNoOp(): void
    {
        unlink($this->dir . '/index.html');
        [, $calls] = $this->mount('/admin', $this->dir);
        self::assertSame(0, $calls, 'No index.html + spaFallback => no routes registered');
        self::assertSame([], $this->registry->all());
    }

    public function testValidMountRegistersTwoControllerRoutes(): void
    {
        [$routes, $calls] = $this->mount('/admin', $this->dir);
        self::assertSame(2, $calls);
        self::assertArrayHasKey('/admin', $routes);
        self::assertArrayHasKey('/admin/{rest}', $routes);
    }

    public function testHandlersAreControllerArraysNotClosures(): void
    {
        // The whole point of the seam: no closures reach the router, so RouteCache
        // can serialize the table. Handlers must be [SpaMountController::class, ...].
        [$routes] = $this->mount('/admin', $this->dir);
        self::assertSame([SpaMountController::class, 'root'], $routes['/admin']);
        self::assertSame([SpaMountController::class, 'asset'], $routes['/admin/{rest}']);
        self::assertNotInstanceOf(\Closure::class, $routes['/admin']);
        self::assertNotInstanceOf(\Closure::class, $routes['/admin/{rest}']);
    }

    public function testMountIsRecordedInRegistry(): void
    {
        $this->mount('/admin', $this->dir);
        $mount = $this->registry->match('/admin/posts/123');
        self::assertNotNull($mount);
        self::assertSame('/admin', $mount['prefix']);
        self::assertSame(realpath($this->dir), $mount['dir']);
        self::assertTrue($mount['spaFallback']);
    }
}
