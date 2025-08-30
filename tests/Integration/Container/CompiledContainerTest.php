<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Container;

use Glueful\DI\ContainerBootstrap;
use Glueful\Framework;
use PHPUnit\Framework\TestCase;

final class CompiledContainerTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/container-compiled-' . uniqid();
        @mkdir($this->baseDir . '/config', 0755, true);

        // Minimal production config
        file_put_contents(
            $this->baseDir . '/config/app.php',
            "<?php return ['env'=>'production','debug'=>false,'version_full'=>'1.0.0'];\n"
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->baseDir);
        ContainerBootstrap::reset();
        parent::tearDown();
    }

    public function testCompiledContainerCreatedAndUsed(): void
    {
        Framework::create($this->baseDir)->withEnvironment('production')->boot(allowReboot: true);

        // Assert compiled container file exists
        $glob = glob($this->baseDir . '/storage/container/container_production_*.php') ?: [];
        $this->assertNotEmpty($glob, 'Compiled container file not found');

        // Assert compiled container is used (not a ContainerBuilder)
        $container = ContainerBootstrap::getContainer();
        $this->assertNotNull($container);
        $this->assertTrue($container->isCompiled());
    }

    public function testCompiledContainerInvalidatesOnConfigChange(): void
    {
        Framework::create($this->baseDir)->withEnvironment('production')->boot(allowReboot: true);
        $first = $this->currentCompiledFile();
        $this->assertNotNull($first, 'Expected initial compiled container file');

        // Touch config to change hash
        file_put_contents(
            $this->baseDir . '/config/app.php',
            "<?php return ['env'=>'production','debug'=>false,'version_full'=>'1.0.1'];\n"
        );

        // Reset and boot again to pick up change
        ContainerBootstrap::reset();
        Framework::create($this->baseDir)->withEnvironment('production')->boot(allowReboot: true);
        $second = $this->currentCompiledFile();

        $this->assertNotNull($second, 'Expected new compiled container file after config change');
        $this->assertNotSame($first, $second, 'Compiled container file should change after config modification');

        // Ensure container is compiled
        $container = ContainerBootstrap::getContainer();
        $this->assertTrue($container->isCompiled());
    }

    private function currentCompiledFile(): ?string
    {
        $glob = glob($this->baseDir . '/storage/container/container_production_*.php') ?: [];
        if (empty($glob)) {
            return null;
        }
        // Choose the newest file
        usort($glob, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return $glob[0];
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
