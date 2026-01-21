<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http\Resources;

use Glueful\Http\Resources\JsonResource;
use Glueful\Http\Resources\ResourceCollection;
use Glueful\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JsonResource
 */
class JsonResourceTest extends TestCase
{
    public function testMakeCreatesResourceInstance(): void
    {
        $data = ['name' => 'John', 'email' => 'john@example.com'];
        $resource = JsonResource::make($data);

        $this->assertInstanceOf(JsonResource::class, $resource);
        $this->assertSame($data, $resource->resource);
    }

    public function testToArrayReturnsResourceData(): void
    {
        $data = ['name' => 'John', 'email' => 'john@example.com'];
        $resource = JsonResource::make($data);

        $this->assertEquals($data, $resource->toArray());
    }

    public function testResolveFiltersData(): void
    {
        $data = ['name' => 'John', 'email' => 'john@example.com'];
        $resource = JsonResource::make($data);

        $this->assertEquals($data, $resource->resolve());
    }

    public function testToResponseCreatesResponse(): void
    {
        $data = ['name' => 'John'];
        $resource = JsonResource::make($data);

        $response = $resource->toResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testToResponseWrapsData(): void
    {
        $data = ['name' => 'John'];
        $resource = JsonResource::make($data);

        $response = $resource->toResponse();
        $content = json_decode($response->getContent(), true);

        $this->assertTrue($content['success']);
        $this->assertEquals($data, $content['data']);
    }

    public function testWithoutWrappingDisablesWrapper(): void
    {
        JsonResource::withoutWrapping();

        $data = ['name' => 'John'];
        $resource = JsonResource::make($data);

        $response = $resource->toResponse();
        $content = json_decode($response->getContent(), true);

        $this->assertTrue($content['success']);
        $this->assertEquals('John', $content['name']);
        $this->assertArrayNotHasKey('data', $content);

        // Reset wrapping
        JsonResource::wrap('data');
    }

    public function testCustomWrapperKey(): void
    {
        JsonResource::wrap('user');

        $data = ['name' => 'John'];
        $resource = JsonResource::make($data);

        $response = $resource->toResponse();
        $content = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('user', $content);
        $this->assertEquals($data, $content['user']);

        // Reset wrapping
        JsonResource::wrap('data');
    }

    public function testAdditionalDataIsMerged(): void
    {
        $data = ['name' => 'John'];
        $resource = JsonResource::make($data)
            ->additional(['meta' => ['version' => '1.0']]);

        $response = $resource->toResponse();
        $content = json_decode($response->getContent(), true);

        $this->assertEquals(['version' => '1.0'], $content['meta']);
    }

    public function testCollectionCreatesResourceCollection(): void
    {
        $items = [
            ['name' => 'John'],
            ['name' => 'Jane'],
        ];

        $collection = JsonResource::collection($items);

        $this->assertInstanceOf(ResourceCollection::class, $collection);
        $this->assertCount(2, $collection);
    }

    public function testPropertyDelegation(): void
    {
        $data = ['name' => 'John', 'email' => 'john@example.com'];
        $resource = JsonResource::make($data);

        $this->assertEquals('John', $resource->name);
        $this->assertEquals('john@example.com', $resource->email);
        $this->assertNull($resource->nonexistent);
    }

    public function testArrayAccess(): void
    {
        $data = ['name' => 'John', 'email' => 'john@example.com'];
        $resource = JsonResource::make($data);

        $this->assertTrue(isset($resource['name']));
        $this->assertEquals('John', $resource['name']);
        $this->assertFalse(isset($resource['nonexistent']));
    }

    public function testJsonSerializable(): void
    {
        $data = ['name' => 'John'];
        $resource = JsonResource::make($data);

        $json = json_encode($resource);
        $decoded = json_decode($json, true);

        $this->assertEquals($data, $decoded);
    }

    public function testToJsonReturnsJsonString(): void
    {
        $data = ['name' => 'John'];
        $resource = JsonResource::make($data);

        $json = $resource->toJson();

        $this->assertEquals('{"name":"John"}', $json);
    }

    public function testNullResourceReturnsEmptyArray(): void
    {
        $resource = JsonResource::make(null);

        $this->assertEquals([], $resource->toArray());
    }

    public function testObjectResourceWithToArrayMethod(): void
    {
        $object = new class {
            public function toArray(): array
            {
                return ['name' => 'John', 'email' => 'john@example.com'];
            }
        };

        $resource = JsonResource::make($object);

        $this->assertEquals(['name' => 'John', 'email' => 'john@example.com'], $resource->toArray());
    }

    public function testNestedResourcesAreResolved(): void
    {
        $innerData = ['title' => 'Post 1'];
        $innerResource = JsonResource::make($innerData);

        $resource = new class(['name' => 'John', 'post' => $innerResource]) extends JsonResource {
            public function toArray(): array
            {
                return [
                    'name' => $this->resource['name'],
                    'post' => $this->resource['post'],
                ];
            }
        };

        $resolved = $resource->resolve();

        $this->assertEquals('John', $resolved['name']);
        $this->assertEquals(['title' => 'Post 1'], $resolved['post']);
    }
}
