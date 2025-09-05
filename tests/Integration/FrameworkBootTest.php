<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration;

use Glueful\Application;
use Glueful\Framework;
use Glueful\Configuration\ConfigRepositoryInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class FrameworkBootTest extends TestCase
{
    private string $testAppPath;
    private string $testConfigPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear any cached configuration from previous tests
        \Glueful\Bootstrap\ConfigurationCache::clear();

        $this->testAppPath = sys_get_temp_dir() . '/glueful-test-' . uniqid();
        $this->testConfigPath = $this->testAppPath . '/config';

        // Create test directory structure
        mkdir($this->testAppPath, 0755, true);
        mkdir($this->testConfigPath, 0755, true);

        // Create minimal test configuration files
        file_put_contents(
            $this->testConfigPath . '/app.php',
            "<?php\nreturn [\n  'name' => 'Test App',\n  'version_full' => '1.0.0',\n" .
            "  'env' => 'testing',\n  'debug' => true,\n];\n"
        );
        file_put_contents(
            $this->testConfigPath . '/database.php',
            "<?php\nreturn [\n  'engine' => 'sqlite',\n" .
            "  'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],\n];\n"
        );
        file_put_contents(
            $this->testConfigPath . '/cache.php',
            "<?php\nreturn [\n  'enabled' => true,\n  'default' => 'array',\n" .
            "  'stores' => ['array' => ['driver' => 'array']],\n];\n"
        );
        file_put_contents(
            $this->testConfigPath . '/security.php',
            "<?php\nreturn [\n  'csrf' => ['enabled' => false],\n];\n"
        );
        file_put_contents($this->testConfigPath . '/session.php', "<?php\nreturn [\n  'jwt_key' => 'test',\n];\n");
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testAppPath)) {
            $this->recursiveRemoveDirectory($this->testAppPath);
        }
        parent::tearDown();
    }

    public function testFrameworkBootsSuccessfully(): void
    {
        $framework = Framework::create($this->testAppPath);
        $this->assertInstanceOf(Framework::class, $framework);
        $this->assertFalse($framework->isBooted());

        $app = $framework->boot(allowReboot: true);

        $this->assertInstanceOf(Application::class, $app);
        $this->assertTrue($framework->isBooted());
    }

    public function testFrameworkBootIdempotency(): void
    {
        $framework = Framework::create($this->testAppPath);
        $framework->boot(allowReboot: true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Framework already booted');
        $framework->boot();
    }

    public function testFrameworkWithCustomConfigPath(): void
    {
        $customConfigPath = $this->testAppPath . '/custom-config';
        mkdir($customConfigPath, 0755, true);
        file_put_contents($customConfigPath . '/app.php', "<?php return ['custom' => true];\n");

        $framework = Framework::create($this->testAppPath)->withConfigDir($customConfigPath);
        $this->assertSame($customConfigPath, $framework->getConfigPath());

        $framework->boot(allowReboot: true);
        $this->assertTrue((bool) config('app.custom'));
    }

    public function testContainerServicesRegistered(): void
    {
        $framework = Framework::create($this->testAppPath);
        $app = $framework->boot(allowReboot: true);
        $container = $app->getContainer();

        $this->assertTrue($container->has(LoggerInterface::class));
        $this->assertTrue($container->has(ConfigRepositoryInterface::class));
        $this->assertNotEmpty(\Glueful\Http\Router::getVersionPrefix());
    }

    private function recursiveRemoveDirectory(string $directory): void
    {
        $files = array_diff(scandir($directory), ['.', '..']);
        foreach ($files as $file) {
            $path = $directory . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->recursiveRemoveDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
