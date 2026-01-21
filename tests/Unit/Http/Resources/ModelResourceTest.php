<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http\Resources;

use Glueful\Http\Resources\AnonymousResourceCollection;
use Glueful\Http\Resources\JsonResource;
use Glueful\Http\Resources\ModelResource;
use Glueful\Http\Resources\ResourceCollection;
use Glueful\Http\Resources\Support\MissingValue;
use Glueful\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ModelResource ORM integration
 */
class ModelResourceTest extends TestCase
{
    public function testModelResourceExtendsJsonResource(): void
    {
        $resource = new TestUserModelResource(['name' => 'John']);

        $this->assertInstanceOf(JsonResource::class, $resource);
        $this->assertInstanceOf(ModelResource::class, $resource);
    }

    public function testWhenLoadedWithOrmModel(): void
    {
        // Create a mock ORM model with relationLoaded and getRelation methods
        $model = new MockOrmModel([
            'name' => 'John',
            'email' => 'john@example.com',
        ]);
        $model->setRelation('posts', [
            ['title' => 'Post 1'],
            ['title' => 'Post 2'],
        ]);

        $resource = new TestUserModelResource($model);
        $resolved = $resource->resolve();

        $this->assertEquals('John', $resolved['name']);
        $this->assertArrayHasKey('posts', $resolved);
        $this->assertCount(2, $resolved['posts']);
    }

    public function testWhenLoadedExcludesUnloadedRelations(): void
    {
        $model = new MockOrmModel([
            'name' => 'John',
            'email' => 'john@example.com',
        ]);
        // Don't set posts relation - it should be excluded

        $resource = new TestUserModelResource($model);
        $resolved = $resource->resolve();

        $this->assertEquals('John', $resolved['name']);
        $this->assertArrayNotHasKey('posts', $resolved);
    }

    public function testWhenCountedWithOrmModel(): void
    {
        $model = new MockOrmModel([
            'name' => 'John',
            'posts_count' => 5,
        ]);

        $resource = new TestUserModelResource($model);
        $resolved = $resource->resolve();

        $this->assertEquals(5, $resolved['posts_count']);
    }

    public function testWhenCountedExcludesWhenNotLoaded(): void
    {
        $model = new MockOrmModel([
            'name' => 'John',
        ]);

        $resource = new TestUserModelResource($model);
        $resolved = $resource->resolve();

        $this->assertArrayNotHasKey('posts_count', $resolved);
    }

    public function testDateAttributeFormatting(): void
    {
        $model = new MockOrmModel([
            'name' => 'John',
            'created_at' => '2024-01-15 10:30:00',
        ]);

        $resource = new TestUserModelResource($model);
        $resolved = $resource->resolve();

        $this->assertStringContainsString('2024-01-15', $resolved['created_at']);
    }

    public function testCollectionHandlesOrmCollection(): void
    {
        $items = [
            new MockOrmModel(['name' => 'John']),
            new MockOrmModel(['name' => 'Jane']),
        ];

        // Simulate ORM Collection
        $collection = new MockOrmCollection($items);

        $resourceCollection = TestUserModelResource::collection($collection);

        $this->assertInstanceOf(AnonymousResourceCollection::class, $resourceCollection);
        $this->assertCount(2, $resourceCollection);
    }

    public function testAttributeAccessWithDefault(): void
    {
        $model = new MockOrmModel([
            'name' => 'John',
        ]);

        $resource = new TestUserModelResourceWithDefaults($model);
        $resolved = $resource->resolve();

        $this->assertEquals('John', $resolved['name']);
        $this->assertEquals('default@example.com', $resolved['email']);
    }

    public function testHasAttributeCheck(): void
    {
        $model = new MockOrmModel([
            'name' => 'John',
        ]);

        $resource = new TestUserModelResourceWithAttributeCheck($model);
        $resolved = $resource->resolve();

        $this->assertEquals('John', $resolved['name']);
        $this->assertTrue($resolved['has_name']);
        $this->assertFalse($resolved['has_email']);
    }

