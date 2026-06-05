<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Support\Documentation\CommentsDocGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Covers route-parameter extraction in CommentsDocGenerator: the legacy positional
 * `@param` form, the editor-clean `@queryParam name:type="..."` form, and the
 * auto-derive/merge of URL path parameters.
 */
final class CommentsDocGeneratorParamTest extends TestCase
{
    /**
     * Invoke the private, state-free parser directly (no DI bootstrap needed).
     *
     * @return array<int, array<string, mixed>>
     */
    private function parse(string $docComment, string $routePath = ''): array
    {
        $generator = (new \ReflectionClass(CommentsDocGenerator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($generator, 'extractSimplifiedParameters');
        $method->setAccessible(true);

        /** @var array<int, array<string, mixed>> $result */
        $result = $method->invoke($generator, $docComment, $routePath);
        return $result;
    }

    public function testParsesQueryParamTag(): void
    {
        $params = $this->parse('/** @queryParam ttl:integer="URL lifetime" */', '/blobs/signed-url');

        self::assertCount(1, $params);
        self::assertSame('ttl', $params[0]['name']);
        self::assertSame('query', $params[0]['in']);
        self::assertFalse($params[0]['required']);
        self::assertSame('URL lifetime', $params[0]['description']);
        self::assertSame(['type' => 'integer'], $params[0]['schema']);
    }

    public function testQueryParamRequiredMarkerSetsRequiredTrue(): void
    {
        $params = $this->parse('/** @queryParam q:string="Search" {required} */');

        self::assertSame('q', $params[0]['name']);
        self::assertTrue($params[0]['required']);
    }

    public function testLegacyPositionalParamStyleStillParses(): void
    {
        $params = $this->parse('/** @param page query integer false "Page number" */');

        self::assertSame('page', $params[0]['name']);
        self::assertSame('query', $params[0]['in']);
        self::assertFalse($params[0]['required']);
        self::assertSame('Page number', $params[0]['description']);
        self::assertSame(['type' => 'integer'], $params[0]['schema']);
    }

    public function testAutoDerivesPathParamAlongsideQueryParam(): void
    {
        // A route with a query param AND a {uuid} in the URL: the path param must still appear.
        $params = $this->parse('/** @queryParam page:integer="Page" */', '/rbac/users/{uuid}/roles');

        $names = array_column($params, 'name');
        self::assertContains('page', $names);
        self::assertContains('uuid', $names);

        $idx = array_search('uuid', $names, true);
        self::assertNotFalse($idx);
        self::assertSame('path', $params[$idx]['in']);
        self::assertTrue($params[$idx]['required']);
    }

    public function testExplicitPathParamIsNotDuplicatedByAutoDerive(): void
    {
        $params = $this->parse('/** @param uuid path string true "Role UUID" */', '/rbac/roles/{uuid}');

        self::assertSame(['uuid'], array_column($params, 'name'), 'path param documented once, no duplicate');
        self::assertSame('Role UUID', $params[0]['description']);
    }
}
