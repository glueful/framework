<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Support\Documentation\DocGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Covers Phase-0 Fix 1: merge only THIS run's fragment files, never a directory
 * glob — so a stale fragment sitting in the same directory is not picked up.
 */
final class DocGeneratorMergeListTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/doc_merge_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function testGenerateFromRouteFilesMergesOnlyGivenFiles(): void
    {
        $current = $this->writeFragment('current.json', '/current', 'CurrentRoutes');
        $stale = $this->writeFragment('stale.json', '/stale', 'StaleRoutes');

        $generator = new DocGenerator(openApiVersion: '3.1.0');
        // Merge only the current-run fragment; the stale one sits in the same dir.
        $generator->generateFromRouteFiles([$current]);

        $spec = json_decode($generator->getSwaggerJson(), true);
        self::assertIsArray($spec);

        self::assertArrayHasKey('/current', $spec['paths']);
        self::assertArrayNotHasKey('/stale', $spec['paths'], 'Stale fragment must not be merged');

        // Sanity: the stale file is genuinely a valid fragment in the same dir.
        self::assertFileExists($stale);
    }

    public function testGenerateFromExtensionFilesMergesOnlyGivenFiles(): void
    {
        // Extension fragments live under {dir}/{extName}/{extName}.json; the
        // extension name (schema prefix) is derived from the parent directory.
        $currentDir = $this->tmpDir . '/aegis';
        $staleDir = $this->tmpDir . '/legacy';
        mkdir($currentDir, 0755, true);
        mkdir($staleDir, 0755, true);

        $current = $currentDir . '/aegis.json';
        file_put_contents($current, $this->fragmentJson('/aegis/login', 'AegisTag'));
        $stale = $staleDir . '/legacy.json';
        file_put_contents($stale, $this->fragmentJson('/legacy/thing', 'LegacyTag'));

        $generator = new DocGenerator(openApiVersion: '3.1.0');
        $generator->generateFromExtensionFiles([$current]);

        $spec = json_decode($generator->getSwaggerJson(), true);
        self::assertIsArray($spec);
        self::assertArrayHasKey('/aegis/login', $spec['paths']);
        self::assertArrayNotHasKey('/legacy/thing', $spec['paths']);
    }

    public function testMissingFilesAreSkippedSilently(): void
    {
        $generator = new DocGenerator(openApiVersion: '3.1.0');
        // Should not throw on a non-existent path.
        $generator->generateFromRouteFiles([$this->tmpDir . '/does-not-exist.json']);
        $generator->generateFromExtensionFiles([$this->tmpDir . '/nope/nope.json']);

        $spec = json_decode($generator->getSwaggerJson(), true);
        self::assertIsArray($spec);
    }

    private function writeFragment(string $name, string $path, string $tag): string
    {
        $file = $this->tmpDir . '/' . $name;
        file_put_contents($file, $this->fragmentJson($path, $tag));
        return $file;
    }

    private function fragmentJson(string $path, string $tag): string
    {
        return (string) json_encode([
            'openapi' => '3.0.0',
            'paths' => [
                $path => [
                    'get' => [
                        'tags' => [$tag],
                        'summary' => 'Test op',
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ]);
    }
}