    public function testIsRelationLoaded(): void
    {
        $model = new MockOrmModel(['name' => 'John']);
        $model->setRelation('posts', [['title' => 'Post']]);

        $resource = new TestUserModelResourceWithRelationCheck($model);
        $resolved = $resource->resolve();

        $this->assertTrue($resolved['posts_loaded']);
        $this->assertFalse($resolved['comments_loaded']);
    }

    public function testRelationshipResource(): void
    {
        $profile = new MockOrmModel([
            'bio' => 'Software developer',
            'website' => 'https://example.com',
        ]);

        $model = new MockOrmModel(['name' => 'John']);
        $model->setRelation('profile', $profile);

        $resource = new TestUserModelResourceWithProfile($model);
        $resolved = $resource->resolve();

        $this->assertEquals('John', $resolved['name']);
        $this->assertArrayHasKey('profile', $resolved);
        $this->assertEquals('Software developer', $resolved['profile']['bio']);
    }

    public function testToResponseCreatesResponse(): void
    {
        $model = new MockOrmModel([
            'name' => 'John',
            'email' => 'john@example.com',
        ]);

        $resource = new TestUserModelResource($model);
        $response = $resource->toResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }
}

/**
 * Mock ORM Model for testing
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class MockOrmModel
{
    protected array $attributes = [];
    protected array $relations = [];

    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function toArray(): array
    {
        return array_merge($this->attributes, $this->relationsToArray());
    }

    public function relationLoaded(string $key): bool
    {
        return array_key_exists($key, $this->relations);
    }

    public function getRelation(string $key): mixed
    {
        return $this->relations[$key] ?? null;
    }

    public function setRelation(string $key, mixed $value): self
    {
        $this->relations[$key] = $value;
        return $this;
    }

    public function getRelations(): array
    {
        return $this->relations;
    }

    protected function relationsToArray(): array
    {
        $result = [];
        foreach ($this->relations as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $value;
            } elseif (is_object($value) && method_exists($value, 'toArray')) {
                $result[$key] = $value->toArray();
            }
        }
        return $result;
    }

    public function __get(string $name): mixed
    {
        // First check relations
        if ($this->relationLoaded($name)) {
            return $this->getRelation($name);
        }

        // Then check attributes
        return $this->getAttribute($name);
    }

    public function __isset(string $name): bool
    {
        return $this->relationLoaded($name) || isset($this->attributes[$name]);
    }
}

/**
 * Mock ORM Collection for testing
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class MockOrmCollection implements \IteratorAggregate, \Countable
{
    protected array $items;

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }
}

/**
 * Test User Model Resource
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class TestUserModelResource extends ModelResource
{
    public function toArray(): array
    {
        return [
            'name' => $this->attribute('name'),
            'email' => $this->attribute('email'),
            'posts' => $this->whenLoaded('posts'),
            'posts_count' => $this->whenCounted('posts'),
            'created_at' => $this->dateAttribute('created_at'),
        ];
    }
}

/**
 * Test User Model Resource with defaults
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class TestUserModelResourceWithDefaults extends ModelResource
{
    public function toArray(): array
    {
        return [
            'name' => $this->attribute('name', 'Unknown'),
            'email' => $this->attribute('email', 'default@example.com'),
        ];
    }
}

/**
 * Test User Model Resource with attribute check
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class TestUserModelResourceWithAttributeCheck extends ModelResource
{
    public function toArray(): array
    {
        return [
            'name' => $this->attribute('name'),
            'has_name' => $this->hasAttribute('name'),
            'has_email' => $this->hasAttribute('email'),
        ];
    }
}

/**
 * Test User Model Resource with relation check
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class TestUserModelResourceWithRelationCheck extends ModelResource
{
    public function toArray(): array
    {
        return [
            'name' => $this->attribute('name'),
            'posts_loaded' => $this->isRelationLoaded('posts'),
            'comments_loaded' => $this->isRelationLoaded('comments'),
        ];
    }
}

/**
 * Test User Model Resource with profile
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class TestUserModelResourceWithProfile extends ModelResource
{
    public function toArray(): array
    {
        return [
            'name' => $this->attribute('name'),
            'profile' => $this->relationshipResource('profile'),
        ];
    }
}
