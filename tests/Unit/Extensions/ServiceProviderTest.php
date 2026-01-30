<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Psr\Container\ContainerInterface;
use Glueful\Routing\Router;
use Glueful\Routing\Route;
use Glueful\Database\Migrations\MigrationManager;

class ServiceProviderTest extends TestCase
{
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
    }

    public function testServicesReturnsEmptyArrayByDefault(): void
    {
        $services = TestServiceProvider::services();
        $this->assertIsArray($services);
        $this->assertEmpty($services);
    }

    public function testLoadRoutesFromWithValidFile(): void
    {
        $router = $this->createMock(Router::class);

        $this->container->method('has')
                       ->with(Router::class)
                       ->willReturn(true);
        $this->container->method('get')
                       ->with(Router::class)
                       ->willReturn($router);

        $provider = new TestServiceProvider($this->container);

        // Create a temporary routes file
        $routesFile = tempnam(sys_get_temp_dir(), 'test_routes_');
        file_put_contents($routesFile, '<?php // Test routes file');

        try {
            // Use reflection to call protected method
            $reflection = new \ReflectionClass($provider);
            $method = $reflection->getMethod('loadRoutesFrom');
            $method->setAccessible(true);
            $method->invoke($provider, $routesFile);

            // If we get here without exception, the method worked
            $this->assertTrue(true);
        } finally {
            unlink($routesFile);
        }
    }

    public function testLoadRoutesFromWithMissingFile(): void
    {
        $provider = new TestServiceProvider($this->container);

        // Use reflection to call protected method with non-existent file
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('loadRoutesFrom');
        $method->setAccessible(true);

        // Should not throw exception with missing file
        $method->invoke($provider, '/path/to/nonexistent/file.php');
        $this->assertTrue(true);
    }

    public function testLoadMigrationsFromWithValidDirectory(): void
    {
        $migrationManager = $this->createMock(MigrationManager::class);
        $migrationManager->expects($this->once())
                        ->method('addMigrationPath');

        $this->container->method('has')
                       ->with(MigrationManager::class)
                       ->willReturn(true);
        $this->container->method('get')
                       ->with(MigrationManager::class)
                       ->willReturn($migrationManager);

        $provider = new TestServiceProvider($this->container);

        // Create a temporary directory
        $migrationDir = sys_get_temp_dir() . '/test_migrations_' . uniqid();
        mkdir($migrationDir);

        try {
            // Use reflection to call protected method
            $reflection = new \ReflectionClass($provider);
            $method = $reflection->getMethod('loadMigrationsFrom');
            $method->setAccessible(true);
            $method->invoke($provider, $migrationDir);
        } finally {
            rmdir($migrationDir);
        }
    }

    public function testMountStaticWithInvalidMountName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid mount name');

        $provider = new TestServiceProvider($this->container);

        // Use reflection to call protected method
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('mountStatic');
        $method->setAccessible(true);
        $method->invoke($provider, 'Invalid_Mount_Name!', '/some/dir');
    }

    public function testMountStaticWithValidMountName(): void
    {
        $router = $this->createMock(Router::class);
        $mockRoute = $this->createMock(Route::class);
        $mockRoute->method('where')->willReturnSelf();

        $router->expects($this->exactly(2))
               ->method('get')
               ->willReturn($mockRoute);

        $this->container->method('has')
                       ->with(\Glueful\Routing\Router::class)
                       ->willReturn(true);
        $this->container->method('get')
                       ->with(\Glueful\Routing\Router::class)
                       ->willReturn($router);

        $provider = new TestServiceProvider($this->container);

        // Create a temporary directory
        $staticDir = sys_get_temp_dir() . '/test_static_' . uniqid();
        mkdir($staticDir);

        try {
            // Use reflection to call protected method
            $reflection = new \ReflectionClass($provider);
            $method = $reflection->getMethod('mountStatic');
            $method->setAccessible(true);
            $method->invoke($provider, 'valid-mount', $staticDir);
        } finally {
            rmdir($staticDir);
        }
    }

    public function testRunningInConsole(): void
    {
        $provider = new TestServiceProvider($this->container);

        // Use reflection to call protected method
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('runningInConsole');
        $method->setAccessible(true);
        $result = $method->invoke($provider);

        $this->assertIsBool($result);
        // In PHPUnit, we're running in CLI mode
        $this->assertTrue($result);
    }
}

// Test implementation of ServiceProvider for testing
class TestServiceProvider extends ServiceProvider // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
{
    public static function services(): array
    {
        return [];
    }

    public function register(ApplicationContext $context): void
    {
        // Empty implementation for testing
    }

    public function boot(ApplicationContext $context): void
    {
        // Empty implementation for testing
    }
}
