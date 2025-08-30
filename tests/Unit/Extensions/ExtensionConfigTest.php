<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Framework;
use Glueful\Extensions\Services\ExtensionConfig;
use PHPUnit\Framework\TestCase;

final class ExtensionConfigTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/ext-config-' . uniqid();
        @mkdir($this->baseDir . '/config', 0755, true);
        @mkdir($this->baseDir . '/extensions', 0755, true);

        // Minimal required configs to boot
        file_put_contents($this->baseDir . '/config/app.php', "<?php return ['env' => 'testing','debug'=>true,'version_full'=>'1.0.0'];\n");
        file_put_contents($this->baseDir . '/config/extensions.php', file_get_contents(base_path('config/extensions.php')) ?: "<?php return [];\n");

        // Create a dummy extension with manifest
        $extPath = $this->baseDir . '/extensions/DemoExt';
        @mkdir($extPath . '/src', 0755, true);
        file_put_contents($extPath . '/manifest.json', json_encode([
            'version' => '1.0.0',
            'author' => 'Glueful Team',
            'engines' => ['php' => '>=8.2'],
            'provides' => [ 'routes' => [] ],
        ], JSON_PRETTY_PRINT));

        Framework::create($this->baseDir)->withEnvironment('testing')->boot(allowReboot: true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->baseDir);
        parent::tearDown();
    }

    public function test_enable_and_disable_extension_updates_config(): void
    {
        $service = container()->get(ExtensionConfig::class);
        $service->setDebugMode(true);

        $this->assertFalse($service->isEnabled('DemoExt'));
        $service->enableExtension('DemoExt');
        $this->assertTrue($service->isEnabled('DemoExt'));
        $this->assertContains('DemoExt', $service->getEnabledExtensions());

        $service->disableExtension('DemoExt');
        $this->assertFalse($service->isEnabled('DemoExt'));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

