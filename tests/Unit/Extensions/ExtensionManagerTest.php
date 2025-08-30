<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Framework;
use Glueful\Extensions\ExtensionManager;
use PHPUnit\Framework\TestCase;

final class ExtensionManagerTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/ext-manager-' . uniqid();
        @mkdir($this->baseDir . '/config', 0755, true);
        @mkdir($this->baseDir . '/extensions/Demo/src', 0755, true);

        // Minimal configs
        file_put_contents($this->baseDir . '/config/app.php', "<?php return ['env'=>'testing','debug'=>true,'version_full'=>'1.0.0'];\n");
        file_put_contents($this->baseDir . '/config/extensions.php', file_get_contents(base_path('config/extensions.php')) ?: "<?php return [];\n");

        // Local extension structure
        $manifest = [
            'version' => '1.0.0',
            'author' => 'Glueful Team',
            'main' => 'Demo.php',
            'main_class' => 'Glueful\\Extensions\\Demo',
        ];
        file_put_contents($this->baseDir . '/extensions/Demo/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        file_put_contents($this->baseDir . '/extensions/Demo/Demo.php', "<?php\\nnamespace Glueful\\\\Extensions;\\nclass Demo {}\\n");

        Framework::create($this->baseDir)->withEnvironment('testing')->boot(allowReboot: true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->baseDir);
        parent::tearDown();
    }

    public function test_installation_state_and_enable_flow(): void
    {
        /** @var ExtensionManager $manager */
        $manager = container()->get(ExtensionManager::class);

        $this->assertTrue($manager->isInstalled('Demo'));
        $this->assertFalse($manager->isEnabled('Demo'));
        $this->assertFalse($manager->isLoaded('Demo'));

        $this->assertTrue($manager->enable('Demo'));

        $this->assertTrue($manager->isEnabled('Demo'));
        // Loader marks it loaded
        $this->assertTrue($manager->isLoaded('Demo'));
        $this->assertContains('Demo', array_map(fn($e) => $e['name'], $manager->listInstalled()));
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

