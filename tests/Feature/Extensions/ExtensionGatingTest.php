<?php

declare(strict_types=1);

namespace Glueful\Tests\Feature\Extensions;

use Glueful\Framework;
use Glueful\Extensions\Services\ExtensionLoader;
use PHPUnit\Framework\TestCase;

final class ExtensionGatingTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/ext-gating-' . uniqid();
        @mkdir($this->baseDir . '/config', 0755, true);
        @mkdir($this->baseDir . '/extensions/Block/src', 0755, true);

        // Config with allow_local toggled off
        $configExt = <<<'PHP'
        <?php
        return [
          'discovery' => [ 'allow_local' => false ],
          'precedence' => [ 'conflict_resolution' => 'composer', 'log_conflicts' => false ],
        ];
        PHP;
        file_put_contents($this->baseDir . '/config/extensions.php', $configExt);
        file_put_contents(
            $this->baseDir . '/config/app.php',
            "<?php return ['env'=>'testing','debug'=>true,'version_full'=>'1.0.0'];\n"
        );

        // Local extension present
        file_put_contents($this->baseDir . '/extensions/Block/manifest.json', json_encode([
            'version' => '1.0.0', 'main' => 'Block.php', 'main_class' => 'Glueful\\\\Extensions\\\\Block'
        ], JSON_PRETTY_PRINT));
        $blockContent = "<?php\\nnamespace Glueful\\\\Extensions;\\nclass Block {}\\n";
        file_put_contents($this->baseDir . '/extensions/Block/Block.php', $blockContent);

        Framework::create($this->baseDir)->withEnvironment('testing')->boot(allowReboot: true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->baseDir);
        parent::tearDown();
    }

    public function testLocalExtensionsAreNotDiscoveredWhenDisabled(): void
    {
        /** @var ExtensionLoader $loader */
        $loader = container()->get(ExtensionLoader::class);
        $found = $loader->discoverAvailableExtensions();

        $this->assertArrayNotHasKey('Block', $found, 'Local extension should be gated off by config');
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
