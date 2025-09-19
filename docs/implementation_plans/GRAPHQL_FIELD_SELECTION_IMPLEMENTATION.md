# GraphQL-Style Field Selection Implementation Plan

## Overview

This document outlines the implementation plan for adding GraphQL-style field selection to the Glueful Framework's REST API endpoints. This feature allows clients to specify exactly which fields they want in API responses, similar to GraphQL's field selection but integrated into REST endpoints via query parameters.

## Current State Analysis

Based on the existing routing implementation in `src/Routing/`, the framework has:

- High-performance router with O(1) static route matching
- Robust parameter injection system in `Router::resolveMethodParameters()` (line 533)
- Middleware pipeline architecture
- Attribute-based route definitions
- Container-based dependency injection

## Implementation Architecture

The enhanced implementation uses a comprehensive multi-component approach with the following core components:

- **FieldTree/FieldNode**: AST representation of field selections
- **FieldSelector**: Lightweight value object wrapping the tree with configuration
- **Projector**: Handles field projection with expander support for N+1 prevention  
- **Parsers**: Separate GraphQL and REST syntax parsers
- **Middleware**: Request/response pipeline integration
- **Attributes**: Declarative route-level configuration

### Recent Enhancements

The implementation now includes several advanced features that were added beyond the original specification:

1. **Factory Method Pattern**: `FieldSelector::fromRequest()` with automatic syntax detection and whitelist support
2. **Enhanced Context Passing**: Rich context data passed to expanders including collection IDs for batch loading
3. **Route-Level Configuration**: Full `#[Fields]` attribute processing integrated into the routing system
4. **Comprehensive Error Handling**: Structured error responses with detailed validation messages
5. **Production-Grade Security**: Multi-level whitelisting with wildcard pattern support

### 1. Enhanced Field Selector

**File**: `src/Support/FieldSelection/FieldSelector.php`

> **Note**: Placed in FieldSelection namespace for clear organization and reusability across GraphQL extensions, REST APIs, and other components

Supports both REST-style and GraphQL-style syntax:
- REST: `?fields=id,name&expand=posts.comments`  
- GraphQL: `?fields=user(id,name,posts(id,title,comments(text)))`

```php
<?php
declare(strict_types=1);

namespace Glueful\Support\FieldSelection;

use Glueful\Support\FieldSelection\Parsers\GraphQLProjectionParser;
use Glueful\Support\FieldSelection\Parsers\RestProjectionParser;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lightweight value-object exposed to controllers (DI-injectable).
 * Wraps the parsed FieldTree + options for guards and DX helpers.
 */
final class FieldSelector
{
    public function __construct(
        public readonly FieldTree $tree,
        public readonly bool $strict = false,
        public readonly int $maxDepth = 6,
        public readonly int $maxFields = 200,
        public readonly int $maxItems = 1000
    ) {
    }

    /**
     * Factory method to create FieldSelector from HTTP request
     *
     * @param array<string>|null $whitelist Optional field whitelist
     */
    public static function fromRequest(
        Request $request,
        bool $strict = false,
        int $maxDepth = 6,
        int $maxFields = 200,
        int $maxItems = 1000,
        ?array $whitelist = null
    ): self {
        $fields = $request->query->get('fields');
        $expand = $request->query->get('expand');

        // Fast path: no field selection
        if ($fields === null && $expand === null) {
            return new self(FieldTree::empty(), $strict, $maxDepth, $maxFields, $maxItems);
        }

        // Parse based on syntax detection
        if ($fields !== null && $fields !== '' && str_contains((string)$fields, '(')) {
            // GraphQL-style syntax detected
            $tree = (new GraphQLProjectionParser())->parse((string)$fields);
        } else {
            // REST-style syntax
            $tree = (new RestProjectionParser())->parse((string)$fields, (string)$expand);
        }

        // Apply whitelist if provided
        if ($whitelist !== null && count($whitelist) > 0) {
            $tree = $tree->applyWhitelist($whitelist);
        }

        return new self($tree, $strict, $maxDepth, $maxFields, $maxItems);
    }

    public function empty(): bool
    {
        return $this->tree->isEmpty();
    }

    public function requested(string $dotPath): bool
    {
        return $this->tree->requested($dotPath);
    }
}
```

### 2. Enhanced Projector with N+1 Prevention

**File**: `src/Support/FieldSelection/Projector.php`

The Projector handles the actual field projection and includes "expanders" for N+1 query prevention:

