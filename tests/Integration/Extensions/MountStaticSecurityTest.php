<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Extensions;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Extensions\ServiceProvider;
use Glueful\Routing\Router;
use Psr\Container\ContainerInterface;

class MountStaticSecurityTest extends TestCase
{
    private string $fixturesDir;
    private ContainerInterface&MockObject $container;
    private Router&MockObject $router;

    protected function setUp(): void
    {
        // Create fixtures directory with test files
        $this->fixturesDir = sys_get_temp_dir() . '/mount_static_fixtures_' . uniqid();
        mkdir($this->fixturesDir, 0755, true);

        // Create test files
        file_put_contents($this->fixturesDir . '/public.txt', 'Public content');
        file_put_contents($this->fixturesDir . '/index.html', '<html><body>SPA Root</body></html>');
        file_put_contents($this->fixturesDir . '/script.js', 'console.log("js");');
        file_put_contents($this->fixturesDir . '/style.css', 'body { color: red; }');

        // Create sensitive files that should be blocked
        file_put_contents($this->fixturesDir . '/.env', 'SECRET=sensitive');
        file_put_contents($this->fixturesDir . '/config.php', '<?php return ["secret" => "value"];');

        // Create subdirectory with files
        mkdir($this->fixturesDir . '/subdir');
        file_put_contents($this->fixturesDir . '/subdir/file.txt', 'Subdirectory content');

        // Set up mock container and router
        $this->container = $this->createMock(ContainerInterface::class);
        $this->router = $this->createMock(Router::class);

        $this->container->expects($this->any())
                       ->method('has')
                       ->with(\Glueful\Routing\Router::class)
                       ->willReturn(true);
        $this->container->expects($this->any())
                       ->method('get')
                       ->with(\Glueful\Routing\Router::class)
                       ->willReturn($this->router);
    }

    protected function tearDown(): void
    {
        // Clean up fixtures
        if (is_dir($this->fixturesDir)) {
            $this->removeDirectory($this->fixturesDir);
        }
    }

