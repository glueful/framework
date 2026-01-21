# Glueful API Resources Documentation

API Resources provide a transformation layer between your data (models, arrays, objects) and the JSON responses returned by your API. They ensure consistent, well-structured API responses across all endpoints.

## Table of Contents

- [Introduction](#introduction)
- [Creating Resources](#creating-resources)
- [Basic Usage](#basic-usage)
- [Conditional Attributes](#conditional-attributes)
- [Working with Relationships](#working-with-relationships)
- [Resource Collections](#resource-collections)
- [Pagination](#pagination)
- [Model Resources](#model-resources)
- [Response Customization](#response-customization)

## Introduction

Instead of manually transforming data in every controller, resources provide a reusable transformation layer:

```php
// Before: Manual transformation in controller
public function show(int $id): Response
{
    $user = User::find($id);

    return Response::success([
        'id' => $user->uuid,
        'name' => $user->name,
        'email' => $user->email,
        'created_at' => $user->created_at->format('c'),
    ]);
}

// After: Using resources
public function show(int $id): Response
{
    $user = User::find($id);

    return UserResource::make($user)->toResponse();
}
```

## Creating Resources

Use the `scaffold:resource` command to generate resources:

```bash
# Basic resource
php glueful scaffold:resource UserResource

# Model resource (with ORM integration)
php glueful scaffold:resource UserResource --model

# Resource collection
php glueful scaffold:resource UserCollection --collection

# Nested namespace
php glueful scaffold:resource User/ProfileResource
```

### Resource Types

| Type | Base Class | Use Case |
|------|-----------|----------|
| Basic | `JsonResource` | Arrays, simple objects, non-ORM data |
| Model | `ModelResource` | ORM models with relationships |
| Collection | `ResourceCollection` | Multiple items with metadata |

## Basic Usage

### Defining a Resource

```php
<?php

namespace App\Http\Resources;

use Glueful\Http\Resources\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->resource['uuid'],
            'name' => $this->resource['name'],
            'email' => $this->resource['email'],
            'created_at' => $this->resource['created_at'],
        ];
    }
}
```

### Using Resources in Controllers

```php
use App\Http\Resources\UserResource;

class UserController
{
    // Single resource
    public function show(int $id): Response
    {
        $user = User::find($id);

        return UserResource::make($user)->toResponse();
    }

    // Collection of resources
    public function index(): Response
    {
        $users = User::where('active', true)->get();

        return UserResource::collection($users)->toResponse();
    }
}
```

### Accessing Resource Data

Within your resource, access the underlying data via `$this->resource` or use property delegation:

```php
class UserResource extends JsonResource
{
    public function toArray(): array
    {
        // Direct access
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
        ];

        // Or with property delegation (for objects)
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
```

## Conditional Attributes

Include attributes only when certain conditions are met.

### Using `when()`

```php
class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'email' => $this->resource['email'],

            // Only include if user is admin
            'admin_notes' => $this->when(
                $this->resource['role'] === 'admin',
                $this->resource['admin_notes']
            ),

            // With default value
            'secret' => $this->when(
                $this->isAuthorized(),
                $this->resource['secret'],
                'hidden'  // Default when condition is false
            ),
        ];
    }
}
```

### Using `mergeWhen()`

Conditionally merge multiple attributes:

```php
class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],

            // Merge admin-only fields
            $this->mergeWhen($this->isAdmin(), [
                'permissions' => $this->resource['permissions'],
                'last_login_ip' => $this->resource['last_login_ip'],
                'login_count' => $this->resource['login_count'],
            ]),
        ];
    }
}
```

### Using `whenHas()`

Include attribute only if it exists in the source data:

```php
class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'bio' => $this->whenHas('bio'),  // Only if 'bio' key exists
        ];
    }
}
```

### Using `whenNotNull()`

Include attribute only if it's not null:

```php
class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'avatar_url' => $this->whenNotNull($this->resource['avatar']),
        ];
    }
}
```

## Working with Relationships

### Using `whenLoaded()`

Only include relationships that have been eager-loaded:

```php
class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],

            // Only included if 'posts' relationship is loaded
            'posts' => $this->whenLoaded('posts'),

            // Transform loaded relationship with a resource
            'profile' => $this->whenLoaded('profile', function ($profile) {
                return ProfileResource::make($profile);
            }),
        ];
    }
}
```

### Using `whenCounted()`

Include relationship counts:

```php
class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],

            // Only if withCount('posts') was called
            'posts_count' => $this->whenCounted('posts'),
        ];
    }
}
```

### Using `whenPivotLoaded()`

Access pivot table data for many-to-many relationships:

```php
class RoleResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],

            // Access pivot data
            'assigned_at' => $this->whenPivotLoaded('role_user', 'created_at'),
        ];
    }
}
```

## Resource Collections

### Basic Collection

```php
// Using the static collection method
$users = User::all();
return UserResource::collection($users)->toResponse();
```

### Custom Collection Class

```php
<?php

namespace App\Http\Resources;

use Glueful\Http\Resources\ResourceCollection;

class UserCollection extends ResourceCollection
{
    public string $collects = UserResource::class;

    public function toArray(): array
    {
        return [
            'data' => $this->resolve(),
            'summary' => [
                'total_users' => $this->count(),
                'active_users' => $this->countActive(),
            ],
        ];
    }

    private function countActive(): int
    {
        return count(array_filter(
            $this->collection,
            fn($resource) => $resource->resource['active'] ?? false
        ));
    }
}
```

### Adding Collection Metadata

```php
// Using the with() method
class UserCollection extends ResourceCollection
{
    public function with(): array
    {
        return [
            'meta' => [
                'version' => '1.0',
                'generated_at' => date('c'),
            ],
        ];
    }
}

// Or using additional()
return UserResource::collection($users)
    ->additional(['meta' => ['version' => '1.0']])
    ->toResponse();
```

## Pagination

### From Query Builder Results

```php
// QueryBuilder returns flat pagination format
$result = User::query()->paginate(page: 1, perPage: 25);

return UserResource::collection($result['data'])
    ->withPaginationFrom($result)
    ->toResponse();
```

### From ORM Results

```php
// ORM returns meta-based pagination format
$result = User::paginate(25);

return UserResource::collection($result['data'])
    ->withPaginationFrom($result)
    ->toResponse();
```

### Adding Pagination Links

```php
$result = User::query()->paginate(page: 2, perPage: 25);

return UserResource::collection($result['data'])
    ->withPaginationFrom($result)
    ->withLinks('/api/users', ['status' => 'active'])
    ->toResponse();

// Response includes:
// {
//   "data": [...],
//   "current_page": 2,
//   "per_page": 25,
//   "total": 150,
//   "total_pages": 6,
//   "links": {
//     "first": "/api/users?status=active&page=1&per_page=25",
//     "last": "/api/users?status=active&page=6&per_page=25",
//     "prev": "/api/users?status=active&page=1&per_page=25",
//     "next": "/api/users?status=active&page=3&per_page=25"
//   }
// }
```

### Using PaginatedResourceResponse

For more control over pagination:

```php
use Glueful\Http\Resources\PaginatedResourceResponse;

$result = User::query()->paginate(page: 1, perPage: 25);

return PaginatedResourceResponse::fromQueryResult($result, UserResource::class)
    ->withBaseUrl('/api/users')
    ->withQueryParams(['status' => 'active'])
    ->additional(['meta' => ['version' => '1.0']])
    ->toResponse();
```

## Model Resources

`ModelResource` extends `JsonResource` with ORM-specific helpers.

### Creating a Model Resource

```bash
php glueful scaffold:resource UserResource --model
```

### Model Resource Helpers

```php
<?php

namespace App\Http\Resources;

use Glueful\Http\Resources\ModelResource;

class UserResource extends ModelResource
{
    public function toArray(): array
    {
        return [
            // Get attribute with optional default
            'id' => $this->attribute('uuid'),
            'name' => $this->attribute('name'),
            'role' => $this->attribute('role', 'user'),  // Default: 'user'

            // Format dates as ISO 8601
            'created_at' => $this->dateAttribute('created_at'),
            'updated_at' => $this->whenDateNotNull('updated_at'),

            // Relationships (only if loaded)
            'posts' => $this->whenLoaded('posts'),
            'profile' => $this->relationshipResource('profile', ProfileResource::class),
            'comments' => $this->relationshipCollection('comments', CommentResource::class),

            // Relationship counts
            'posts_count' => $this->whenCounted('posts'),

            // Pivot data
            'role_assigned_at' => $this->whenPivotLoaded('role_user', 'created_at'),
        ];
    }
}
```

### Available Model Resource Methods

| Method | Description |
|--------|-------------|
| `attribute($key, $default)` | Get model attribute with optional default |
| `hasAttribute($key)` | Check if attribute exists |
| `dateAttribute($key)` | Format date as ISO 8601 |
| `whenDateNotNull($key)` | Include date only if not null |
| `isRelationLoaded($relation)` | Check if relationship is loaded |
| `getRelation($relation)` | Get loaded relationship |
| `relationshipResource($relation, $class)` | Transform single relationship |
| `relationshipCollection($relation, $class)` | Transform collection relationship |
| `hasAnyRelationLoaded(...$relations)` | Check if any relation is loaded |
| `hasAllRelationsLoaded(...$relations)` | Check if all relations are loaded |

## Response Customization

### Custom Status Codes

```php
return UserResource::make($user)->toResponse(201);
```

### Custom Headers

```php
return UserResource::make($user)->toResponse(200, [
    'X-Custom-Header' => 'value',
]);
```

### Additional Data

```php
return UserResource::make($user)
    ->additional([
        'meta' => ['version' => '1.0'],
        'links' => ['self' => '/api/users/' . $user->id],
    ])
    ->toResponse();
```

### Response Wrapping

By default, resources wrap data in a `data` key. Customize this:

```php
// Disable wrapping globally
JsonResource::withoutWrapping();

// Custom wrapper key
JsonResource::wrap('result');

// Response structure:
// { "result": { ... } }
```

### Converting to Array

```php
// Get the transformed array without creating a Response
$array = UserResource::make($user)->resolve();

// For collections
$array = UserResource::collection($users)->resolve();
```

## Best Practices

1. **One Resource Per Model**: Create dedicated resources for each model type
2. **Use Conditional Loading**: Always use `whenLoaded()` for relationships to prevent N+1 queries
3. **Eager Load in Controllers**: Load relationships in controllers, not resources
4. **Keep Resources Focused**: Resources transform data; business logic belongs elsewhere
5. **Use Model Resources for ORM**: Leverage `ModelResource` helpers when working with ORM models

```php
// Good: Eager load in controller
public function show(int $id): Response
{
    $user = User::with(['posts', 'profile'])->findOrFail($id);
    return UserResource::make($user)->toResponse();
}

// Bad: Loading in resource (causes N+1)
public function toArray(): array
{
    return [
        'posts' => $this->resource->posts,  // Triggers query!
    ];
}
```
