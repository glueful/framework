<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Support\Documentation\DocGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Proves OpenAPI `paths` serialization is canonical and insertion-order-independent:
 * lexicographic path keys, and within each path item, HTTP method operations ordered
 * get, put, post, delete, options, head, patch, trace, followed by non-operation keys
 * (parameters, $ref, summary, …) lexicographically. Feeds the same operations to
 * mergePaths() in two different insertion orders and asserts byte-identical output —
 * both the raw JSON string and the decoded `paths` array — under OpenAPI 3.0 and 3.1.
 */
final class DocGeneratorPathOrderingTest extends TestCase
{
    /**
     * Same set of paths/operations, two different insertion orders. Path keys are
     * intentionally out of lexicographic order and, within each path item, verbs
     * are shuffled and interleaved with a non-operation `parameters` key.
     *
     * @return list<array<string, mixed>>
     */
    private function shuffledOrderings(): array
    {
        $usersGet = [
            'tags' => ['Users'],
            'summary' => 'List users',
            'responses' => ['200' => ['description' => 'OK']],
        ];
        $usersPost = [
            'tags' => ['Users'],
            'summary' => 'Create user',
            'responses' => ['201' => ['description' => 'Created']],
        ];
        $usersDelete = [
            'tags' => ['Users'],
            'summary' => 'Delete user',
            'responses' => ['200' => ['description' => 'OK']],
        ];
        $healthGet = [
            'tags' => ['Health'],
            'summary' => 'Health check',
            'responses' => ['200' => ['description' => 'OK']],
        ];
        $ordersPut = [
            'tags' => ['Orders'],
            'summary' => 'Replace order',
            'responses' => ['200' => ['description' => 'OK']],
        ];
        $ordersGet = [
            'tags' => ['Orders'],
            'summary' => 'Get order',
            'responses' => ['200' => ['description' => 'OK']],
        ];
        $params = [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]];

        $orderingA = [
            '/users' => ['post' => $usersPost, 'get' => $usersGet],
            '/health' => ['get' => $healthGet],
            '/orders/{id}' => ['parameters' => $params, 'put' => $ordersPut, 'get' => $ordersGet],
            '/users/{id}' => ['delete' => $usersDelete],
        ];

        $orderingB = [
            '/orders/{id}' => ['get' => $ordersGet, 'put' => $ordersPut, 'parameters' => $params],
            '/users/{id}' => ['delete' => $usersDelete],
            '/users' => ['get' => $usersGet, 'post' => $usersPost],
            '/health' => ['get' => $healthGet],
        ];

        return [$orderingA, $orderingB];
    }

    /**
     * @dataProvider openApiVersionProvider
     */
    public function testShuffledMergeOrderProducesByteIdenticalOutput(string $openApiVersion): void
    {
        [$orderingA, $orderingB] = $this->shuffledOrderings();

        $generatorA = new DocGenerator(openApiVersion: $openApiVersion);
        $generatorA->mergePaths($orderingA);
        $jsonA = $generatorA->getSwaggerJson();

        $generatorB = new DocGenerator(openApiVersion: $openApiVersion);
        $generatorB->mergePaths($orderingB);
        $jsonB = $generatorB->getSwaggerJson();

        self::assertSame($jsonA, $jsonB, 'full serialized spec must be byte-identical regardless of merge order');

        $decodedA = json_decode($jsonA, true);
        $decodedB = json_decode($jsonB, true);
        self::assertIsArray($decodedA);
        self::assertIsArray($decodedB);
        self::assertSame(
            $decodedA['paths'],
            $decodedB['paths'],
            'decoded paths must be identical regardless of merge order'
        );

        // Canonical ordering assertions on the decoded structure itself.
        self::assertSame(
            ['/health', '/orders/{id}', '/users', '/users/{id}'],
            array_keys($decodedA['paths']),
            'path keys must be sorted lexicographically'
        );
        self::assertSame(
            ['get', 'put', 'parameters'],
            array_keys($decodedA['paths']['/orders/{id}']),
            'operations ordered get,put,... come before non-operation keys, which are lexicographic'
        );
        self::assertSame(
            ['get', 'post'],
            array_keys($decodedA['paths']['/users']),
            'get precedes post per the canonical method order'
        );
    }

    /** @return array<string, array{0: string}> */
    public static function openApiVersionProvider(): array
    {
        return [
            'openapi 3.0' => ['3.0.3'],
            'openapi 3.1' => ['3.1.0'],
        ];
    }
}
