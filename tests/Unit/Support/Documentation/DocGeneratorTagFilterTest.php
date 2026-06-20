<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Support\Documentation\DocGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the tag allow/deny filter (config `documentation.options.tags.include` /
 * `.exclude`, both default empty = no filtering) applied to the assembled paths.
 */
final class DocGeneratorTagFilterTest extends TestCase
{
    /** @return array<string, mixed> */
    private function paths(): array
    {
        return [
            '/content' => [
                'get' => ['tags' => ['Content']],
                'post' => ['tags' => ['Content']],
            ],
            '/health' => [
                'get' => ['tags' => ['Health']],
            ],
            '/mixed' => [
                // path-item-level key (not an operation) — must be preserved as-is
                'parameters' => [['name' => 'id', 'in' => 'path']],
                'get' => ['tags' => ['Content', 'Internal']],
            ],
            '/untagged' => [
                'get' => [], // no tags key
            ],
        ];
    }

    public function testEmptyListsAreAPassthrough(): void
    {
        $paths = $this->paths();
        self::assertSame($paths, DocGenerator::filterPathsByTags($paths, [], []));
    }

    public function testExcludeDropsMatchingOperationsAndEmptiedPaths(): void
    {
        $out = DocGenerator::filterPathsByTags($this->paths(), [], ['Health']);

        self::assertArrayNotHasKey('/health', $out, 'a path whose only op is Health-tagged is dropped');
        self::assertArrayHasKey('/content', $out);
        self::assertArrayHasKey('/untagged', $out, 'untagged op passes an exclude-only filter');
        // /mixed keeps its Content+Internal op (Internal not excluded) and its parameters key.
        self::assertArrayHasKey('/mixed', $out);
        self::assertArrayHasKey('get', $out['/mixed']);
        self::assertArrayHasKey('parameters', $out['/mixed'], 'non-operation path-item keys are preserved');
    }

    public function testDenyWinsAndPathWithOnlyNonOpKeysIsDropped(): void
    {
        // Excluding "Internal" drops /mixed's only op, leaving just `parameters` -> path removed.
        $out = DocGenerator::filterPathsByTags($this->paths(), [], ['Internal']);

        self::assertArrayNotHasKey('/mixed', $out, 'a path left with only non-op keys is dropped');
        self::assertArrayHasKey('/content', $out);
        self::assertArrayHasKey('/health', $out);
    }

    public function testIncludeKeepsOnlyMatchingTags(): void
    {
        $out = DocGenerator::filterPathsByTags($this->paths(), ['Content'], []);

        self::assertArrayHasKey('/content', $out);
        self::assertCount(2, $out['/content'], 'both Content ops kept');
        self::assertArrayNotHasKey('/health', $out, 'Health not in the allow-list');
        self::assertArrayHasKey('/mixed', $out, '/mixed has the allowed Content tag');
        self::assertArrayHasKey('get', $out['/mixed']);
        self::assertArrayNotHasKey('/untagged', $out, 'untagged op fails a non-empty allow-list');
    }

    public function testIncludePlusExcludeDenyTakesPrecedence(): void
    {
        // /mixed's op is tagged both Content (allowed) and Internal (denied) -> denied.
        $out = DocGenerator::filterPathsByTags($this->paths(), ['Content'], ['Internal']);

        self::assertArrayNotHasKey('/mixed', $out, 'exclude wins even when an allowed tag is present');
        self::assertArrayHasKey('/content', $out);
    }

    public function testNonArrayPathItemsArePreserved(): void
    {
        $paths = ['/x' => ['get' => ['tags' => ['A']]], '/ref' => '#/paths/x'];
        $out = DocGenerator::filterPathsByTags($paths, [], ['A']);

        self::assertArrayNotHasKey('/x', $out);
        self::assertSame('#/paths/x', $out['/ref'], 'non-array path entries pass through untouched');
    }

    public function testConfigDefaultsToNoFiltering(): void
    {
        $config = require dirname(__DIR__, 4) . '/config/documentation.php';

        self::assertArrayHasKey('tags', $config['options']);
        self::assertSame([], $config['options']['tags']['include']);
        self::assertSame([], $config['options']['tags']['exclude']);
    }

    public function testGetSwaggerJsonDoesNotFilterByDefault(): void
    {
        // Null context => config returns the empty defaults => filter is a passthrough.
        $generator = new DocGenerator(openApiVersion: '3.1.0');
        $generator->mergePaths([
            '/keep' => ['get' => ['tags' => ['Health'], 'responses' => []]],
        ]);

        $spec = json_decode($generator->getSwaggerJson(), true);
        self::assertIsArray($spec);
        self::assertArrayHasKey('/keep', $spec['paths'], 'no filtering when tag lists are empty');
    }
}