```php
<?php
declare(strict_types=1);

namespace Glueful\Support\FieldSelection;

/**
 * Applies a FieldTree to arrays/objects and runs expanders for relations.
 * Expanders are callables registered per relation name: fn(array $context, FieldNode $node, array $data): mixed
 */
final class Projector
{
    /** @var array<string, callable> relationName => expander */
    private array $expanders = [];

    /** @param array<string,string[]> $whitelist */
    public function __construct(
        private readonly array $whitelist = [],
        private readonly bool $strictDefault = false,
        private readonly int $maxDepthDefault = 6,
        private readonly int $maxFieldsDefault = 200,
        private readonly int $maxItemsDefault = 1000,
    ) {
    }

    /** Register or override a relation expander */
    public function register(string $relation, callable $expander): void
    {
        $this->expanders[$relation] = $expander;
    }

    /**
     * Project a record (or list of records).
     * $data can be an array (assoc), an object (ArrayAccess/getter), or list<array>.
     *
     * @param array<int,mixed>|array<string,mixed>|object $data
     * @param array<string>|null $allowed overrides whitelist key if given
     * @param array<string,mixed> $context context data for expanders
     * @return mixed
     */
    public function project(
        mixed $data,
        FieldSelector $selector,
        ?array $allowed = null,
        ?string $whitelistKey = null,
        array $context = []
    ): mixed {
        // Resolve whitelist
        $allowedFields = $allowed;
        if ($allowedFields === null && $whitelistKey !== null && isset($this->whitelist[$whitelistKey])) {
            $allowedFields = $this->whitelist[$whitelistKey];
        }

        // If empty selection, return as-is (fast-path).
        if ($selector->empty()) {
            return $data;
        }

        // Expand '*' into allowed list when present.
        $tree = $this->expandWildcards($selector->tree, $allowedFields);

        // Guards
        $this->guardTree($tree, $selector, $allowedFields);

        // Project lists vs single
        if (\is_array($data) && $this->isList($data)) {
            $limit = min(\count($data), $selector->maxItems);
            $out = [];
            for ($i = 0; $i < $limit; $i++) {
                $out[] = $this->projectOne($data[$i], $tree, $context);
            }
            return $out;
        }

        return $this->projectOne($data, $tree, $context);
    }

    // ... Additional methods for projection logic, guards, and helpers
}
```

### 3. Enhanced Middleware

**File**: `src/Routing/Middleware/FieldSelectionMiddleware.php`

> **Note**: Placed alongside existing `AuthMiddleware.php` and follows the same patterns

```php
<?php
declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Routing\RouteMiddleware;
use Glueful\Support\FieldSelection\Parsers\{GraphQLProjectionParser, RestProjectionParser};
use Glueful\Support\FieldSelection\{FieldSelector, FieldTree, Projector, FieldNode};
use Glueful\Support\FieldSelection\Exceptions\InvalidFieldSelectionException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FieldSelectionMiddleware implements RouteMiddleware
{
    public function __construct(private readonly Projector $projector) {}

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        try {
            // Fast path: no params bypass
            $fields = $request->query->get('fields');
            $expand = $request->query->get('expand');
            if ($fields === null && $expand === null) {
                return $next($request);
            }

            // Parse both syntaxes
            $tree = $this->parseCombined((string)$fields, (string)$expand);

            // Read per-route hints (set by attributes or router metadata; fall back to config)
            $route = $request->attributes->get('_route');
            $routeFieldsConfig = [];

            // Get fields config from Route object if available
            if ($route instanceof \Glueful\Routing\Route) {
                $fieldsConfig = $route->getFieldsConfig();
                if ($fieldsConfig !== null) {
                    $routeFieldsConfig = $fieldsConfig;
                }
            } elseif (\is_array($route)) {
                $routeFieldsConfig = $route['fields'] ?? [];
            }

            $opts = \array_merge($this->defaults(), $routeFieldsConfig);

            $selector = new FieldSelector(
                tree: $tree,
                strict: (bool)($opts['strict'] ?? false),
                maxDepth: (int)($opts['maxDepth'] ?? 6),
                maxFields: (int)($opts['maxFields'] ?? 200),
                maxItems: (int)($opts['maxItems'] ?? 1000),
            );

            // Stash for controllers/expanders DI
            $request->attributes->set(FieldSelector::class, $selector);

            // Continue pipeline
            $response = $next($request);

            // Only project JSON-ish responses we control
            if (!$response instanceof Response || !str_contains($response->headers->get('Content-Type', ''), 'application/json')) {
                return $response;
            }

            // Decode, project, re-encode
            $payload = json_decode((string)$response->getContent(), true);
            if ($payload === null) {
                return $response;
            }

            $allowed = \is_array($opts['allowed'] ?? null) ? $opts['allowed'] : null;
            $key     = \is_string($opts['whitelistKey'] ?? null) ? $opts['whitelistKey'] : null;

            // Build comprehensive context for expanders
            $context = $this->buildContext($request, $payload);

            $projected = $this->projector->project($payload, $selector, $allowed, $key, $context);
            $response->setContent((string)json_encode($projected));

            return $response;
        } catch (InvalidFieldSelectionException $e) {
            return $e->toResponse();
        }
    }
    
    /**
     * Extract collection IDs for expander batch loading
     */
    private function extractCollectionIds(array $payload): array
    {
        if (!is_array($payload)) return [];
        
        // Handle single item
        if (isset($payload['id'])) {
            return ['item_ids' => [$payload['id']]];
        }
        
        // Handle collections
        if (array_is_list($payload)) {
            return ['item_ids' => array_column($payload, 'id')];
        }
        
        // Handle paginated responses
        if (isset($payload['data']) && is_array($payload['data'])) {
            return ['item_ids' => array_column($payload['data'], 'id')];
        }
        
        return [];
    }
}
```

