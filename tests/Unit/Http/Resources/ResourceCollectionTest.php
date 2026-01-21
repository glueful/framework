<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http\Resources;

use Glueful\Http\Resources\AnonymousResourceCollection;
use Glueful\Http\Resources\JsonResource;
use Glueful\Http\Resources\ResourceCollection;
use Glueful\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ResourceCollection
 */
class ResourceCollectionTest extends TestCase
{
    public function testMakeCreatesCollection(): void
    {
        $items = [['name' => 'John'], ['name' => 'Jane']];
        $collection = ResourceCollection::make($items);

        $this->assertInstanceOf(ResourceCollection::class, $collection);
        $this->assertCount(2, $collection);
    }

    public function testCountReturnsItemCount(): void
    {
        $items = [['name' => 'John'], ['name' => 'Jane'], ['name' => 'Bob']];
        $collection = ResourceCollection::make($items);

        $this->assertEquals(3, $collection->count());
    }

    public function testIsEmptyReturnsTrueForEmptyCollection(): void
    {
        $collection = ResourceCollection::make([]);

        $this->assertTrue($collection->isEmpty());
        $this->assertFalse($collection->isNotEmpty());
    }

    public function testIsNotEmptyReturnsTrueForNonEmptyCollection(): void
    {
        $collection = ResourceCollection::make([['name' => 'John']]);

        $this->assertFalse($collection->isEmpty());
        $this->assertTrue($collection->isNotEmpty());
    }

    public function testResolveReturnsArrayOfResolvedResources(): void
    {
        $items = [['name' => 'John'], ['name' => 'Jane']];
        $collection = ResourceCollection::make($items);

        $resolved = $collection->resolve();

        $this->assertIsArray($resolved);
        $this->assertCount(2, $resolved);
        $this->assertEquals('John', $resolved[0]['name']);
        $this->assertEquals('Jane', $resolved[1]['name']);
    }

    public function testToResponseCreatesResponse(): void
    {
        $items = [['name' => 'John']];
        $collection = ResourceCollection::make($items);

        $response = $collection->toResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testToResponseIncludesData(): void
    {
        $items = [['name' => 'John'], ['name' => 'Jane']];
        $collection = ResourceCollection::make($items);

        $response = $collection->toResponse();
        $content = json_decode($response->getContent(), true);

        $this->assertTrue($content['success']);
        $this->assertArrayHasKey('data', $content);
        $this->assertCount(2, $content['data']);
    }

    public function testWithPaginationAddsPaginationData(): void
    {
        $items = [['name' => 'John']];
        $collection = ResourceCollection::make($items)
            ->withPagination([
                'current_page' => 1,
                'per_page' => 15,
                'total' => 100,
                'total_pages' => 7,
            ]);

        $response = $collection->toResponse();
        $content = json_decode($response->getContent(), true);

        $this->assertEquals(1, $content['current_page']);
        $this->assertEquals(15, $content['per_page']);
        $this->assertEquals(100, $content['total']);
        $this->assertEquals(7, $content['total_pages']);
    }

    public function testWithPaginationFromExtractsPagination(): void
    {
        $items = [['name' => 'John']];
        $paginationResult = [
            'data' => $items,
            'meta' => [
                'current_page' => 2,
                'per_page' => 25,
                'total' => 50,
                'total_pages' => 2,
                'has_next_page' => false,
                'has_previous_page' => true,
            ],
        ];

        $collection = ResourceCollection::make($items)
            ->withPaginationFrom($paginationResult);

        $response = $collection->toResponse();
        $content = json_decode($response->getContent(), true);

        $this->assertEquals(2, $content['current_page']);
        $this->assertEquals(25, $content['per_page']);
        $this->assertFalse($content['has_next_page']);
        $this->assertTrue($content['has_previous_page']);
    }

    public function testAdditionalDataIsMerged(): void
    {
        $items = [['name' => 'John']];
        $collection = ResourceCollection::make($items)
            ->additional(['meta' => ['version' => '1.0']]);

        $response = $collection->toResponse();
        $content = json_decode($response->getContent(), true);

        $this->assertEquals(['version' => '1.0'], $content['meta']);
    }

    public function testIteratorReturnsResources(): void
    {
        $items = [['name' => 'John'], ['name' => 'Jane']];
        $collection = ResourceCollection::make($items);

        $count = 0;
        foreach ($collection as $resource) {
            $this->assertInstanceOf(JsonResource::class, $resource);
            $count++;
        }

        $this->assertEquals(2, $count);
    }

    public function testJsonSerializeReturnsResolvedData(): void
    {
        $items = [['name' => 'John']];
        $collection = ResourceCollection::make($items);

        $json = json_encode($collection);
        $decoded = json_decode($json, true);

        $this->assertEquals([['name' => 'John']], $decoded);
    }

    public function testToJsonReturnsJsonString(): void
    {
        $items = [['name' => 'John']];
        $collection = ResourceCollection::make($items);

        $json = $collection->toJson();

        $this->assertEquals('[{"name":"John"}]', $json);
    }
}

/**
 * Tests for AnonymousResourceCollection
 */
class AnonymousResourceCollectionTest extends TestCase
{
    public function testCreatedFromJsonResourceCollection(): void
    {
        $items = [['name' => 'John'], ['name' => 'Jane']];
        $collection = JsonResource::collection($items);

        $this->assertInstanceOf(AnonymousResourceCollection::class, $collection);
        $this->assertCount(2, $collection);
    }

    public function testUsesSpecifiedResourceClass(): void
    {
        $items = [['name' => 'John']];
        $collection = new AnonymousResourceCollection($items, TestUserResource::class);

        $resolved = $collection->resolve();

        // TestUserResource adds 'transformed' => true
        $this->assertTrue($resolved[0]['transformed']);
    }

    public function testResolvesWithCustomResourceClass(): void
    {
        $items = [
            ['name' => 'John', 'email' => 'john@example.com'],
            ['name' => 'Jane', 'email' => 'jane@example.com'],
        ];

        $collection = TestUserResource::collection($items);
        $resolved = $collection->resolve();

        $this->assertCount(2, $resolved);
        $this->assertTrue($resolved[0]['transformed']);
        $this->assertTrue($resolved[1]['transformed']);
    }
}

/**
 * Test resource class
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class TestUserResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'name' => $this->resource['name'],
            'email' => $this->resource['email'] ?? null,
            'transformed' => true,
        ];
    }
}
