<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Extensions;

use Glueful\Framework;
use Glueful\Extensions\Services\ExtensionLoader;
use PHPUnit\Framework\TestCase;

final class ExtensionConflictTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/ext-conflict-' . uniqid();
        @mkdir($this->baseDir . '/config', 0755, true);
        @mkdir($this->baseDir . '/extensions/Demo/src', 0755, true);
        @mkdir($this->baseDir . '/vendor/glueful/demo-composer/src', 0755, true);
        @mkdir($this->baseDir . '/vendor/composer', 0755, true);

        // Config: allow local + switch precedence easily in test cases
        $configExt = <<<'PHP'
        <?php
        return [
          'discovery' => [ 'allow_local' => true ],
          'precedence' => [ 'conflict_resolution' => 'composer', 'log_conflicts' => false ],
        ];
        PHP;
        file_put_contents($this->baseDir . '/config/extensions.php', $configExt);
        file_put_contents(
            $this->baseDir . '/config/app.php',
            "<?php return ['env'=>'testing','debug'=>true,'version_full'=>'1.0.0'];\n"
        );

        // Local extension 'Demo'
        file_put_contents($this->baseDir . '/extensions/Demo/manifest.json', json_encode([
          'version' => '1.0.0', 'main' => 'Demo.php', 'main_class' => 'Glueful\\\\Extensions\\\\Demo'
        ], JSON_PRETTY_PRINT));
        file_put_contents(
            $this->baseDir . '/extensions/Demo/Demo.php',
            "<?php\\nnamespace Glueful\\\\Extensions;\\nclass Demo {}\\n"
        );

        // Composer extension with same extension name 'Demo'
        $installed = [ 'packages' => [[
            'name' => 'glueful/demo-composer',
            'type' => 'glueful-extension',
            'version' => '9.9.9',
            'description' => 'Composer Demo',
            'autoload' => [ 'psr-4' => [ 'Glueful\\\\ComposerDemo\\\\' => 'src' ]],
            'extra' => [ 'glueful' => [ 'extension-class' => 'Glueful\\\\ComposerDemo\\\\DemoExtension' ] ],
        ]]];
        file_put_contents(
            $this->baseDir . '/vendor/composer/installed.json',
            json_encode($installed, JSON_PRETTY_PRINT)
        );
        $demoExtensionContent = "<?php\\nnamespace Glueful\\\\ComposerDemo;\\nclass DemoExtension {}\\n";
        file_put_contents(
            $this->baseDir . '/vendor/glueful/demo-composer/src/DemoExtension.php',
            $demoExtensionContent
        );

        Framework::create($this->baseDir)->withEnvironment('testing')->boot(allowReboot: true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->baseDir);
        parent::tearDown();
    }

    public function testComposerWinsWhenPrecedenceIsComposer(): void
    {
        /** @var ExtensionLoader $loader */
        $loader = container()->get(ExtensionLoader::class);
        $found = $loader->discoverAvailableExtensions();

        $this->assertArrayHasKey('Demo', $found);
        $this->assertSame('composer', $found['Demo']['source_type']);
        $this->assertSame('glueful/demo-composer', $found['Demo']['package_name']);
    }

    public function testLocalWinsWhenPrecedenceIsLocal(): void
    {
        // Override precedence to local
        $configExt = <<<'PHP'
        <?php
        return [
          'discovery' => [ 'allow_local' => true ],
          'precedence' => [ 'conflict_resolution' => 'local' ],
        ];
        PHP;
        file_put_contents($this->baseDir . '/config/extensions.php', $configExt);
        // Reboot container with updated config
        Framework::create($this->baseDir)->withEnvironment('testing')->boot(allowReboot: true);

        /** @var ExtensionLoader $loader */
        $loader = container()->get(ExtensionLoader::class);
        $found = $loader->discoverAvailableExtensions();

        $this->assertArrayHasKey('Demo', $found);
        $this->assertSame('local', $found['Demo']['source_type']);
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