### 5. Field Selection Attribute

**File**: `src/Routing/Attributes/Fields.php`

> **Note**: Placed alongside existing route attributes (`Get.php`, `Post.php`, etc.)

```php
<?php
declare(strict_types=1);

namespace Glueful\Routing\Attributes;

use Attribute;

/**
 * Attach per-route field-selection options & whitelist.
 * Example:
 *   #[Fields(strict: true, allowed: ['id','name','posts','comments'], maxDepth: 6)]
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Fields
{
    /** @param string[]|null $allowed */
    public function __construct(
        public readonly ?bool $strict = null,
        public readonly ?array $allowed = null,
        public readonly ?string $whitelistKey = null,
        public readonly ?int $maxDepth = null,
        public readonly ?int $maxFields = null,
        public readonly ?int $maxItems = null,
    ) {
    }
}

/**
 * Field Selection Behavior:
 * 
 * strict=true:
 *   - Undeclared fields → 400 error with invalid_fields list
 *   - Provides clear feedback to API consumers
 *   - Recommended for production APIs
 * 
 * strict=false:
 *   - Undeclared fields → silently ignored during projection
 *   - More permissive, but less discoverable
 *   - Use when backward compatibility is critical
 * 
 * All parameters are optional and override global configuration when provided.
 */
```

### 6. Attribute Processing Integration

**Extend**: `src/Routing/AttributeRouteLoader.php`

Add to the existing attribute processing around line 50+:

```php
use Glueful\Routing\Attributes\Fields;

// In processClass method, after processing existing attributes
private function processMethodAttributes(\ReflectionMethod $method, Route $route): void
{
    // ... existing attribute processing ...
    
    // Process Fields attribute
    $fieldsAttributes = $method->getAttributes(Fields::class);
    if (!empty($fieldsAttributes)) {
        $fieldsAttr = $fieldsAttributes[0]->newInstance();
        $route->setFieldsConfig([
            'allowed' => $fieldsAttr->allowedFields,
            'default' => $fieldsAttr->defaultFields,
            'strict' => $fieldsAttr->strict,
            'max_depth' => $fieldsAttr->maxDepth
        ]);
    }
}
```

### 4. Enhanced Route Processing

**Extension to**: `src/Routing/AttributeRouteLoader.php`

Add field validation logic to process `#[Fields]` attributes and validate requested fields against allowed fields.

```php
private function processFieldsAttribute(\ReflectionMethod $method, Route $route): void
{
    $fieldsAttributes = $method->getAttributes(Fields::class);
    
    if (!empty($fieldsAttributes)) {
        $fieldsAttr = $fieldsAttributes[0]->newInstance();
        
        // Store field configuration on route for validation
        $route->setFieldsConfig([
            'allowed' => $fieldsAttr->allowedFields,
            'default' => $fieldsAttr->defaultFields,
            'strict' => $fieldsAttr->strict
        ]);
    }
}
```

### 5. Route Enhancement

**Extension to**: `src/Routing/Route.php`

```php
class Route
{
    private ?array $fieldsConfig = null;

    // ... existing code ...

    public function setFieldsConfig(array $config): void
    {
        $this->fieldsConfig = $config;
    }

    public function getFieldsConfig(): ?array
    {
        return $this->fieldsConfig;
    }
}
```

## Usage Examples

### Dual Syntax Support

**REST-style (traditional)**:
```
GET /users/123?fields=id,name,email&expand=posts.title,posts.comments.text
```

**GraphQL-style (nested)**:
```
GET /users/123?fields=user(id,name,email,posts(title,comments(text)))
```

