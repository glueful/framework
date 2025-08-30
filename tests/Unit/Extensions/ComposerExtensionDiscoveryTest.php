<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Extensions\Services\ComposerExtensionDiscovery;
use PHPUnit\Framework\TestCase;

final class ComposerExtensionDiscoveryTest extends TestCase
{
    public function test_discovers_glueful_extension_package(): void
    {
        $root = sys_get_temp_dir() . '/composer-ext-' . uniqid();
        $vendor = $root . '/vendor/glueful/demo-extension';
        @mkdir($vendor . '/src', 0755, true);
        @mkdir($root . '/vendor/composer', 0755, true);

        // Create class file that maps via PSR-4
        $class = <<<'PHP'
        <?php
        namespace Glueful\ComposerDemo;
        class DemoExtension {}
        PHP;
        file_put_contents($vendor . '/src/DemoExtension.php', $class);

        // installed.json for Composer v2
        $installed = [
            'packages' => [[
                'name' => 'glueful/demo-extension',
                'type' => 'glueful-extension',
                'version' => '1.2.3',
                'description' => 'Demo Composer Extension',
                'autoload' => [ 'psr-4' => [ 'Glueful\\\\ComposerDemo\\\\' => 'src' ]],
                'extra' => [ 'glueful' => [ 'extension-class' => 'Glueful\\\\ComposerDemo\\\\DemoExtension' ] ],
            ]]
        ];
        file_put_contents($root . '/vendor/composer/installed.json', json_encode($installed, JSON_PRETTY_PRINT));

        $discovery = new ComposerExtensionDiscovery($root, null);
        $packages = $discovery->discoverExtensionPackages();

        $this->assertNotEmpty($packages);
        $pkg = $packages[0];
        $this->assertSame('glueful/demo-extension', $pkg['package_name']);
        $this->assertSame('Demo', $pkg['extension_name']);
        $this->assertSame('Glueful\\ComposerDemo\\DemoExtension', $pkg['extension_class']);
        $this->assertSame('1.2.3', $pkg['version']);
        $this->assertDirectoryExists($pkg['install_path']);

        // Cleanup
        $this->rrmdir($root);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

