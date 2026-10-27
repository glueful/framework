<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Generate;

use Glueful\Console\Commands\Generate\OpenApiDocsCommand;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;

/**
 * --clean removes the generated fragment directories (routes/*.json and
 * extensions/<ext>/*.json) and never the hand-authored fragments beside them.
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

    public function testCleanDefinitionsRemovesGeneratedOutputButKeepsHandAuthoredFragments(): void
    {
        $routeFragment = $this->root . '/routes/foo.json';
        $extFragment = $this->root . '/extensions/bar/bar.json';
        $handAuthored = $this->root . '/custom.json';
        mkdir($this->root . '/shared', 0755, true);
        $handAuthoredNested = $this->root . '/shared/schemas.json';

        file_put_contents($routeFragment, '{}');
        file_put_contents($extFragment, '{}');
        file_put_contents($handAuthored, '{}');
        file_put_contents($handAuthoredNested, '{}');

        [$dirCount, $fileCount] = OpenApiDocsCommand::cleanGeneratedDefinitions(
            $this->root,
            new FileFinder()
        );

        // The (legacy) generator output under routes/ and extensions/ is gone ...
        self::assertDirectoryDoesNotExist($this->root . '/routes');
        self::assertDirectoryDoesNotExist($this->root . '/extensions');
        // ... while hand-authored fragments the generator merges are kept.
        self::assertFileExists($handAuthored);
        self::assertFileExists($handAuthoredNested);

        self::assertSame(2, $fileCount);
        // routes, extensions/bar, extensions
        self::assertSame(3, $dirCount);
    }

    public function testCleanDefinitionsIsNoOpWhenPathMissing(): void
    {
        self::assertSame([0, 0], OpenApiDocsCommand::cleanGeneratedDefinitions(
            $this->root . '/missing',
            new FileFinder()
        ));
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
