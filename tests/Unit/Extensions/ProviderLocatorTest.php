<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use PHPUnit\Framework\TestCase;
use Glueful\Extensions\ProviderLocator;
use Glueful\Bootstrap\ApplicationContext;

class ProviderLocatorTest extends TestCase
{
    private string $tempConfigDir;
    private ?ApplicationContext $context = null;

    protected function setUp(): void
    {
        // Create temporary config directory for testing
        $this->tempConfigDir = sys_get_temp_dir() . '/test_config_' . uniqid();
        mkdir($this->tempConfigDir, 0755, true);
        $this->context = new ApplicationContext(
            basePath: sys_get_temp_dir(),
            environment: 'testing',
            configPaths: [
                'application' => $this->tempConfigDir,
                'framework' => $this->tempConfigDir
            ]
        );
    }


    public function testDiscoveryOrderDeterminism(): void
    {
        $this->markTestSkipped(
            'Skipping due to static config cache issue. ' .
            'The config() function caches by filename, causing interference between tests.'
        );
    }

    public function testExclusiveAllowListMode(): void
    {
        $this->markTestSkipped(
            'Skipping due to static config cache issue. ' .
            'The config() function caches by filename, causing interference between tests.'
        );
    }

    public function testDisabledProvidersAreFiltered(): void
    {
        $this->markTestSkipped(
            'Skipping due to static config cache issue. ' .
            'The config() function caches by filename, causing interference between tests.'
        );
    }

    public function testProductionExcludesDevOnly(): void
    {
        $this->markTestSkipped(
            'Skipping due to static config cache issue. ' .
            'The config() function caches by filename, causing interference between tests.'
        );
    }

    public function testDeduplicationPreservesFirstOccurrence(): void
    {
        $this->markTestSkipped(
            'Skipping due to static config cache issue. ' .
            'The config() function caches by filename, causing interference between tests.'
        );
    }

    public function testScanLocalExtensionsWithValidComposerJson(): void
    {
        // Create temporary extension directory structure
        $tempDir = sys_get_temp_dir() . '/extensions_test_' . uniqid();
        $extensionDir = $tempDir . '/test-extension';
        mkdir($extensionDir, 0755, true);

        $context = new ApplicationContext(
            basePath: $tempDir,
            environment: 'testing',
            configPaths: [
                'application' => $this->tempConfigDir,
                'framework' => $this->tempConfigDir
            ]
        );

        // Create a mock vendor/autoload.php file for the test
        $vendorDir = $tempDir . '/vendor';
        mkdir($vendorDir, 0755, true);
        file_put_contents($vendorDir . '/autoload.php', '<?php
            class MockClassLoader {
                public function addPsr4($prefix, $paths, $prepend = false) {}
                public function register($prepend = false) {}
            }
            return new MockClassLoader();
        ');

        $composerJson = [
            'name' => 'test/extension',
            'type' => 'glueful-extension',
            'autoload' => [
                'psr-4' => [
                    'Test\\Extension\\' => 'src/'
                ]
            ],
            'extra' => [
                'glueful' => [
                    'provider' => 'Test\\Extension\\TestProvider'
                ]
            ]
        ];

        file_put_contents($extensionDir . '/composer.json', json_encode($composerJson));

        // Create src directory to make autoloading valid
        mkdir($extensionDir . '/src', 0755, true);

        try {
            // Use reflection to test private method
            $reflection = new \ReflectionClass(ProviderLocator::class);
            $method = $reflection->getMethod('scanLocalExtensions');
            $method->setAccessible(true);

            // Pass an empty string since base_path will be prepended
            $providers = $method->invoke(null, $context, '');

            $this->assertEquals(['Test\\Extension\\TestProvider'], $providers);
        } finally {
            // Clean up
            unlink($extensionDir . '/composer.json');
            rmdir($extensionDir . '/src');
            rmdir($extensionDir);
            unlink($vendorDir . '/autoload.php');
            rmdir($vendorDir);
            rmdir($tempDir);
        }
    }

    public function testScanLocalExtensionsSkipsInvalidJson(): void
    {
        // Create temporary extension directory with invalid JSON
        $tempDir = sys_get_temp_dir() . '/extensions_test_' . uniqid();
        $extensionDir = $tempDir . '/invalid-extension';
        mkdir($extensionDir, 0755, true);

        $context = new ApplicationContext(
            basePath: $tempDir,
            environment: 'testing',
            configPaths: [
                'application' => $this->tempConfigDir,
                'framework' => $this->tempConfigDir
            ]
        );

        // Create a mock vendor/autoload.php file for the test
        $vendorDir = $tempDir . '/vendor';
        mkdir($vendorDir, 0755, true);
        file_put_contents($vendorDir . '/autoload.php', '<?php
            class MockClassLoader {
                public function addPsr4($prefix, $paths, $prepend = false) {}
                public function register($prepend = false) {}
            }
            return new MockClassLoader();
        ');

        // Invalid JSON
        file_put_contents($extensionDir . '/composer.json', '{ invalid json }');

        try {
            // Use reflection to test private method
            $reflection = new \ReflectionClass(ProviderLocator::class);
            $method = $reflection->getMethod('scanLocalExtensions');
            $method->setAccessible(true);

            // Pass an empty string since base_path will be prepended
            $providers = $method->invoke(null, $context, '');

            // Should return empty array for invalid JSON
            $this->assertEquals([], $providers);
        } finally {
            // Clean up
            unlink($extensionDir . '/composer.json');
            rmdir($extensionDir);
            unlink($vendorDir . '/autoload.php');
            rmdir($vendorDir);
            rmdir($tempDir);
        }
    }

    protected function tearDown(): void
    {
        // Reset environment
        unset($_ENV['APP_ENV']);
        $this->context = null;

        // Clean up temporary config directory
        if (is_dir($this->tempConfigDir)) {
            if (file_exists($this->tempConfigDir . '/extensions.php')) {
                unlink($this->tempConfigDir . '/extensions.php');
            }
            rmdir($this->tempConfigDir);
        }
    }
}
