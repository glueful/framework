<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;

class CommandDiscoveryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Create a temporary directory for test command files
        $this->tempDir = sys_get_temp_dir() . '/glueful_cmd_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/Console', 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temporary files
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function discoverCommandsFindsValidCommands(): void
    {
        // Create a valid command file
        $commandCode = <<<'PHP'
<?php
namespace TestExtension\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'test:discovered', description: 'A test command')]
class DiscoveredCommand extends Command
{
    protected function execute($input, $output): int
    {
        return Command::SUCCESS;
    }
}
PHP;
        file_put_contents($this->tempDir . '/Console/DiscoveredCommand.php', $commandCode);

        // Load the class
        require_once $this->tempDir . '/Console/DiscoveredCommand.php';

        // Create mock container
        $console = new ConsoleApplication();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(function ($id) use ($console) {
            return $id === 'console.application';
        });
        $container->method('get')->willReturnCallback(function ($id) use ($console) {
            if ($id === 'console.application') {
                return $console;
            }
            return new $id();
        });

        // Create a test provider that exposes the protected method
        $provider = new class($container) extends \Glueful\Extensions\ServiceProvider {
            public function testDiscoverCommands(string $namespace, string $directory): void
            {
                $this->discoverCommands($namespace, $directory);
            }
        };

        // Discover commands
        $provider->testDiscoverCommands('TestExtension\\Console', $this->tempDir . '/Console');

        // Verify command was discovered and registered
        $this->assertTrue($console->has('test:discovered'));
    }

    #[Test]
    public function discoverCommandsIgnoresAbstractClasses(): void
    {
        // Create an abstract command file
        $abstractCode = <<<'PHP'
<?php
namespace TestExtension2\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'test:abstract')]
abstract class AbstractCommand extends Command
{
}
PHP;
        file_put_contents($this->tempDir . '/Console/AbstractCommand.php', $abstractCode);

        // Load the class
        require_once $this->tempDir . '/Console/AbstractCommand.php';

        // Create mock container
        $console = new ConsoleApplication();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(function ($id) use ($console) {
            return $id === 'console.application';
        });
        $container->method('get')->willReturnCallback(function ($id) use ($console) {
            return $console;
        });

        $provider = new class($container) extends \Glueful\Extensions\ServiceProvider {
            public function testDiscoverCommands(string $namespace, string $directory): void
            {
                $this->discoverCommands($namespace, $directory);
            }
        };

        $provider->testDiscoverCommands('TestExtension2\\Console', $this->tempDir . '/Console');

        // Abstract class should NOT be registered
        $this->assertFalse($console->has('test:abstract'));
    }

    #[Test]
    public function discoverCommandsIgnoresClassesWithoutAttribute(): void
    {
        // Create a command without #[AsCommand] attribute
        $noAttrCode = <<<'PHP'
<?php
namespace TestExtension3\Console;

use Symfony\Component\Console\Command\Command;

class NoAttributeCommand extends Command
{
    protected static $defaultName = 'test:no-attr';
}
PHP;
        file_put_contents($this->tempDir . '/Console/NoAttributeCommand.php', $noAttrCode);

        require_once $this->tempDir . '/Console/NoAttributeCommand.php';

        $console = new ConsoleApplication();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(function ($id) use ($console) {
            return $id === 'console.application';
        });
        $container->method('get')->willReturnCallback(function ($id) use ($console) {
            return $console;
        });

        $provider = new class($container) extends \Glueful\Extensions\ServiceProvider {
            public function testDiscoverCommands(string $namespace, string $directory): void
            {
                $this->discoverCommands($namespace, $directory);
            }
        };

        $provider->testDiscoverCommands('TestExtension3\\Console', $this->tempDir . '/Console');

        // Command without attribute should NOT be registered
        $this->assertFalse($console->has('test:no-attr'));
    }

    #[Test]
    public function discoverCommandsHandlesNestedDirectories(): void
    {
        // Create nested directory structure
        mkdir($this->tempDir . '/Console/Sub', 0755, true);

        $nestedCode = <<<'PHP'
<?php
namespace TestExtension4\Console\Sub;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'test:nested', description: 'A nested command')]
class NestedCommand extends Command
{
    protected function execute($input, $output): int
    {
        return Command::SUCCESS;
    }
}
PHP;
        file_put_contents($this->tempDir . '/Console/Sub/NestedCommand.php', $nestedCode);

        require_once $this->tempDir . '/Console/Sub/NestedCommand.php';

        $console = new ConsoleApplication();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(function ($id) use ($console) {
            return $id === 'console.application';
        });
        $container->method('get')->willReturnCallback(function ($id) use ($console) {
            if ($id === 'console.application') {
                return $console;
            }
            return new $id();
        });

        $provider = new class($container) extends \Glueful\Extensions\ServiceProvider {
            public function testDiscoverCommands(string $namespace, string $directory): void
            {
                $this->discoverCommands($namespace, $directory);
            }
        };

        $provider->testDiscoverCommands('TestExtension4\\Console', $this->tempDir . '/Console');

        // Nested command should be discovered
        $this->assertTrue($console->has('test:nested'));
    }

    #[Test]
    public function discoverCommandsHandlesNonExistentDirectory(): void
    {
        $console = new ConsoleApplication();
        $initialCount = count($console->all());

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($console);

        $provider = new class($container) extends \Glueful\Extensions\ServiceProvider {
            public function testDiscoverCommands(string $namespace, string $directory): void
            {
                $this->discoverCommands($namespace, $directory);
            }
        };

        // Should not throw, just return gracefully
        $provider->testDiscoverCommands('NonExistent\\Console', '/nonexistent/path');

        // No new commands should be added
        $this->assertCount($initialCount, $console->all());
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