### Controller Implementation with N+1 Prevention

```php
use Glueful\Support\FieldSelection\{FieldSelector, Projector};
use Glueful\Routing\Attributes\{Get, Fields};

#[Get('/users/{id}')]
#[Fields(allowed: ['id', 'name', 'email', 'profile', 'posts'], strict: true, maxDepth: 4)]
public function getUser(int $id, FieldSelector $selector, Projector $projector, UserRepository $repo): array
{
    // Conditional loading based on requested fields
    $user = $repo->findAsArray($id);
    
    if ($selector->requested('posts')) {
        $user['posts'] = $repo->findPostsForUser($id);
    }
    
    if ($selector->requested('posts.comments')) {
        // Batch load comments for all posts
        $postIds = array_column($user['posts'] ?? [], 'id');
        $comments = $repo->findCommentsForPosts($postIds);
        
        // Group comments by post_id
        $commentsByPost = [];
        foreach ($comments as $comment) {
            $commentsByPost[$comment['post_id']][] = $comment;
        }
        
        // Attach to posts
        foreach ($user['posts'] as &$post) {
            $post['comments'] = $commentsByPost[$post['id']] ?? [];
        }
    }

    // Middleware automatically applies field projection
    return $user;
}
```

### Advanced Expander Registration

```php
// In a service provider or controller setup
$projector->register('posts', function(array $context, FieldNode $node, array $rows) use ($repo) {
    // Context includes collection IDs for batch loading
    $userIds = $context['collection_ids']['item_ids'] ?? [];
    
    if (empty($userIds)) {
        return [];
    }
    
    // Batch load posts for all users
    $posts = $repo->findPostsByUserIds($userIds);
    
    // Group by user_id for efficient lookup
    $postsByUser = [];
    foreach ($posts as $post) {
        $postsByUser[$post['user_id']][] = $post;
    }
    
    return $postsByUser;
});
```

### Request Examples

**Basic Field Selection**:
```
GET /users/123?fields=id,name,email
```
**Response**: 
```json
{
    "id": 123,
    "name": "John Doe",
    "email": "john@example.com"
}
```

**GraphQL-style Nested**:
```
GET /users/123?fields=id,name,posts(title,comments(text,author.name))
```
**Response**:
```json
{
    "id": 123,
    "name": "John Doe",
    "posts": [
        {
            "title": "First Post",
            "comments": [
                {
                    "text": "Great post!",
                    "author": {"name": "Jane"}
                }
            ]
        }
    ]
}
```

**REST-style with Expand**:
```
GET /users/123?fields=*&expand=posts.title,posts.comments.text
```
**Response**:
```json
{
    "id": 123,
    "name": "John Doe",
    "email": "john@example.com",
    "created_at": "2023-01-01T00:00:00Z",
    "posts": [
        {
            "title": "First Post",
            "comments": [
                {"text": "Great post!"}
            ]
        }
    ]
}
```

## Integration Points

### 1. Service Provider Registration

Add to `src/Container/Providers/CoreProvider.php`:

```php
use Glueful\Support\FieldSelection\{FieldSelector, Projector};
use Glueful\Routing\Middleware\FieldSelectionMiddleware;

public function register(): void
{
    // ... existing registrations ...
    
    // Register field selection services
    $this->container->singleton(Projector::class, function ($container) {
        $cfg = \function_exists('config') ? (array)\config('api.field_selection', []) : [];
        $whitelist = (array)($cfg['whitelists'] ?? []);
        return new Projector(
            whitelist: $whitelist,
            strictDefault: (bool)($cfg['strict'] ?? false),
            maxDepthDefault: (int)($cfg['maxDepth'] ?? 6),
            maxFieldsDefault: (int)($cfg['maxFields'] ?? 200),
            maxItemsDefault: (int)($cfg['maxItems'] ?? 1000),
        );
    });
    
    // Register field selection middleware
    $this->container->singleton(FieldSelectionMiddleware::class);
    
    // Alias middleware
    $this->container->alias('field_selection', FieldSelectionMiddleware::class);
}
```

### 2. Router Parameter Injection

Extend `src/Routing/Router.php` parameter resolution around line 545:

```php
use Glueful\Support\FieldSelection\{FieldSelector, Projector};

// In resolveMethodParameters method 
if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
    $typeName = $type->getName();
    
    // ... existing DI logic ...
    
    // Add field selection support
    if ($typeName === FieldSelector::class) {
        $selector = $request->attributes->get(FieldSelector::class);
        if ($selector === null) {
            // Create default if not set by middleware
            $selector = FieldSelector::fromRequest($request);
        }
        $args[] = $selector;
        continue;
    }
    
    if ($typeName === Projector::class) {
        $projector = $this->container->get(Projector::class);
        $args[] = $projector;
        continue;
    }
    
    // ... rest of existing logic ...
}
```

