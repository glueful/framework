<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit;

use Glueful\Framework;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PathResolutionTest extends TestCase
{
    private string $tmpBase = '';
    private string $tmpConfig = '';

    protected function setUp(): void
    {
        parent::setUp();
        \Glueful\DI\ContainerBootstrap::reset();
        $uniq = uniqid('glueful_test_', true);
        $this->tmpBase = sys_get_temp_dir() . '/' . $uniq . '_base';
        $this->tmpConfig = sys_get_temp_dir() . '/' . $uniq . '_config';
        @mkdir($this->tmpBase, 0755, true);
        @mkdir($this->tmpConfig, 0755, true);
    }

    protected function tearDown(): void
    {
        \Glueful\DI\ContainerBootstrap::reset();
        $this->removeDir($this->tmpBase);
        $this->removeDir($this->tmpConfig);
        parent::tearDown();
    }

    public function testAllPathsResolveCorrectly(): void
    {
        $framework = Framework::create($this->tmpBase)
            ->withConfigDir($this->tmpConfig)
            ->withEnvironment('development');

        $framework->boot(allowReboot: true);

        $this->assertSame($this->tmpBase, base_path());
        $this->assertSame($this->tmpConfig, config_path());
        $this->assertSame($this->tmpBase . '/storage', base_path('storage'));
        $this->assertSame($this->tmpBase . '/resources', resource_path());
    }

    public function testStoragePathCreatesParentDirectories(): void
    {
        $framework = Framework::create($this->tmpBase)
            ->withConfigDir($this->tmpConfig)
            ->withEnvironment('development');

        $framework->boot(allowReboot: true);

        $target = storage_path('cache/demo/test.txt');
        $dir = dirname($target);
        $this->assertDirectoryDoesNotExist($dir, 'Precondition: directory should not exist');

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
