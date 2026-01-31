<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit;

use Glueful\Bootstrap\ApplicationContext;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PathResolutionTest extends TestCase
{
    private string $tmpBase = '';
    private string $tmpConfig = '';
    private ?ApplicationContext $context = null;

    protected function setUp(): void
    {
        parent::setUp();

        $uniq = uniqid('glueful_test_', true);
        $this->tmpBase = sys_get_temp_dir() . '/' . $uniq . '_base';
        $this->tmpConfig = sys_get_temp_dir() . '/' . $uniq . '_config';
        @mkdir($this->tmpBase, 0755, true);
        @mkdir($this->tmpConfig, 0755, true);

        // Create extensions directory and minimal config to prevent extension loading errors
        @mkdir($this->tmpBase . '/extensions', 0755, true);
        file_put_contents($this->tmpBase . '/extensions/extensions.json', '{}');

        // Create minimal config files to prevent bootstrap errors
        file_put_contents($this->tmpConfig . '/app.php', "<?php return ['env' => 'testing', 'debug' => true];");
        file_put_contents(
            $this->tmpConfig . '/extensions.php',
            "<?php return ['discovery' => ['allow_local' => false, 'allow_composer' => false]];"
        );

        $this->context = new ApplicationContext(
            basePath: $this->tmpBase,
            environment: 'testing',
            configPaths: [
                'application' => $this->tmpConfig,
                'framework' => dirname(__DIR__, 2) . '/config',
            ]
        );
    }

    protected function tearDown(): void
    {
        $this->context = null;
        $this->removeDir($this->tmpBase);
        $this->removeDir($this->tmpConfig);
        parent::tearDown();
    }

    public function testAllPathsResolveCorrectly(): void
    {
        $context = $this->context;
        $this->assertNotNull($context);

        $this->assertSame($this->tmpBase, base_path($context));
        $this->assertSame($this->tmpConfig, config_path($context));
        $this->assertSame($this->tmpBase . '/storage', base_path($context, 'storage'));
        $this->assertSame($this->tmpBase . '/resources', resource_path($context));
    }

    public function testStoragePathCreatesParentDirectories(): void
    {
        $context = $this->context;
        $this->assertNotNull($context);

        $target = storage_path($context, 'cache/demo/test.txt');
        // Note: storage_path() creates parent directories as a convenience feature

        // storage_path should create parent dirs when writing
        file_put_contents($target, 'ok');
        $this->assertFileExists($target);
        $this->assertSame('ok', file_get_contents($target));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($dir);
    }
}