### 3. Middleware Registration

Add to routes that should support field selection:

```php
// Global API middleware
$router->group(['middleware' => ['field_selection']], function ($router) {
    $router->get('/users/{id}', [UserController::class, 'show']);
    $router->get('/posts/{id}', [PostController::class, 'show']);
});

// With whitelist restrictions
$router->group([
    'middleware' => ['field_selection:whitelist=id,name,email']
], function ($router) {
    $router->get('/admin/users', [AdminController::class, 'users']);
});
```

### 4. Advanced Projector Setup

```php
use Glueful\Support\Projection\Projector;

// In a service provider or controller
$projector = new Projector([
    // Prevent N+1 on user posts
    'posts' => function(array $ctx, $value) use ($postRepo) {
        $userIds = [$ctx['route_params']['id']];
        return $postRepo->findByUserIds($userIds);
    },
    
    // Batch load comments for posts
    'comments' => function(array $ctx, $posts) use ($commentRepo) {
        if (!$posts) return [];
        $postIds = array_column((array)$posts, 'id');
        return $commentRepo->findByPostIds($postIds);
    },
    
    // Load user profiles
    'profile' => function(array $ctx, $value) use ($profileRepo) {
        $userId = $ctx['route_params']['id'];
        return $profileRepo->findByUserId($userId);
    },
], maxItems: 500);

// Override default projector for this request using container
$this->container->instance(Projector::class, $projector);
```

## Performance Considerations

### Benefits
- **Reduced Payload Size**: 30-70% smaller responses for typical API calls
- **Network Efficiency**: Fewer bytes over the wire
- **Parsing Speed**: Less JSON parsing on client side
- **Memory Usage**: Smaller memory footprint for large datasets
- **N+1 Prevention**: Expanders enable batch loading of relations
- **Query Optimization**: Only fetch needed data when integrated with ORM

### Built-in Protections
- **Max Depth**: Prevents deeply nested queries (default: 6 levels)
- **Max Items**: Limits collection size processing (default: 1000 items)
- **Whitelist Validation**: Restricts available fields per route
- **Safe Parsing**: Tokenizer ignores malformed syntax gracefully

### Optimizations
- **Tree Caching**: Field selection trees can be cached
- **Batch Loading**: Expanders prevent N+1 queries automatically
- **Early Filtering**: Filter at serialization level
- **Context Passing**: Enables intelligent expansion decisions

### N+1 Query Prevention

The expander system is the key innovation:

```php
// Without expanders: N+1 queries
foreach ($users as $user) {
    $user->posts; // Separate query per user
}

// With expanders: Single batch query
'posts' => function($ctx, $value) use ($repo) {
    $userIds = array_column($ctx['all_users'], 'id');
    return $repo->postsForUsers($userIds); // One query
}
```

## Security Features

### Built-in Security
- **Whitelist Enforcement**: Only permitted fields can be selected
- **Depth Limiting**: Prevents deep nesting DoS attacks
- **Size Limiting**: Max items protection against large payload DoS
- **Safe Parsing**: Tokenizer safely ignores malformed input
- **Input Sanitization**: Field names validated during parsing

### Field Whitelisting Examples

```php
// Middleware with whitelist
$router->group([
    'middleware' => ['field_selection:whitelist=id,name,email']
], function ($router) {
    // Only id, name, email can be selected
});

// Attribute-based restrictions  
#[Fields(['id', 'name', 'email', 'profile.avatar'], strict: true)]
public function getUser(int $id): array { ... }

// Runtime whitelist
$selector = FieldSelector::fromRequest(
    $request,
    maxDepth: 4,
    whitelist: ['id', 'name', 'email']
);
```

### Validation Features
- **Malformed Syntax**: GraphQL-style parser handles errors gracefully
- **Injection Prevention**: Field names are validated as identifiers
- **Resource Limits**: Configurable depth and item count limits
- **Performance Monitoring**: Track parsing time and memory usage

## Migration Strategy

### Phase 1: Core Implementation
1. Implement `FieldSelector` service
2. Create middleware
3. Add attribute support
4. Basic integration tests

### Phase 2: Framework Integration  
1. Extend router parameter injection
2. Add route attribute processing
3. Integrate with existing middleware pipeline
4. Performance testing

### Phase 3: Advanced Features
1. Database query optimization
2. Caching layer
3. OpenAPI documentation generation
4. CLI tools for field analysis

