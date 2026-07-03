<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Extensions;

use Glueful\Extensions\ServiceProvider;
use Glueful\Routing\Route;
use Glueful\Routing\Router;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ServeFrontendTest extends TestCase
{
    private string $dir;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/serve_frontend_' . uniqid();
        mkdir($this->dir . '/assets', 0755, true);
        file_put_contents($this->dir . '/index.html', '<!doctype html><title>App</title>');
        file_put_contents($this->dir . '/favicon.ico', 'ico');
        file_put_contents($this->dir . '/assets/app-C5kJ8nQ2.js', 'console.log(1)');
        file_put_contents($this->dir . '/style.css', 'body{}');
        file_put_contents($this->dir . '/.env', 'SECRET=1');
        file_put_contents($this->dir . '/config.php', '<?php');

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
     * @return array{0: array<string, callable>, 1: int}
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
        $this->container->method('get')->with(Router::class)->willReturn($router);

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
        [$routes] = $this->mount('admin', $this->dir); // no leading slash
    }

    public function testTrailingSlashMountArgumentThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        [$routes] = $this->mount('/admin/', $this->dir);
    }

    public function testMissingDirIsNoOp(): void
    {
        [$routes, $calls] = $this->mount('/admin', $this->dir . '/does-not-exist');
        self::assertSame(0, $calls);
    }

    public function testMissingIndexWithSpaFallbackIsNoOp(): void
    {
        unlink($this->dir . '/index.html');
        [$routes, $calls] = $this->mount('/admin', $this->dir);
        self::assertSame(0, $calls, 'No index.html + spaFallback => no routes registered');
    }

    public function testValidMountRegistersTwoRoutes(): void
    {
        [$routes, $calls] = $this->mount('/admin', $this->dir);
        self::assertSame(2, $calls);
        self::assertArrayHasKey('/admin', $routes);
        self::assertArrayHasKey('/admin/{rest}', $routes);
    }

    public function testTraversalDotfileAndPhpAreDenied(): void
    {
        [$routes] = $this->mount('/admin', $this->dir);
        $serve = $routes['/admin/{rest}'];
        foreach (['../../../etc/passwd', '../.env', '.env', 'config.php'] as $bad) {
            $resp = $serve(Request::create("/admin/$bad"), $bad);
            self::assertSame(404, $resp->getStatusCode(), "$bad must be denied");
        }
    }

    public function testNonHashedAssetServedWithNoCacheAndSecurityHeaders(): void
    {
        [$routes] = $this->mount('/admin', $this->dir);
        $resp = $routes['/admin/{rest}'](Request::create('/admin/style.css'), 'style.css');

        self::assertSame(200, $resp->getStatusCode());
        self::assertInstanceOf(BinaryFileResponse::class, $resp);
        self::assertSame('nosniff', $resp->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-cache', (string) $resp->headers->get('Cache-Control'));
    }

    public function testTextAssetsGetExtensionMimeNotSniffedTextPlain(): void
    {
        // finfo content-sniffing calls css/js "text/plain" (no magic bytes), and
        // these responses carry X-Content-Type-Options: nosniff — so a wrong
        // type makes browsers REFUSE stylesheets/module scripts outright. The
        // extension map must win for known extensions; sniffing is only the
        // fallback for extensionless files.
        [$routes] = $this->mount('/admin', $this->dir);
        $css = $routes['/admin/{rest}'](Request::create('/admin/style.css'), 'style.css');
        self::assertSame('text/css', $css->headers->get('Content-Type'));
        $js = $routes['/admin/{rest}'](
            Request::create('/admin/assets/app-C5kJ8nQ2.js'),
            'assets/app-C5kJ8nQ2.js',
        );
        self::assertStringContainsString('javascript', (string) $js->headers->get('Content-Type'));
    }

    public function testHashedAssetServedImmutable(): void
    {
        [$routes] = $this->mount('/admin', $this->dir);
        $resp = $routes['/admin/{rest}'](Request::create('/admin/assets/app-C5kJ8nQ2.js'), 'assets/app-C5kJ8nQ2.js');

        self::assertSame(200, $resp->getStatusCode());
        self::assertStringContainsString('immutable', (string) $resp->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=31536000', (string) $resp->headers->get('Cache-Control'));
        self::assertNotEmpty($resp->headers->get('ETag'));
    }

    public function testRootServesIndexWithNoCache(): void
    {
        [$routes] = $this->mount('/admin', $this->dir);
        $resp = $routes['/admin'](Request::create('/admin'));

        self::assertSame(200, $resp->getStatusCode());
        self::assertStringContainsString('no-cache', (string) $resp->headers->get('Cache-Control'));
    }

    public function testRouteLikeDeepLinkFallsBackToIndex(): void
    {
        [$routes] = $this->mount('/admin', $this->dir);
        $resp = $routes['/admin/{rest}'](Request::create('/admin/posts/123'), 'posts/123');

        self::assertSame(200, $resp->getStatusCode());
        self::assertStringContainsString('no-cache', (string) $resp->headers->get('Cache-Control'));
    }

    public function testMissingAssetIsA404NotIndex(): void
    {
        [$routes] = $this->mount('/admin', $this->dir);
        $resp = $routes['/admin/{rest}'](Request::create('/admin/missing.js'), 'missing.js');
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testDotRuleTreatsDottedPathAsAsset(): void
    {
        [$routes] = $this->mount('/admin', $this->dir);
        $resp = $routes['/admin/{rest}'](Request::create('/admin/docs.v1'), 'docs.v1');
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testSpaFallbackFalseReturns404OnMissAndRoot(): void
    {
        [$routes] = $this->mount('/downloads', $this->dir, ['spaFallback' => false]);

        $miss = $routes['/downloads/{rest}'](Request::create('/downloads/nope'), 'nope');
        self::assertSame(404, $miss->getStatusCode());

        $root = $routes['/downloads'](Request::create('/downloads'));
        self::assertSame(404, $root->getStatusCode());

        // A real file is still served with spaFallback:false.
        $hit = $routes['/downloads/{rest}'](Request::create('/downloads/style.css'), 'style.css');
        self::assertSame(200, $hit->getStatusCode());
    }
}
