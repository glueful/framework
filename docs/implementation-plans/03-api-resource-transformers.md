# API Resource Transformers Implementation Plan

> A comprehensive plan for implementing JSON API resource transformers that provide consistent, flexible API responses.

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Goals and Non-Goals](#goals-and-non-goals)
3. [Current State Analysis](#current-state-analysis)
4. [Architecture Design](#architecture-design)
5. [JsonResource Class](#jsonresource-class)
6. [Resource Collections](#resource-collections)
7. [Conditional Attributes](#conditional-attributes)
8. [Relationships and Nested Resources](#relationships-and-nested-resources)
9. [Pagination](#pagination)
10. [Response Wrapping](#response-wrapping)
11. [Implementation Phases](#implementation-phases)
12. [Testing Strategy](#testing-strategy)
13. [Performance Considerations](#performance-considerations)
14. [API Reference](#api-reference)

---

## Executive Summary

This document outlines the implementation of API Resource Transformers for Glueful Framework. Resources provide:

- **Consistent API responses** across all endpoints
- **Transformation layer** between models/data and JSON output
- **Conditional attributes** that only appear when conditions are met
- **Relationship handling** with automatic eager loading detection
- **Collection support** with pagination metadata
- **Extensible design** for custom transformation logic

The implementation integrates with the existing `Response` class and (future) ORM models.

---

## Goals and Non-Goals

### Goals

- ✅ Provide consistent JSON transformation layer
- ✅ Support conditional attributes (`when`, `whenLoaded`)
- ✅ Support resource collections with pagination
- ✅ Integrate with Response class
- ✅ Work with arrays, objects, and ORM models
- ✅ Allow custom transformation logic
- ✅ Support nested resources for relationships

### Non-Goals

- ❌ Replace Response class (resources return Response)
- ❌ JSON:API or HAL specification compliance (use middleware)
- ❌ Automatic OpenAPI schema generation (separate concern)
- ❌ GraphQL-style field selection (existing FieldSelector handles this)

---

## Current State Analysis

### Existing Infrastructure

Glueful has a comprehensive response system:

```
src/Http/
├── Response.php              # Modern API Response (extends JsonResponse)
├── HttpResponse.php          # Legacy response helper
├── Pagination.php            # Pagination utilities
└── ResponseHelper.php        # Response building helpers
```

### Current Usage Pattern

```php
// Current: Manual array transformation
public function show(string $id): Response
{
    $user = $this->userRepository->find($id);

    return Response::success([
        'id' => $user['uuid'],
        'email' => $user['email'],
        'name' => $user['name'],
        'created_at' => (new DateTime($user['created_at']))->format('c'),
        // Manually check if posts should be included
        'posts' => $includesPosts ? $this->transformPosts($user['posts']) : null,
    ]);
}

// Repeated transformation logic in every controller
private function transformPosts(array $posts): array
{
    return array_map(fn($post) => [
        'id' => $post['uuid'],
        'title' => $post['title'],
    ], $posts);
}
```

### Problems with Current Approach

| Problem | Impact |
|---------|--------|
| Repeated transformation code | DRY violation |
| No conditional attributes | Over-fetching or null fields |
| Manual relationship handling | N+1 queries likely |
| Inconsistent responses | Different formats per endpoint |
| No pagination standards | Ad-hoc pagination structures |

---

## Architecture Design

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         Controller                              │
│                                                                 │
│  return UserResource::make($user);                              │
│  return UserResource::collection($users);                       │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Resource Layer                             │
│  ┌─────────────────┐  ┌──────────────────────────────────────┐  │
│  │  JsonResource   │  │      ResourceCollection              │  │
│  │  (single item)  │  │      (array of items)                │  │
│  └─────────────────┘  └──────────────────────────────────────┘  │
│           │                          │                          │
│           └──────────┬───────────────┘                          │
│                      ▼                                          │
│            ┌──────────────────┐                                 │
│            │    toArray()     │                                 │
│            │  transformation  │                                 │
│            └──────────────────┘                                 │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Response Layer                               │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │                  Glueful\Http\Response                      ││
│  │  - Wrapping (optional 'data' key)                           ││
│  │  - Pagination metadata                                      ││
│  │  - Additional data                                          ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

### Directory Structure

```
src/Http/Resources/
├── JsonResource.php                    # Base resource class
├── ResourceCollection.php              # Collection wrapper
├── AnonymousResourceCollection.php     # Anonymous collections
├── PaginatedResourceResponse.php       # Paginated response handler
│
├── Concerns/
│   ├── ConditionallyLoadsAttributes.php  # when(), whenLoaded()
│   ├── CollectsResources.php             # Collection helpers
│   └── DelegatesToResource.php           # Property delegation
│
├── Contracts/
│   ├── Resourceable.php                  # Resource interface
│   └── PaginatedCollection.php           # Pagination interface
│
└── Support/
    ├── MissingValue.php                  # Sentinel for missing values
    └── ResourceResponseFactory.php       # Response building
```

---

## JsonResource Class

### Base Implementation

```php
<?php

declare(strict_types=1);

namespace Glueful\Http\Resources;

use Glueful\Http\Response;
use Glueful\Http\Resources\Concerns\ConditionallyLoadsAttributes;
use Glueful\Http\Resources\Concerns\DelegatesToResource;
use Glueful\Http\Resources\Support\MissingValue;
use JsonSerializable;
use ArrayAccess;

/**
 * JSON Resource - API Response Transformer
 *
 * Transforms models/data into consistent JSON API responses.
 * Extend this class to define your resource transformations.
 *
 * @template TResource
 */
class JsonResource implements JsonSerializable, ArrayAccess
{
    use ConditionallyLoadsAttributes;
    use DelegatesToResource;

    /**
     * The resource instance being transformed
     *
     * @var TResource
     */
    public mixed $resource;

    /**
     * Additional data to be added to the response
     *
     * @var array<string, mixed>
     */
    public array $with = [];

    /**
     * Additional meta data for the response
     *
     * @var array<string, mixed>
     */
    public array $additional = [];

    /**
     * The "data" wrapper to use
     */
    public static ?string $wrap = 'data';

    /**
     * Create a new resource instance
     *
     * @param TResource $resource
     */
    public function __construct(mixed $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Create a new resource instance
     *
     * @param TResource $resource
     * @return static
     */
    public static function make(mixed $resource): static
    {
        return new static($resource);
    }

    /**
     * Create a new resource collection
     *
     * @param iterable<TResource> $resources
     * @return ResourceCollection<static>
     */
    public static function collection(iterable $resources): ResourceCollection
    {
        return new AnonymousResourceCollection($resources, static::class);
    }

    /**
     * Transform the resource into an array
     *
     * Override this method to define your transformation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (is_null($this->resource)) {
            return [];
        }

        // Default: return all attributes
        return is_array($this->resource)
            ? $this->resource
            : (method_exists($this->resource, 'toArray')
                ? $this->resource->toArray()
                : (array) $this->resource);
    }

    /**
     * Add additional data to the response
     *
     * @param array<string, mixed> $data
     * @return static
     */
    public function additional(array $data): static
    {
        $this->additional = array_merge($this->additional, $data);

        return $this;
    }

    /**
     * Set the data wrapper key
     */
    public static function withoutWrapping(): void
    {
        static::$wrap = null;
    }

    /**
     * Set custom wrapper key
     */
    public static function wrap(string $value): void
    {
        static::$wrap = $value;
    }

    /**
     * Resolve the resource to an array
     *
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $data = $this->toArray();

        // Filter out missing values
        $data = $this->filterMissingValues($data);

        // Resolve nested resources
        $data = $this->resolveNestedResources($data);

        return $data;
    }

    /**
     * Filter out MissingValue instances from the data
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function filterMissingValues(array $data): array
    {
        $filtered = [];

        foreach ($data as $key => $value) {
            if ($value instanceof MissingValue) {
                continue;
            }

            if (is_array($value)) {
                $filtered[$key] = $this->filterMissingValues($value);
            } else {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Resolve nested JsonResource instances
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function resolveNestedResources(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof JsonResource) {
                $data[$key] = $value->resolve();
            } elseif ($value instanceof ResourceCollection) {
                $data[$key] = $value->resolve();
            } elseif (is_array($value)) {
                $data[$key] = $this->resolveNestedResources($value);
            }
        }

        return $data;
    }

    /**
     * Create an HTTP response from the resource
     */
    public function toResponse(int $status = 200): Response
    {
        $data = $this->resolve();

        // Apply wrapping
        if (static::$wrap !== null) {
            $data = [static::$wrap => $data];
        }

        // Merge additional data
        $data = array_merge($data, $this->with, $this->additional);

        return new Response(
            array_merge(['success' => true], $data),
            $status
        );
    }

    /**
     * Prepare the resource for JSON serialization
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->resolve();
    }

    /**
     * Transform the resource when it's converted to JSON
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Determine if the given attribute exists on the resource
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->resource[$offset]);
    }

    /**
     * Get an attribute from the resource
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->resource[$offset] ?? null;
    }

    /**
     * Set an attribute on the resource (not supported)
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Resources are read-only
    }

    /**
     * Unset an attribute on the resource (not supported)
     */
    public function offsetUnset(mixed $offset): void
    {
        // Resources are read-only
    }
}
```

### Example Resources

```php
<?php

namespace App\Http\Resources;

use Glueful\Http\Resources\JsonResource;

/**
 * User Resource
 *
 * Transforms user data into consistent API responses.
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->uuid,
            'email' => $this->email,
            'name' => $this->name,
            'avatar_url' => $this->avatar_url,
            'created_at' => $this->formatDate($this->created_at),

            // Conditional attributes
            'email_verified_at' => $this->when(
                $this->email_verified_at !== null,
                fn() => $this->formatDate($this->email_verified_at)
            ),

            // Only include if relationship was loaded
            'posts' => PostResource::collection($this->whenLoaded('posts')),
            'profile' => ProfileResource::make($this->whenLoaded('profile')),

            // Include for admin users only
            'admin_notes' => $this->when(
                $this->isAdmin(),
                $this->admin_notes
            ),

            // Links
            'links' => [
                'self' => url("/api/users/{$this->uuid}"),
                'posts' => url("/api/users/{$this->uuid}/posts"),
            ],
        ];
    }

    /**
     * Format a date for the response
     */
    protected function formatDate(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return (new \DateTime($date))->format(\DateTime::ATOM);
    }

    /**
     * Check if current user is admin
     */
    protected function isAdmin(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * Get additional data for the response
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'meta' => [
                'version' => '1.0',
            ],
        ];
    }
}

/**
 * Post Resource
 */
class PostResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'content' => $this->when($this->shouldShowContent(), $this->content),
            'published_at' => $this->published_at,
            'status' => $this->status,

            // Nested resources
            'author' => UserResource::make($this->whenLoaded('author')),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),

            // Computed attributes
            'reading_time' => $this->calculateReadingTime(),
            'is_published' => $this->status === 'published',

            'links' => [
                'self' => url("/api/posts/{$this->slug}"),
            ],
        ];
    }

    protected function shouldShowContent(): bool
    {
        // Only show full content on single resource requests
        return request()->route('post') !== null;
    }

    protected function calculateReadingTime(): int
    {
        $wordCount = str_word_count(strip_tags($this->content ?? ''));
        return max(1, (int) ceil($wordCount / 200));
    }
}
```

---

## Conditional Attributes

### ConditionallyLoadsAttributes Trait

```php
<?php

declare(strict_types=1);

namespace Glueful\Http\Resources\Concerns;

use Glueful\Http\Resources\Support\MissingValue;
use Closure;

/**
 * Conditional Attribute Loading
 *
 * Provides methods for conditionally including attributes in resources.
 */
trait ConditionallyLoadsAttributes
{
    /**
     * Include a value only when a condition is true
     *
     * @param bool|Closure $condition
     * @param mixed|Closure $value Value to include (or closure that returns value)
     * @param mixed $default Default value when condition is false
     */
    protected function when(bool|Closure $condition, mixed $value, mixed $default = null): mixed
    {
        $conditionResult = $condition instanceof Closure ? $condition() : $condition;

        if ($conditionResult) {
            return $value instanceof Closure ? $value() : $value;
        }

        if (func_num_args() === 3) {
            return $default instanceof Closure ? $default() : $default;
        }

        return new MissingValue();
    }

    /**
     * Include a value only when it's not null
     *
     * @param mixed $value
     * @param Closure|null $callback Optional transformation callback
     */
    protected function whenNotNull(mixed $value, ?Closure $callback = null): mixed
    {
        return $this->when(
            $value !== null,
            fn() => $callback ? $callback($value) : $value
        );
    }

    /**
     * Include a relationship only when it's been loaded
     *
     * Works with ORM models that have relationship loading tracking.
     *
     * @param string $relationship The relationship name
     * @param mixed $default Default value when not loaded
     */
    protected function whenLoaded(string $relationship, mixed $default = null): mixed
    {
        // Check if resource has relationLoaded method (ORM model)
        if (method_exists($this->resource, 'relationLoaded')) {
            if ($this->resource->relationLoaded($relationship)) {
                return $this->resource->$relationship;
            }
        }

        // Check if it's an array with the key present
        if (is_array($this->resource) && array_key_exists($relationship, $this->resource)) {
            return $this->resource[$relationship];
        }

        // Check if it's an object with the property
        if (is_object($this->resource) && isset($this->resource->$relationship)) {
            return $this->resource->$relationship;
        }

        if (func_num_args() === 2) {
            return $default instanceof Closure ? $default() : $default;
        }

        return new MissingValue();
    }

    /**
     * Include pivot attributes when available
     *
     * @param string $attribute Pivot attribute name
     * @param mixed $default Default value
     */
    protected function whenPivotLoaded(string $table, string $attribute, mixed $default = null): mixed
    {
        if (!method_exists($this->resource, 'pivot') || $this->resource->pivot === null) {
            return func_num_args() === 3 ? $default : new MissingValue();
        }

        if ($this->resource->pivot->getTable() !== $table) {
            return func_num_args() === 3 ? $default : new MissingValue();
        }

        return $this->resource->pivot->$attribute ?? $default;
    }

    /**
     * Merge values conditionally
     *
     * @param bool|Closure $condition
     * @param array<string, mixed>|Closure $values
     * @return array<string, mixed>|MissingValue
     */
    protected function mergeWhen(bool|Closure $condition, array|Closure $values): array|MissingValue
    {
        $conditionResult = $condition instanceof Closure ? $condition() : $condition;

        if (!$conditionResult) {
            return new MissingValue();
        }

        return $values instanceof Closure ? $values() : $values;
    }

    /**
     * Include attributes based on the request
     *
     * @param string $field
     * @param mixed $value
     */
    protected function whenRequested(string $field, mixed $value): mixed
    {
        // Integration with FieldSelector
        $fields = request()->get('fields', '*');

        if ($fields === '*') {
            return $value instanceof Closure ? $value() : $value;
        }

        $requestedFields = is_array($fields) ? $fields : explode(',', $fields);

        return $this->when(
            in_array($field, $requestedFields, true),
            $value
        );
    }
}
```

---

## Resource Collections

### ResourceCollection Class

```php
<?php

declare(strict_types=1);

namespace Glueful\Http\Resources;

use Glueful\Http\Response;
use Glueful\Http\Resources\Concerns\CollectsResources;
use Countable;
use IteratorAggregate;
use ArrayIterator;
use JsonSerializable;

/**
 * Resource Collection
 *
 * Transforms a collection of resources into a consistent API response.
 *
 * @template TResource of JsonResource
 * @implements IteratorAggregate<int, TResource>
 */
class ResourceCollection implements Countable, IteratorAggregate, JsonSerializable
{
    use CollectsResources;

    /**
     * The resource that this collection collects
     *
     * @var class-string<TResource>
     */
    public string $collects;

    /**
     * The collection of resources
     *
     * @var iterable<mixed>
     */
    public iterable $collection;

    /**
     * Additional data to be added to the response
     *
     * @var array<string, mixed>
     */
    public array $with = [];

    /**
     * Additional meta data
     *
     * @var array<string, mixed>
     */
    public array $additional = [];

    /**
     * Pagination data if paginated
     *
     * @var array<string, mixed>|null
     */
    protected ?array $pagination = null;

    /**
     * Create a new resource collection
     *
     * @param iterable<mixed> $resource
     */
    public function __construct(iterable $resource)
    {
        $this->collection = $this->collectResources($resource);
    }

    /**
     * Create a new collection instance
     *
     * @param iterable<mixed> $resource
     * @return static
     */
    public static function make(iterable $resource): static
    {
        return new static($resource);
    }

    /**
     * Set pagination data
     *
     * @param array<string, mixed> $pagination
     * @return static
     */
    public function withPagination(array $pagination): static
    {
        $this->pagination = $pagination;

        return $this;
    }

    /**
     * Resolve the collection to an array
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolve(): array
    {
        return array_map(
            fn($resource) => $resource->resolve(),
            iterator_to_array($this->collection)
        );
    }

    /**
     * Create an HTTP response from the collection
     */
    public function toResponse(int $status = 200): Response
    {
        $data = [
            'success' => true,
            'data' => $this->resolve(),
        ];

        // Add pagination if present
        if ($this->pagination !== null) {
            $data = array_merge($data, $this->pagination);
        }

        // Add additional data
        $data = array_merge($data, $this->with, $this->additional);

        return new Response($data, $status);
    }

    /**
     * Add additional data to the response
     *
     * @param array<string, mixed> $data
     * @return static
     */
    public function additional(array $data): static
    {
        $this->additional = array_merge($this->additional, $data);

        return $this;
    }

    /**
     * Get the count of items in the collection
     */
    public function count(): int
    {
        return count(iterator_to_array($this->collection));
    }

    /**
     * Get an iterator for the collection
     *
     * @return ArrayIterator<int, TResource>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator(iterator_to_array($this->collection));
    }

    /**
     * Convert to array for JSON serialization
     *
     * @return array<int, array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return $this->resolve();
    }

    /**
     * Convert to JSON string
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }
}

/**
 * Anonymous Resource Collection
 *
 * Used when calling ::collection() on a JsonResource.
 *
 * @template TResource of JsonResource
 * @extends ResourceCollection<TResource>
 */
class AnonymousResourceCollection extends ResourceCollection
{
    /**
     * Create a new anonymous resource collection
     *
     * @param iterable<mixed> $resource
     * @param class-string<TResource> $collects
     */
    public function __construct(iterable $resource, string $collects)
    {
        $this->collects = $collects;

        parent::__construct($resource);
    }

    /**
     * Collect resources into the appropriate resource class
     *
     * @param iterable<mixed> $resources
     * @return array<TResource>
     */
    protected function collectResources(iterable $resources): array
    {
        $collected = [];

        foreach ($resources as $resource) {
            $collected[] = new $this->collects($resource);
        }

        return $collected;
    }
}
```

### CollectsResources Trait

```php
<?php

declare(strict_types=1);

namespace Glueful\Http\Resources\Concerns;

/**
 * Resource Collection Helpers
 */
trait CollectsResources
{
    /**
     * Collect resources into resource instances
     *
     * @param iterable<mixed> $resources
     * @return array<\Glueful\Http\Resources\JsonResource>
     */
    protected function collectResources(iterable $resources): array
    {
        $collected = [];

        foreach ($resources as $resource) {
            if ($resource instanceof \Glueful\Http\Resources\JsonResource) {
                $collected[] = $resource;
            } else {
                $collected[] = $this->newResourceInstance($resource);
            }
        }

        return $collected;
    }

    /**
     * Create a new resource instance
     */
    protected function newResourceInstance(mixed $resource): \Glueful\Http\Resources\JsonResource
    {
        if (isset($this->collects)) {
            return new $this->collects($resource);
        }

        return new \Glueful\Http\Resources\JsonResource($resource);
    }
}
```

---

## Pagination

### Paginated Collections

```php
<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use Glueful\Http\Response;

class UserController
{
    public function index(): Response
    {
        // Using QueryBuilder pagination
        $result = User::query()
            ->where('status', 'active')
            ->paginate(perPage: 25);

        // Create resource collection with pagination
        return UserResource::collection($result['data'])
            ->withPagination([
                'current_page' => $result['meta']['current_page'],
                'per_page' => $result['meta']['per_page'],
                'total' => $result['meta']['total'],
                'total_pages' => $result['meta']['total_pages'],
                'has_next_page' => $result['meta']['has_next_page'],
                'has_previous_page' => $result['meta']['has_previous_page'],
            ])
            ->toResponse();
    }
}
```

### Paginated Response Format

```json
{
    "success": true,
    "data": [
        {
            "id": "uuid-1",
            "name": "John Doe",
            "email": "john@example.com"
        },
        {
            "id": "uuid-2",
            "name": "Jane Smith",
            "email": "jane@example.com"
        }
    ],
    "current_page": 1,
    "per_page": 25,
    "total": 150,
    "total_pages": 6,
    "has_next_page": true,
    "has_previous_page": false
}
```

---

## Response Wrapping

### Customizing the Wrapper

```php
<?php

// Disable wrapping globally
JsonResource::withoutWrapping();

// Custom wrapper key
JsonResource::wrap('result');

// Per-resource wrapper
class UserResource extends JsonResource
{
    public static ?string $wrap = 'user';
}

// Response without wrapping:
{
    "success": true,
    "id": "uuid",
    "name": "John"
}

// Response with 'data' wrapping (default):
{
    "success": true,
    "data": {
        "id": "uuid",
        "name": "John"
    }
}

// Response with 'user' wrapping:
{
    "success": true,
    "user": {
        "id": "uuid",
        "name": "John"
    }
}
```

---

## Implementation Phases

### Phase 1: Core Classes (Week 1)

**Deliverables:**
- [ ] `JsonResource` base class
- [ ] `ResourceCollection` class
- [ ] `AnonymousResourceCollection`
- [ ] `MissingValue` sentinel class
- [ ] Basic `toArray()` transformation

**Acceptance Criteria:**
```php
// Single resource
return UserResource::make($user)->toResponse();

// Collection
return UserResource::collection($users)->toResponse();
```

### Phase 2: Conditional Loading (Week 1-2)

**Deliverables:**
- [ ] `ConditionallyLoadsAttributes` trait
- [ ] `when()` method
- [ ] `whenLoaded()` method
- [ ] `whenNotNull()` method
- [ ] `mergeWhen()` method

**Acceptance Criteria:**
```php
public function toArray(): array
{
    return [
        'name' => $this->name,
        'posts' => PostResource::collection($this->whenLoaded('posts')),
        'secret' => $this->when($this->isAdmin(), $this->secret),
    ];
}
```

### Phase 3: Pagination & Response (Week 2)

**Deliverables:**
- [ ] `withPagination()` method
- [ ] Response wrapping configuration
- [ ] `additional()` method
- [ ] Integration with existing `Response` class

**Acceptance Criteria:**
```php
return UserResource::collection($users)
    ->withPagination($pagination)
    ->additional(['meta' => ['version' => '1.0']])
    ->toResponse();
```

### Phase 4: Make Command & Docs (Week 2-3)

**Deliverables:**
- [ ] `php glueful make:resource` command
- [ ] Complete test coverage
- [ ] Documentation
- [ ] IDE helper stubs

---

## Testing Strategy

### Unit Tests

```php
<?php

namespace Glueful\Tests\Unit\Http\Resources;

use PHPUnit\Framework\TestCase;
use Glueful\Http\Resources\JsonResource;

class JsonResourceTest extends TestCase
{
    public function testTransformsArrayToArray(): void
    {
        $data = ['name' => 'John', 'email' => 'john@example.com'];
        $resource = JsonResource::make($data);

        $this->assertEquals($data, $resource->resolve());
    }

    public function testWhenConditionTrue(): void
    {
        $resource = new class(['name' => 'John', 'secret' => 'hidden']) extends JsonResource {
            public function toArray(): array {
                return [
                    'name' => $this->resource['name'],
                    'secret' => $this->when(true, $this->resource['secret']),
                ];
            }
        };

        $this->assertArrayHasKey('secret', $resource->resolve());
    }

    public function testWhenConditionFalse(): void
    {
        $resource = new class(['name' => 'John', 'secret' => 'hidden']) extends JsonResource {
            public function toArray(): array {
                return [
                    'name' => $this->resource['name'],
                    'secret' => $this->when(false, $this->resource['secret']),
                ];
            }
        };

        $this->assertArrayNotHasKey('secret', $resource->resolve());
    }
}
```

### Integration Tests

```php
<?php

namespace Glueful\Tests\Integration\Http\Resources;

use Glueful\Tests\TestCase;
use App\Http\Resources\UserResource;

class UserResourceTest extends TestCase
{
    public function testResourceReturnsCorrectStructure(): void
    {
        $user = $this->createUser();

        $response = $this->get("/api/users/{$user->uuid}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'email',
                'name',
                'created_at',
                'links' => ['self'],
            ],
        ]);
    }

    public function testCollectionWithPagination(): void
    {
        $this->createUsers(30);

        $response = $this->get('/api/users?page=2&per_page=10');

        $response->assertStatus(200);
        $response->assertJson([
            'current_page' => 2,
            'per_page' => 10,
            'has_previous_page' => true,
        ]);
        $response->assertJsonCount(10, 'data');
    }
}
```

---

## Performance Considerations

### Avoiding N+1 Queries

```php
// BAD: N+1 queries
$users = User::all();
return UserResource::collection($users)->toResponse();
// Each user's posts loaded individually in the resource

// GOOD: Eager load relationships
$users = User::with(['posts', 'profile'])->get();
return UserResource::collection($users)->toResponse();
// All relationships loaded in 3 queries total
```

### Using whenLoaded Properly

```php
public function toArray(): array
{
    return [
        'name' => $this->name,
        // Only serializes if already loaded - no extra query
        'posts' => PostResource::collection($this->whenLoaded('posts')),
    ];
}
```

### Lazy Transformation

```php
// Values are only computed when accessed
'reading_time' => $this->when(
    $this->shouldCalculateReadingTime(),
    fn() => $this->calculateReadingTime() // Only called if condition is true
),
```

---

## API Reference

### JsonResource Methods

| Method | Description |
|--------|-------------|
| `make($resource)` | Create a new resource instance |
| `collection($resources)` | Create a resource collection |
| `toArray()` | Transform resource to array (override) |
| `resolve()` | Resolve all nested resources |
| `toResponse($status)` | Create HTTP response |
| `additional(array $data)` | Add extra data to response |
| `withoutWrapping()` | Disable data wrapper |
| `wrap(string $key)` | Set custom wrapper key |

### Conditional Methods

| Method | Description |
|--------|-------------|
| `when($condition, $value, $default)` | Include value conditionally |
| `whenLoaded($relation, $default)` | Include if relation loaded |
| `whenNotNull($value, $callback)` | Include if not null |
| `mergeWhen($condition, $values)` | Merge array conditionally |
| `whenPivotLoaded($table, $attr)` | Include pivot attribute |

### Collection Methods

| Method | Description |
|--------|-------------|
| `make($resources)` | Create collection |
| `withPagination($data)` | Add pagination data |
| `additional($data)` | Add extra data |
| `toResponse($status)` | Create HTTP response |
| `count()` | Get item count |