## Testing Strategy

### Unit Tests
- `FieldSelector` field filtering logic
- Attribute parsing and validation
- Middleware request/response processing

### Integration Tests  
- End-to-end API requests with field selection
- Nested object and array handling
- Error cases and edge conditions

### Performance Tests
- Response time comparison with/without field selection
- Memory usage profiling
- Large dataset handling

## Configuration

### Environment Variables
```env
# Field selection configuration
FIELD_SELECTION_ENABLED=true
FIELD_SELECTION_MAX_DEPTH=5
FIELD_SELECTION_MAX_FIELDS=50
```

### Config File: `config/api.php`
```php
return [
    'field_selection' => [
        'enabled' => env('FIELD_SELECTION_ENABLED', true),
        'max_depth' => env('FIELD_SELECTION_MAX_DEPTH', 5),
        'max_fields' => env('FIELD_SELECTION_MAX_FIELDS', 50),
        'default_fields' => [],
        'global_middleware' => true,
    ],
];
```

## Quick Nits (Easy Wins)

### 1. JSON Response Guard
```php
// In middleware - only process JSON responses
if (!str_contains($response->headers->get('Content-Type', ''), 'json')) {
    return $response; // Skip HTML/streams/binary
}
```

### 2. Wildcard + Whitelist Precedence
```php
// Ensure fields=* + whitelist expands * to whitelist explicitly
if ($tree['*'] ?? false) {
    $new = ['*' => false];
    foreach ($whitelist as $field) {
        $new[$field] = $tree[$field] ?? true;
    }
    return $new;
}
```

### 3. Max-Fields Guard
```php
// In FieldSelector::fromRequest() - count leaf nodes
$leafCount = count(array_filter($tree, fn($v) => $v === true));
if ($leafCount > $maxFields) {
    throw new FieldSelectionException(
        "Too many fields requested: {$leafCount} (max: {$maxFields})"
    );
}
```

### 4. Dotted Identifier Validation
```php
// In tokenize() - reject invalid characters early
if (ctype_alnum($ch) || $ch === '_' || $ch === '.') {
    // Validate dotted path doesn't contain invalid sequences
    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $identifier)) {
        $out[] = $identifier;
    }
}
```

### 5. Consistent Error Format
```php
// Match framework's global error schema exactly
return new JsonResponse([
    'success' => false,
    'message' => 'Invalid fields requested', 
    'code' => 400,
    'error_code' => 'INVALID_FIELDS',
    'details' => [
        'invalid_fields' => $invalidFields,
        'available_fields' => $allowedFields
    ]
], 400);

// Note: Ensure this matches Glueful\Http\SecureErrorResponse format
// for consistent client experience across all API errors
```

### 6. Observability Hook
```php
// Debug header with size limits
if ($request->query->get('fields_debug') && !$response->headers->hasCacheControlDirective('public')) {
    $treeJson = json_encode($selector->tree());
    $truncated = strlen($treeJson) > 1024 ? substr($treeJson, 0, 1021) . '...' : $treeJson;
    $response->headers->set('X-Fields-Tree', $truncated);
}
```

## Production Enhancements (Recommended)

### Phase 1: Performance & Security (High Priority)

**1. Fast-Path Optimization:**
```php
// In FieldSelectionMiddleware::handle()
public function handle(Request $request, callable $next, mixed ...$params): mixed
{
    $fields = trim((string) $request->query->get('fields', ''));
    $expand = trim((string) $request->query->get('expand', ''));
    
    // Fast-path: skip processing if no field selection requested
    if ($fields === '' && $expand === '') {
        return $next($request);
    }
    
    // Cache parsed tree per request signature
    $signature = md5($fields . '|' . $expand);
    if (!$request->attributes->has('_field_sig') || 
        $request->attributes->get('_field_sig') !== $signature) {
        
        $selector = FieldSelector::fromRequest($request, /* params */);
        $request->attributes->set('_field_tree', $selector->tree());
        $request->attributes->set('_field_sig', $signature);
    }
    
    // ... rest of processing
}
```

**2. Security Limits:**
```php
// In FieldSelector::fromRequest()
if (count(array_keys($tree, true, true)) > $maxFields) {
    throw new FieldSelectionException(
        "Too many fields requested (max: {$maxFields})"
    );
}
```

**3. Safer JSON Handling:**
```php
// Only process JSON responses
if (!str_contains($response->headers->get('Content-Type', ''), 'json')) {
    return $response;
}

try {
    $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    return $response; // Keep original if JSON invalid
}
```

### Phase 2: Developer Experience (Medium Priority)