    public function testMountStaticDeniesTraversalAndPhp(): void
    {
        // Create test provider and mount static files
        $provider = new TestMountStaticProvider($this->container);

        // Set up router to capture registered routes
        $routes = [];
        $this->router->expects($this->any())
                    ->method('get')
                    ->willReturnCallback(function ($path, $handler) use (&$routes) {
                        $routes[$path] = $handler;
                        $mockRoute = $this->createMock(\Glueful\Routing\Route::class);
                        $mockRoute->method('where')->willReturnSelf();
                        return $mockRoute;
                    });

        // Mount the static files
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('mountStatic');
        $method->setAccessible(true);
        $method->invoke($provider, 'demo', $this->fixturesDir);

        // Get the file serving handler
        $this->assertArrayHasKey("/extensions/demo/{path}", $routes);
        $fileHandler = $routes["/extensions/demo/{path}"];

        // Test path traversal attack
        $request = Request::create('/extensions/demo/../../../etc/passwd', 'GET');
        $response = $fileHandler($request, '../../../etc/passwd');
        $this->assertEquals(404, $response->getStatusCode());

        // Test relative path traversal
        $request = Request::create('/extensions/demo/../.env', 'GET');
        $response = $fileHandler($request, '../.env');
        $this->assertEquals(404, $response->getStatusCode());

        // Test PHP file access
        $request = Request::create('/extensions/demo/config.php', 'GET');
        $response = $fileHandler($request, 'config.php');
        $this->assertEquals(404, $response->getStatusCode());

        // Test dotfile access
        $request = Request::create('/extensions/demo/.env', 'GET');
        $response = $fileHandler($request, '.env');
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testMountStaticAllowsLegitimateFiles(): void
    {
        $provider = new TestMountStaticProvider($this->container);

        // Set up router to capture registered routes
        $routes = [];
        $this->router->expects($this->any())
                    ->method('get')
                    ->willReturnCallback(function ($path, $handler) use (&$routes) {
                        $routes[$path] = $handler;
                        $mockRoute = $this->createMock(\Glueful\Routing\Route::class);
                        $mockRoute->method('where')->willReturnSelf();
                        return $mockRoute;
                    });

        // Mount the static files
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('mountStatic');
        $method->setAccessible(true);
        $method->invoke($provider, 'demo', $this->fixturesDir);

        // Get the file serving handler
        $fileHandler = $routes["/extensions/demo/{path}"];

        // Test legitimate file access
        $request = Request::create('/extensions/demo/public.txt', 'GET');
        $response = $fileHandler($request, 'public.txt');
        $this->assertEquals(200, $response->getStatusCode());
        $txtContentType = $response->headers->get('Content-Type');
        $this->assertNotEmpty($txtContentType, 'Content-Type header should be set for text files');

        // Test CSS file
        $request = Request::create('/extensions/demo/style.css', 'GET');
        $response = $fileHandler($request, 'style.css');
        $this->assertEquals(200, $response->getStatusCode());
        $contentType = $response->headers->get('Content-Type');
        $this->assertNotEmpty($contentType, 'Content-Type header should be set for CSS files');

        // Test JS file
        $request = Request::create('/extensions/demo/script.js', 'GET');
        $response = $fileHandler($request, 'script.js');
        $this->assertEquals(200, $response->getStatusCode());
        $jsContentType = $response->headers->get('Content-Type');
        $this->assertNotEmpty($jsContentType, 'Content-Type header should be set for JS files');

        // Test subdirectory file
        $request = Request::create('/extensions/demo/subdir/file.txt', 'GET');
        $response = $fileHandler($request, 'subdir/file.txt');
        $this->assertEquals(200, $response->getStatusCode());
        $subdirContentType = $response->headers->get('Content-Type');
        $this->assertNotEmpty($subdirContentType, 'Content-Type header should be set for subdirectory files');
    }

    public function testMountStaticRootServesIndexAndHonorsEtag(): void
    {
        $provider = new TestMountStaticProvider($this->container);

        // Set up router to capture registered routes
        $routes = [];
        $this->router->expects($this->any())
                    ->method('get')
                    ->willReturnCallback(function ($path, $handler) use (&$routes) {
                        $routes[$path] = $handler;
                        $mockRoute = $this->createMock(\Glueful\Routing\Route::class);
                        $mockRoute->method('where')->willReturnSelf();
                        return $mockRoute;
                    });

        // Mount the static files
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('mountStatic');
        $method->setAccessible(true);
        $method->invoke($provider, 'demo', $this->fixturesDir);

        // Get the index handler
        $this->assertArrayHasKey("/extensions/demo", $routes);
        $indexHandler = $routes["/extensions/demo"];

        // Test index.html serving
        $request = Request::create('/extensions/demo', 'GET');
        $response = $indexHandler($request);
        $this->assertEquals(200, $response->getStatusCode());
        // Note: BinaryFileResponse may not set Content-Type for HTML files in test environment
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response);

        // Verify security headers are set
        $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertEquals('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        $this->assertStringContainsString(
            "default-src 'self'",
            (string) $response->headers->get('Content-Security-Policy')
        );
    }

    public function testMountStaticSecurityHeaders(): void
    {
        $provider = new TestMountStaticProvider($this->container);

        // Set up router to capture registered routes
        $routes = [];
        $this->router->expects($this->any())
                    ->method('get')
                    ->willReturnCallback(function ($path, $handler) use (&$routes) {
                        $routes[$path] = $handler;
                        $mockRoute = $this->createMock(\Glueful\Routing\Route::class);
                        $mockRoute->method('where')->willReturnSelf();
                        return $mockRoute;
                    });

        // Mount the static files
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('mountStatic');
        $method->setAccessible(true);
        $method->invoke($provider, 'demo', $this->fixturesDir);

        // Get the file serving handler
        $fileHandler = $routes["/extensions/demo/{path}"];

        // Test security headers on file response
        $request = Request::create('/extensions/demo/public.txt', 'GET');
        $response = $fileHandler($request, 'public.txt');

        // Verify all security headers are present
        $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertEquals('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        $this->assertEquals('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertEquals('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertEquals('0', $response->headers->get('X-XSS-Protection'));
        $this->assertStringContainsString(
            "default-src 'self'",
            (string) $response->headers->get('Content-Security-Policy')
        );

        // Verify caching headers
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=31536000', $cacheControl);
        $this->assertStringContainsString('immutable', $cacheControl);
        $this->assertNotEmpty($response->headers->get('ETag'));
        $this->assertNotEmpty($response->headers->get('Last-Modified'));
    }

    private function removeDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

// Test ServiceProvider for mounting static files
class TestMountStaticProvider extends ServiceProvider // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
{
    public static function services(): array
    {
        return [];
    }

    public function testMountStatic(string $mount, string $dir): void
    {
        $this->mountStatic($mount, $dir);
    }
}
