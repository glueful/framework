<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Generate;

use Glueful\Console\Commands\Generate\OpenApiDocsCommand;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;

/**
 * Covers Phase-0 Fix 3: --clean recursively removes nested fragment files
 * (routes/*.json and extensions/<ext>/*.json) and their now-empty subdirs.
 */
final class OpenApiDocsCleanTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/openapi_clean_' . uniqid();
        mkdir($this->root . '/routes', 0755, true);
        mkdir($this->root . '/extensions/bar', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    public function testCleanRemovesNestedFragmentsAndSubdirs(): void
    {
        $routeFragment = $this->root . '/routes/foo.json';
        $extFragment = $this->root . '/extensions/bar/bar.json';
        $rootFragment = $this->root . '/custom.json';

        file_put_contents($routeFragment, '{}');
        file_put_contents($extFragment, '{}');
        file_put_contents($rootFragment, '{}');

        self::assertFileExists($routeFragment);
        self::assertFileExists($extFragment);

        [$dirCount, $fileCount] = OpenApiDocsCommand::cleanDirectoryRecursively(
            $this->root,
            new FileFinder()
        );

        // All three files gone, including the nested ones.
        self::assertFileDoesNotExist($routeFragment);
        self::assertFileDoesNotExist($extFragment);
        self::assertFileDoesNotExist($rootFragment);

        // Subdirectories removed too.
        self::assertDirectoryDoesNotExist($this->root . '/routes');
        self::assertDirectoryDoesNotExist($this->root . '/extensions/bar');
        self::assertDirectoryDoesNotExist($this->root . '/extensions');

        self::assertSame(3, $fileCount);
        // routes, extensions/bar, extensions => 3 directories.
        self::assertSame(3, $dirCount);
    }

    public function testCleanIsNoOpWhenPathMissing(): void
    {
        [$dirCount, $fileCount] = OpenApiDocsCommand::cleanDirectoryRecursively(
            $this->root . '/missing',
            new FileFinder()
        );
        self::assertSame(0, $dirCount);
        self::assertSame(0, $fileCount);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