**1. Enhanced Controller Helper:**
```php
// Add to FieldSelector class
public function requested(string $path): bool
{
    return $this->pathExistsInTree($path, $this->tree);
}

// Usage in controllers
public function getUser(int $id, FieldSelector $fields): array
{
    $user = User::find($id);
    
    if ($fields->requested('posts')) {
        $user->load('posts');
    }
    
    return $user->toArray();
}
```

**2. Whitelist Wildcards:**
```php
// Support in Fields attribute
#[Fields(['id', 'name', 'posts.*', 'profile.*'])]
public function getUser(): array { /* ... */ }

// Expand during whitelist application
if (str_ends_with($whitelistField, '.*')) {
    $prefix = rtrim($whitelistField, '.*');
    // Allow any field under this prefix
}
```

**3. Collection Context Enhancement:**
```php
// Pass collection IDs to expanders for better batching
$context = [
    'route' => $request->attributes->get('_route'),
    'user' => $request->attributes->get('user'),
    'route_params' => $request->attributes->get('_route_params'),
    'collection_ids' => $this->extractIds($payload) // New!
];
```

### Phase 3: Advanced Features (Nice to Have)

**1. Debug Mode:**
```php
// Add debug header when ?fields_debug=1
if ($request->query->get('fields_debug')) {
    $response->headers->set(
        'X-Fields-Tree', 
        substr(json_encode($selector->tree()), 0, 1000)
    );
}
```

**2. Validation with Error Response:**
```php
// In strict mode, return 400 for invalid fields
if ($strict && !empty($invalidFields)) {
    return new JsonResponse([
        'error' => 'Invalid fields requested',
        'invalid_fields' => $invalidFields,
        'available_fields' => $allowedFields
    ], 400);
}
```

## Future Enhancements (Low Priority)

### Advanced Query Features
- **Field Aliases**: `?fields=id,name:full_name,email:contact_email`
- **Computed Fields**: `?fields=id,name,@full_name,@post_count`
- **Field Transformations**: `?fields=created_at:format(Y-m-d),price:currency(USD)`
- **Conditional Fields**: `?fields=id,name,email:if(public),admin_notes:if(admin)`

### Advanced Expanders
- **Conditional Loading**: Load relations based on context
- **Aggregation Support**: `?fields=user,post_count,comment_count`
- **Cross-Service Fields**: Load fields from microservices
- **Cached Expanders**: Cache expensive computations

## Conclusion

This enhanced implementation provides production-ready field selection with significant advantages over the original plan:

### Key Improvements
- **Dual Syntax Support**: Both REST-style (`expand=`) and GraphQL-style (`fields=user(...)`) 
- **N+1 Prevention**: Built-in expander system for batch loading relations
- **Production Security**: Comprehensive whitelisting, depth limits, and input validation
- **Performance Protection**: Max item limits, safe parsing, and resource monitoring
- **Context-Aware**: Expanders receive route params, user context for intelligent decisions
- **Framework Agnostic**: Core components in neutral namespace for maximum reusability

### Architecture Benefits  
- **Separation of Concerns**: FieldSelector (parsing) + Projector (application)
- **Zero N+1 Queries**: Expander system enables efficient data loading
- **Framework Integration**: Seamless integration with existing router and DI
- **Reusable Components**: `Glueful\Support\Projection` namespace enables future GraphQL extensions
- **Developer Experience**: Simple setup with powerful capabilities
- **Future-Proof**: Extensible design for advanced query features

### Future Extension Possibilities
With components in `Glueful\Support\Projection`, the same field selection logic can be leveraged for:
- **GraphQL Server Extension**: Reuse `Projector` for GraphQL field resolution
- **Database Query Optimization**: Use `FieldSelector` to optimize ORM queries
- **API Gateway**: Apply projection at gateway level for microservice orchestration
- **Cache Optimization**: Project cached data based on client requirements
- **Export/Import Tools**: Selective data export based on field selection patterns

### Performance Characteristics
- **Minimal Overhead**: Only processes when field selection is requested
- **Efficient Parsing**: Single-pass tokenizer with O(n) complexity  
- **Memory Efficient**: Streaming-friendly with configurable limits
- **Cache-Friendly**: Field trees and expander results can be cached

### Production Readiness
- **Security**: Comprehensive input validation and whitelisting
- **Reliability**: Graceful error handling and safe parsing
- **Observability**: Built-in limits and monitoring capabilities  
- **Maintainability**: Clean separation between parsing and projection logic

## Final Implementation Punch-List

### Phase 1: Core Components
- ✅ Add `FieldSelector` + `Projector` in `Glueful\Support\Projection`
- ✅ Include `max_fields`, `max_depth`, `max_items` from `config/api.php`
- ✅ Implement dual syntax parsing (REST + GraphQL-style)

