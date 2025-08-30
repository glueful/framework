<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Extensions;

use Glueful\Framework;
use Glueful\Extensions\ExtensionManager;
use PHPUnit\Framework\TestCase;

final class ExtensionAutoloadingTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/ext-autoload-' . uniqid();
        @mkdir($this->baseDir . '/config', 0755, true);
        @mkdir($this->baseDir . '/extensions/Auto/src', 0755, true);

        file_put_contents(
            $this->baseDir . '/config/app.php',
            "<?php return ['env'=>'testing','debug'=>true,'version_full'=>'1.0.0'];\n"
        );
        $extensionsConfig = file_get_contents(base_path('config/extensions.php')) ?: "<?php return [];\n";
        file_put_contents($this->baseDir . '/config/extensions.php', $extensionsConfig);

        // Local extension manifest - autoload mapping provided by ExtensionConfig default
        file_put_contents($this->baseDir . '/extensions/Auto/manifest.json', json_encode([
            'version' => '1.0.0',
            'author' => 'Glueful Team',
            'main' => 'Auto.php',
            'main_class' => 'Glueful\\\\Extensions\\\\Auto',
        ], JSON_PRETTY_PRINT));
        file_put_contents(
            $this->baseDir . '/extensions/Auto/Auto.php',
            "<?php\\nnamespace Glueful\\\\Extensions;\\nclass Auto {}\\n"
        );

        // A PSR-4 class under extension namespace to be autoloaded
        file_put_contents(
            $this->baseDir . '/extensions/Auto/src/Helper.php',
            "<?php\\nnamespace Glueful\\\\Extensions\\\\Auto;\\n" .
            "class Helper { public static function ping(): string { return 'pong'; } }\\n"
        );

        Framework::create($this->baseDir)->withEnvironment('testing')->boot(allowReboot: true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->baseDir);
        parent::tearDown();
    }

    public function testPsr4AutoloadingRegistersExtensionNamespace(): void
    {
        /** @var ExtensionManager $manager */
        $manager = container()->get(ExtensionManager::class);
        $this->assertTrue($manager->enable('Auto'));

        // After enabling, ExtensionLoader->registerNamespace should map PSR-4
        $class = 'Glueful\\Extensions\\Auto\\Helper';
        $this->assertTrue(class_exists($class));
        $this->assertSame('pong', call_user_func([$class, 'ping']));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
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
