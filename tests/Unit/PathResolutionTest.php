<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit;

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
        // Legacy DI bootstrap removed; nothing to reset

        // Reset path function static caches
        $this->resetPathCaches();

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
    }

    protected function tearDown(): void
    {
        // Legacy DI bootstrap removed; nothing to reset

        // Reset path function static caches
        $this->resetPathCaches();

        // Clear any globals we set
        unset($GLOBALS['base_path'], $GLOBALS['config_paths'], $GLOBALS['container']);
        $this->removeDir($this->tmpBase);
        $this->removeDir($this->tmpConfig);
        parent::tearDown();
    }

    public function testAllPathsResolveCorrectly(): void
    {
        // Set globals directly instead of using full framework boot
        $GLOBALS['base_path'] = $this->tmpBase;
        $GLOBALS['config_paths'] = [
            'application' => $this->tmpConfig,
            'framework' => dirname(__DIR__, 2) . '/config'
        ];

        $this->assertSame($this->tmpBase, base_path());
        $this->assertSame($this->tmpConfig, config_path());
        $this->assertSame($this->tmpBase . '/storage', base_path('storage'));
        $this->assertSame($this->tmpBase . '/resources', resource_path());
    }

    public function testStoragePathCreatesParentDirectories(): void
    {
        // Set globals directly instead of using full framework boot
        $GLOBALS['base_path'] = $this->tmpBase;
        $GLOBALS['config_paths'] = [
            'application' => $this->tmpConfig,
            'framework' => dirname(__DIR__, 2) . '/config'
        ];

        $target = storage_path('cache/demo/test.txt');
        // Note: storage_path() creates parent directories as a convenience feature

        // storage_path should create parent dirs when writing
        file_put_contents($target, 'ok');
        $this->assertFileExists($target);
        $this->assertSame('ok', file_get_contents($target));
    }

    private function resetPathCaches(): void
    {
        // Clear globals that path functions check
        unset($GLOBALS['base_path'], $GLOBALS['config_paths'], $GLOBALS['container']);

        // Reset static variables in path functions
        if (function_exists('base_path')) {
            base_path('__RESET__');
        }
        if (function_exists('config_path')) {
            config_path('__RESET__');
        }
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