### Phase 2: Middleware Integration  
- ✅ `Glueful\Routing\Middleware\FieldSelectionMiddleware` that:
  - Skips non-JSON responses
  - Fast-path skip when fields/expand empty
  - Uses request-signature caching
  - Emits `X-Fields-Tree` in debug mode (truncated)
- ✅ Register in core provider with global API group alias `field_selection`

### Phase 3: Routing Integration
- ✅ Keep middleware under `Routing` (protocol-agnostic, pipeline-bound)
- ✅ Extend parameter injection for `FieldSelector`/`Projector` in controllers
- ✅ No manual container fetches needed

### Phase 4: Attributes System
- ✅ Implement `#[Fields(...)]` and persist route-level config on `Route`
- ✅ In middleware: validate against route `Fields` config if `strict` enabled
- ✅ Short-circuit with 400 on invalid fields (framework error format)

### Phase 5: Developer Experience
- ✅ Document expander pattern: route params + collection IDs in context
- ✅ Provide `FieldSelector::requested('rel.subrel')` helper
- ✅ Enable conditional eager-loading in controllers

### Phase 6: Testing Strategy
**Unit Tests:**
- Parser parity (REST & GraphQL syntax → same tree)
- Whitelist behavior, depth limits, `*` expansion
- Field count validation, dotted identifier handling

**Middleware Tests:**
- Non-JSON pass-through, JSON projection
- Strict mode error responses, debug headers
- Request caching, fast-path behavior

**Integration Tests:**
- End-to-end requests with expanders
- Verify batch loading (no N+1 queries)
- Performance under various field selection patterns

**Performance Baseline Tests:**
- Large field expansions (1000 users × 10 posts) project under 100ms
- Regression protection for complex nested projections
- Memory usage stays bounded under high field counts

## End State Example

```php
<?php

use Glueful\Routing\Attributes\{Get, Fields};
use Glueful\Support\FieldSelection\{FieldSelector, Projector};

class UserController extends BaseController
{
    #[Get('/users/{id}')]
    #[Fields(
        allowed: [
            'id', 'name', 'email', 'profile', 'posts', 
            'posts.title', 'posts.comments', 'posts.comments.text'
        ],
        strict: true, // Undeclared fields are rejected with 400 error
        maxDepth: 6,
        maxFields: 50
    )]
    public function show(
        int $id, 
        FieldSelector $fields, 
        Projector $projector, 
        UserRepository $repo
    ): array {
        $user = $repo->findAsArray($id);

        // Conditional eager-loading based on requested fields
        if ($fields->requested('posts')) {
            $user['posts'] = $repo->findPostsForUser($id);
        }
        
        if ($fields->requested('posts.comments')) {
            // Batch load comments for all posts  
            $postIds = array_column($user['posts'] ?? [], 'id');
            $comments = $repo->findCommentsForPosts($postIds);
            
            // Group comments by post_id
            $commentsByPost = [];
            foreach ($comments as $comment) {
                $commentsByPost[$comment['post_id']][] = $comment;
            }
            
            // Attach to posts
            foreach ($user['posts'] as &$post) {
                $post['comments'] = $commentsByPost[$post['id']] ?? [];
            }
        }

        // Middleware automatically applies field projection
        return $user;
    }
    
    // Alternative: Using factory method directly
    public function index(Request $request, UserRepository $repo): array
    {
        $selector = FieldSelector::fromRequest($request, whitelist: ['id', 'name', 'email']);
        
        $users = $repo->findAllAsArray();
        
        if ($selector->requested('profile')) {
            // Load profiles for all users
            $userIds = array_column($users, 'id');
            $profiles = $repo->findProfilesForUsers($userIds);
            
            $profilesByUser = [];
            foreach ($profiles as $profile) {
                $profilesByUser[$profile['user_id']] = $profile;
            }
            
            foreach ($users as &$user) {
                $user['profile'] = $profilesByUser[$user['id']] ?? null;
            }
        }
        
        return $users;
    }
}
```

### Request Examples:

**Basic REST-style:**
```
GET /users/123?fields=id,name,email
```

**GraphQL-style nested:**
```
GET /users/123?fields=id,name,posts(title,comments(text))
```

**REST-style with expand:**
```
GET /users/123?fields=*&expand=posts.title,posts.comments.text
```

**All return optimized, projected responses with N+1 prevention built-in.**

---

The implementation transforms REST endpoints into GraphQL-like experiences while maintaining REST's simplicity and the framework's high-performance characteristics.
