# Search & Filtering DSL Implementation Plan

> A comprehensive plan for implementing an advanced search and filtering domain-specific language (DSL) with support for complex queries, full-text search, and search engine integration in Glueful Framework.

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Goals and Non-Goals](#goals-and-non-goals)
3. [Current State Analysis](#current-state-analysis)
4. [Architecture Design](#architecture-design)
5. [Filter Syntax](#filter-syntax)
6. [Query Filter Classes](#query-filter-classes)
7. [Search Integration](#search-integration)
8. [Implementation Phases](#implementation-phases)
9. [Testing Strategy](#testing-strategy)
10. [API Reference](#api-reference)

---

## Executive Summary

This document outlines the implementation of a powerful search and filtering DSL for Glueful Framework. The system enables advanced data querying through a standardized URL query syntax, supporting complex filters, sorting, full-text search, and integration with search engines like Elasticsearch and Meilisearch.

### Key Features

- **Filter DSL**: Standardized query parameter syntax (`filter[field][operator]=value`)
- **Comparison Operators**: `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `contains`, `in`, `between`, etc.
- **Full-Text Search**: Integrated search with configurable searchable fields
- **Sorting**: Multi-column sorting with direction (`-created_at` for descending)
- **Custom Filters**: Extensible filter classes for complex logic
- **Search Engine Adapters**: Elasticsearch, Meilisearch, Algolia integration

---

## Goals and Non-Goals

### Goals

- ✅ Standardized filter query syntax (JSON:API inspired)
- ✅ Rich set of comparison operators
- ✅ Full-text search with field specification
- ✅ Multi-column sorting with direction indicators
- ✅ Custom filter classes for complex filtering logic
- ✅ Attribute-based configuration on controllers
- ✅ Search engine adapters (Elasticsearch, Meilisearch)
- ✅ Security: field whitelisting, depth limiting

### Non-Goals

- ❌ GraphQL query language (separate concern)
- ❌ OData/OASIS query syntax
- ❌ Real-time search updates (WebSocket)
- ❌ Search analytics/autocomplete
- ❌ Geospatial queries (future extension)

---

## Current State Analysis

### Existing Filtering Support

Glueful has basic field selection through the FieldSelector:

```php
// Current approach - field selection middleware
GET /users?fields=id,name,email

// Basic where clause in controllers
$users = User::query()
    ->where('status', $request->query->get('status'))
    ->get();
```

### Limitations

| Limitation | Impact |
|------------|--------|
| No standardized syntax | Each endpoint has custom logic |
| No comparison operators | Only equality matching |
| No full-text search | Manual LIKE queries needed |
| No sorting syntax | Custom sort parameter per endpoint |
| No reusable filter logic | Duplicate code across controllers |

---

## Architecture Design

### Component Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                        Request                                   │
│  GET /users?filter[status]=active&filter[age][gte]=18&sort=-name│
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                   FilterMiddleware                               │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                  FilterParser                            │   │
│  │  • Parse filter[field][op]=value syntax                  │   │
│  │  • Parse sort parameter                                  │   │
│  │  • Parse search parameter                                │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Controller                                  │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │              QueryFilter (e.g., UserFilter)              │   │
│  │  • Apply filters to query builder                        │   │
│  │  • Custom filter methods                                 │   │
│  │  • Searchable field definitions                          │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              │
                    ┌─────────┴─────────┐
                    │                   │
                    ▼                   ▼
          ┌─────────────────┐  ┌─────────────────┐
          │   QueryBuilder  │  │  SearchAdapter  │
          │  (Database)     │  │ (Elasticsearch) │
          └─────────────────┘  └─────────────────┘
```

### Directory Structure

```
src/Api/Filtering/
├── Contracts/
│   ├── FilterableInterface.php        # Model filterable contract
│   ├── SearchableInterface.php        # Model searchable contract
│   └── FilterOperatorInterface.php    # Operator contract
├── QueryFilter.php                    # Base filter class
├── FilterParser.php                   # Parse URL parameters
├── FilterBuilder.php                  # Build query conditions
├── SortParser.php                     # Parse sort parameters
├── SearchAdapter.php                  # Base search adapter
├── Operators/
│   ├── EqualOperator.php              # eq, =
│   ├── NotEqualOperator.php           # ne, !=
│   ├── GreaterThanOperator.php        # gt, >
│   ├── GreaterThanOrEqualOperator.php # gte, >=
│   ├── LessThanOperator.php           # lt, <
│   ├── LessThanOrEqualOperator.php    # lte, <=
│   ├── ContainsOperator.php           # contains, like
│   ├── StartsWithOperator.php         # starts, prefix
│   ├── EndsWithOperator.php           # ends, suffix
│   ├── InOperator.php                 # in
│   ├── NotInOperator.php              # nin, not_in
│   ├── BetweenOperator.php            # between
│   ├── NullOperator.php               # null, is_null
│   ├── NotNullOperator.php            # not_null
│   └── OperatorRegistry.php           # Register operators
├── Attributes/
│   ├── Filterable.php                 # Mark field as filterable
│   ├── Searchable.php                 # Mark field as searchable
│   └── Sortable.php                   # Mark field as sortable
├── Adapters/
│   ├── ElasticsearchAdapter.php       # Elasticsearch integration
│   ├── MeilisearchAdapter.php         # Meilisearch integration
│   └── DatabaseAdapter.php            # Default database adapter
├── Middleware/
│   └── FilterMiddleware.php           # Request processing
└── Exceptions/
    ├── InvalidFilterException.php
    └── InvalidOperatorException.php
```

### Auto-Migration Pattern (Search Index Tracking)

The filtering DSL works out of the box with **no database tables required**. However, when using external search engines (Elasticsearch, Meilisearch), an optional tracking table is **automatically created at runtime** following the pattern from `DatabaseLogHandler`.

#### When Is the Table Needed?

| Scenario | Table Required? |
|----------|-----------------|
| Basic filtering (`filter[status]=active`) | No |
| Sorting (`sort=-created_at`) | No |
| Database LIKE search | No |
| Elasticsearch/Meilisearch search | **Auto-created** (for sync tracking) |
| Incremental re-indexing | **Auto-created** |

#### Implementation

```php
<?php

namespace Glueful\Api\Filtering\Adapters;

use Glueful\Database\Connection;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Base search adapter with auto-migration
 */
abstract class SearchAdapter
{
    protected SchemaBuilderInterface $schema;
    protected Connection $db;
    protected bool $tableEnsured = false;

    public function __construct()
    {
        $connection = new Connection();
        $this->schema = $connection->getSchemaBuilder();
        $this->db = $connection;
    }

    /**
     * Index a document (ensures table exists first)
     */
    public function index(string $id, array $document): void
    {
        $this->ensureTable();

        // ... indexing logic
        $this->logIndexed($id, $document);
    }

    /**
     * Ensure search index tracking table exists
     *
     * Following the pattern from DatabaseLogHandler, the table is created
     * automatically at runtime when first needed.
     */
    protected function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        if (!$this->schema->hasTable('search_index_log')) {
            $table = $this->schema->table('search_index_log');

            $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
            $table->string('indexable_type', 255);    // Model class name
            $table->string('indexable_id', 36);       // Model ID (UUID or int)
            $table->string('index_name', 255);        // Search engine index name
            $table->timestamp('indexed_at')->default('CURRENT_TIMESTAMP');
            $table->string('checksum', 64)->nullable(); // For change detection

            // Composite unique index for upsert operations
            $table->unique(['indexable_type', 'indexable_id', 'index_name']);
            $table->index('indexed_at');

            $table->create();
            $this->schema->execute();
        }

        $this->tableEnsured = true;
    }

    /**
     * Log that a record has been indexed
     */
    protected function logIndexed(string $id, array $document): void
    {
        $this->db->table('search_index_log')->upsert([
            'indexable_type' => $document['_type'] ?? 'unknown',
            'indexable_id' => $id,
            'index_name' => $this->getIndexName(),
            'indexed_at' => date('Y-m-d H:i:s'),
            'checksum' => md5(json_encode($document)),
        ], ['indexable_type', 'indexable_id', 'index_name']);
    }

    abstract protected function getIndexName(): string;
}
```

#### Elasticsearch Adapter Example

```php
<?php

namespace Glueful\Api\Filtering\Adapters;

class ElasticsearchAdapter extends SearchAdapter
{
    private \Elastic\Elasticsearch\Client $client;
    private string $index;

    public function __construct(string $index)
    {
        parent::__construct();

        $this->index = $index;
        $this->client = \Elastic\Elasticsearch\ClientBuilder::create()
            ->setHosts([config('search.elasticsearch.host', 'localhost:9200')])
            ->build();
    }

    public function index(string $id, array $document): void
    {
        // Ensure tracking table exists
        $this->ensureTable();

        // Index in Elasticsearch
        $this->client->index([
            'index' => $this->index,
            'id' => $id,
            'body' => $document,
        ]);

        // Log to tracking table
        $this->logIndexed($id, array_merge($document, ['_type' => $document['_type'] ?? 'document']));
    }

    public function search(string $query, array $options = []): SearchResult
    {
        // Search doesn't need the tracking table
        $response = $this->client->search([
            'index' => $this->index,
            'body' => $this->buildQuery($query, $options),
        ]);

        return new SearchResult(
            hits: array_map(fn($hit) => $hit['_source'], $response['hits']['hits']),
            total: $response['hits']['total']['value'],
            took: $response['took'],
        );
    }

    protected function getIndexName(): string
    {
        return $this->index;
    }
}
```

#### Benefits

| Benefit | Description |
|---------|-------------|
| Zero configuration | Table created automatically when search engine is used |
| No install command | Works out of the box |
| Self-healing | If table is dropped, it's recreated on next index operation |
| Opt-in complexity | Basic filtering works without any database tables |

#### Table Schema

**`search_index_log`** (auto-created when using search engines):

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT | Primary key |
| `indexable_type` | VARCHAR(255) | Model class name |
| `indexable_id` | VARCHAR(36) | Model ID (supports UUID) |
| `index_name` | VARCHAR(255) | Search engine index name |
| `indexed_at` | TIMESTAMP | When record was indexed |
| `checksum` | VARCHAR(64) | MD5 hash for change detection |

> **Note:** This table is only created when you use a search adapter (Elasticsearch, Meilisearch). Basic filtering and database LIKE searches work without any tables.

---

## Filter Syntax

### URL Query Parameter Syntax

#### Basic Equality

```
GET /users?filter[status]=active
GET /users?filter[role]=admin
```

#### Comparison Operators

```
GET /users?filter[age][gte]=18
GET /users?filter[created_at][lt]=2026-01-01
GET /users?filter[price][between]=10,100
```

#### Multiple Values (IN)

```
GET /users?filter[status][in]=active,pending,review
GET /products?filter[category][nin]=archived,deleted
```

#### Text Search

```
GET /users?filter[name][contains]=john
GET /users?filter[email][starts]=admin@
GET /posts?filter[title][ends]=2026
```

#### Null Checks

```
GET /users?filter[deleted_at][null]
GET /users?filter[verified_at][not_null]
```

#### Boolean Values

```
GET /users?filter[is_admin]=true
GET /users?filter[is_active]=1
```

### Sorting Syntax

```
# Ascending (default)
GET /users?sort=name

# Descending (prefix with -)
GET /users?sort=-created_at

# Multiple columns
GET /users?sort=status,-created_at,name
```

### Full-Text Search

```
# Search all searchable fields
GET /users?search=john doe

# Search specific fields
GET /posts?search=php api&search_fields=title,body

# Combined with filters
GET /posts?search=tutorial&filter[status]=published&sort=-views
```

---

## Supported Operators

| Operator | Alias | Description | Example |
|----------|-------|-------------|---------|
| `eq` | `=`, (none) | Equal to | `filter[status]=active` |
| `ne` | `!=`, `neq` | Not equal to | `filter[status][ne]=deleted` |
| `gt` | `>` | Greater than | `filter[age][gt]=18` |
| `gte` | `>=` | Greater than or equal | `filter[age][gte]=21` |
| `lt` | `<` | Less than | `filter[price][lt]=100` |
| `lte` | `<=` | Less than or equal | `filter[price][lte]=50` |
| `contains` | `like` | Contains substring | `filter[name][contains]=john` |
| `starts` | `prefix` | Starts with | `filter[email][starts]=admin` |
| `ends` | `suffix` | Ends with | `filter[email][ends]=.com` |
| `in` | | In array | `filter[status][in]=a,b,c` |
| `nin` | `not_in` | Not in array | `filter[status][nin]=x,y` |
| `between` | `range` | Between two values | `filter[price][between]=10,100` |
| `null` | `is_null` | Is null | `filter[deleted_at][null]` |
| `not_null` | | Is not null | `filter[verified_at][not_null]` |

---

## Query Filter Classes

### Base QueryFilter Class

```php
<?php

namespace Glueful\Api\Filtering;

use Glueful\Database\Query\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base class for model-specific query filters
 */
abstract class QueryFilter
{
    protected QueryBuilder $query;
    protected Request $request;
    protected FilterParser $parser;

    /**
     * Fields allowed for filtering
     * Override in subclass to restrict filterable fields
     *
     * @var array<string>|null Null means all fields allowed
     */
    protected ?array $filterable = null;

    /**
     * Fields allowed for sorting
     *
     * @var array<string>|null Null means all fields allowed
     */
    protected ?array $sortable = null;

    /**
     * Fields included in full-text search
     *
     * @var array<string>
     */
    protected array $searchable = [];

    /**
     * Default sort when none specified
     */
    protected ?string $defaultSort = null;

    /**
     * Maximum filter depth (prevents nested attack)
     */
    protected int $maxDepth = 3;

    /**
     * Maximum number of filters
     */
    protected int $maxFilters = 20;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->parser = new FilterParser($this->maxDepth, $this->maxFilters);
    }

    /**
     * Apply filters to a query builder
     */
    public function apply(QueryBuilder $query): QueryBuilder
    {
        $this->query = $query;

        // Apply filters
        $filters = $this->parser->parseFilters($this->request);
        foreach ($filters as $filter) {
            $this->applyFilter($filter);
        }

        // Apply search
        $search = $this->parser->parseSearch($this->request);
        if ($search !== null) {
            $this->applySearch($search);
        }

        // Apply sorting
        $sorts = $this->parser->parseSorts($this->request);
        if ($sorts === []) {
            $sorts = $this->getDefaultSort();
        }
        foreach ($sorts as $sort) {
            $this->applySort($sort);
        }

        return $this->query;
    }

    /**
     * Apply a single filter
     */
    protected function applyFilter(ParsedFilter $filter): void
    {
        // Check if field is filterable
        if (!$this->isFilterable($filter->field)) {
            return;
        }

        // Check for custom filter method
        $method = 'filter' . $this->studly($filter->field);
        if (method_exists($this, $method)) {
            $this->$method($filter->value, $filter->operator);
            return;
        }

        // Apply standard filter
        $this->applyStandardFilter($filter);
    }

    /**
     * Apply standard filter using operator
     */
    protected function applyStandardFilter(ParsedFilter $filter): void
    {
        $operator = OperatorRegistry::get($filter->operator);
        $operator->apply($this->query, $filter->field, $filter->value);
    }

    /**
     * Apply full-text search
     */
    protected function applySearch(string $search): void
    {
        $fields = $this->getSearchableFields();

        if ($fields === []) {
            return;
        }

        $this->query->where(function (QueryBuilder $query) use ($search, $fields) {
            foreach ($fields as $i => $field) {
                $method = $i === 0 ? 'where' : 'orWhere';
                $query->$method($field, 'LIKE', "%{$search}%");
            }
        });
    }

    /**
     * Apply sorting
     */
    protected function applySort(ParsedSort $sort): void
    {
        if (!$this->isSortable($sort->field)) {
            return;
        }

        $this->query->orderBy($sort->field, $sort->direction);
    }

    /**
     * Check if field is filterable
     */
    protected function isFilterable(string $field): bool
    {
        if ($this->filterable === null) {
            return true;
        }

        return in_array($field, $this->filterable, true);
    }

    /**
     * Check if field is sortable
     */
    protected function isSortable(string $field): bool
    {
        if ($this->sortable === null) {
            return true;
        }

        return in_array($field, $this->sortable, true);
    }

    /**
     * Get searchable fields
     */
    protected function getSearchableFields(): array
    {
        $requestFields = $this->request->query->get('search_fields');

        if ($requestFields !== null) {
            $requestFields = explode(',', $requestFields);
            return array_intersect($requestFields, $this->searchable);
        }

        return $this->searchable;
    }

    /**
     * Get default sort
     *
     * @return ParsedSort[]
     */
    protected function getDefaultSort(): array
    {
        if ($this->defaultSort === null) {
            return [];
        }

        return $this->parser->parseSortString($this->defaultSort);
    }

    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }
}
```

### Custom Filter Example

```php
<?php

namespace App\Filters;

use Glueful\Api\Filtering\QueryFilter;
use Glueful\Database\Query\QueryBuilder;

class UserFilter extends QueryFilter
{
    /**
     * Allowed filterable fields
     */
    protected ?array $filterable = [
        'status',
        'role',
        'created_at',
        'email_verified_at',
        'subscription_tier',
    ];

    /**
     * Allowed sortable fields
     */
    protected ?array $sortable = [
        'name',
        'email',
        'created_at',
        'last_login_at',
    ];

    /**
     * Full-text searchable fields
     */
    protected array $searchable = [
        'name',
        'email',
        'bio',
    ];

    /**
     * Default sort
     */
    protected ?string $defaultSort = '-created_at';

    /**
     * Custom filter: status with "any" support
     */
    public function filterStatus(string|array $value, string $operator): void
    {
        if ($value === 'any') {
            return; // No filter
        }

        if (is_array($value) || str_contains($value, ',')) {
            $values = is_array($value) ? $value : explode(',', $value);
            $this->query->whereIn('status', $values);
        } else {
            $this->query->where('status', $value);
        }
    }

    /**
     * Custom filter: verified users
     */
    public function filterVerified(bool $value, string $operator): void
    {
        if ($value) {
            $this->query->whereNotNull('email_verified_at');
        } else {
            $this->query->whereNull('email_verified_at');
        }
    }

    /**
     * Custom filter: subscription tier with hierarchy
     */
    public function filterSubscriptionTier(string $value, string $operator): void
    {
        $tiers = ['free' => 0, 'pro' => 1, 'enterprise' => 2];

        if ($operator === 'gte') {
            $minLevel = $tiers[$value] ?? 0;
            $allowedTiers = array_filter(
                $tiers,
                fn($level) => $level >= $minLevel
            );
            $this->query->whereIn('subscription_tier', array_keys($allowedTiers));
        } else {
            $this->query->where('subscription_tier', $value);
        }
    }

    /**
     * Custom filter: date range shortcuts
     */
    public function filterCreatedAt(string $value, string $operator): void
    {
        // Handle shortcuts
        $date = match ($value) {
            'today' => now()->startOfDay(),
            'yesterday' => now()->subDay()->startOfDay(),
            'this_week' => now()->startOfWeek(),
            'this_month' => now()->startOfMonth(),
            'this_year' => now()->startOfYear(),
            default => $value,
        };

        match ($operator) {
            'eq' => $this->query->whereDate('created_at', $date),
            'gte' => $this->query->where('created_at', '>=', $date),
            'lte' => $this->query->where('created_at', '<=', $date),
            'gt' => $this->query->where('created_at', '>', $date),
            'lt' => $this->query->where('created_at', '<', $date),
            default => $this->query->where('created_at', $date),
        };
    }
}
```

### Controller Usage

```php
<?php

namespace App\Http\Controllers;

use App\Filters\UserFilter;
use App\Http\Resources\UserResource;
use Glueful\Api\Filtering\Attributes\Filterable;
use Glueful\Api\Filtering\Attributes\Searchable;
use Glueful\Http\Controllers\Controller;

class UserController extends Controller
{
    #[Filterable(['status', 'role', 'created_at', 'subscription_tier'])]
    #[Searchable(['name', 'email', 'bio'])]
    public function index(UserFilter $filter): Response
    {
        $users = User::query()
            ->tap(fn($query) => $filter->apply($query))
            ->paginate();

        return UserResource::collection($users);
    }
}
```

---

## Filter Parser

```php
<?php

namespace Glueful\Api\Filtering;

use Glueful\Api\Filtering\Exceptions\InvalidFilterException;
use Symfony\Component\HttpFoundation\Request;

class FilterParser
{
    private const FILTER_PARAM = 'filter';
    private const SORT_PARAM = 'sort';
    private const SEARCH_PARAM = 'search';

    public function __construct(
        private readonly int $maxDepth = 3,
        private readonly int $maxFilters = 20,
    ) {}

    /**
     * Parse filter parameters from request
     *
     * @return ParsedFilter[]
     */
    public function parseFilters(Request $request): array
    {
        $filterParam = $request->query->all()[self::FILTER_PARAM] ?? [];

        if (!is_array($filterParam)) {
            return [];
        }

        $filters = [];
        $this->parseFilterArray($filterParam, '', $filters, 0);

        if (count($filters) > $this->maxFilters) {
            throw new InvalidFilterException(
                "Maximum number of filters ({$this->maxFilters}) exceeded"
            );
        }

        return $filters;
    }

    /**
     * Recursively parse filter array
     */
    private function parseFilterArray(
        array $data,
        string $prefix,
        array &$filters,
        int $depth
    ): void {
        if ($depth > $this->maxDepth) {
            throw new InvalidFilterException(
                "Maximum filter depth ({$this->maxDepth}) exceeded"
            );
        }

        foreach ($data as $key => $value) {
            $field = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                // Check if this is an operator array
                $operators = OperatorRegistry::getAliases();
                $isOperator = count(array_intersect(array_keys($value), $operators)) > 0;

                if ($isOperator) {
                    foreach ($value as $operator => $operatorValue) {
                        $filters[] = new ParsedFilter(
                            field: $prefix ?: $key,
                            operator: $operator,
                            value: $operatorValue,
                        );
                    }
                } else {
                    // Nested field
                    $this->parseFilterArray($value, $field, $filters, $depth + 1);
                }
            } else {
                // Simple equality filter
                $filters[] = new ParsedFilter(
                    field: $field,
                    operator: 'eq',
                    value: $value,
                );
            }
        }
    }

    /**
     * Parse sort parameter
     *
     * @return ParsedSort[]
     */
    public function parseSorts(Request $request): array
    {
        $sortParam = $request->query->get(self::SORT_PARAM);

        if ($sortParam === null) {
            return [];
        }

        return $this->parseSortString($sortParam);
    }

    /**
     * Parse sort string
     *
     * @return ParsedSort[]
     */
    public function parseSortString(string $sortString): array
    {
        $sorts = [];
        $fields = explode(',', $sortString);

        foreach ($fields as $field) {
            $field = trim($field);
            if ($field === '') {
                continue;
            }

            if (str_starts_with($field, '-')) {
                $sorts[] = new ParsedSort(
                    field: substr($field, 1),
                    direction: 'DESC'
                );
            } else {
                $sorts[] = new ParsedSort(
                    field: $field,
                    direction: 'ASC'
                );
            }
        }

        return $sorts;
    }

    /**
     * Parse search parameter
     */
    public function parseSearch(Request $request): ?string
    {
        $search = $request->query->get(self::SEARCH_PARAM);

        if ($search === null || trim($search) === '') {
            return null;
        }

        return trim($search);
    }
}
```

---

## Operators

### Operator Interface

```php
<?php

namespace Glueful\Api\Filtering\Contracts;

use Glueful\Database\Query\QueryBuilder;

interface FilterOperatorInterface
{
    /**
     * Get operator name
     */
    public function name(): string;

    /**
     * Get operator aliases
     *
     * @return array<string>
     */
    public function aliases(): array;

    /**
     * Apply operator to query
     */
    public function apply(QueryBuilder $query, string $field, mixed $value): void;
}
```

### Example Operators

```php
<?php

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Database\Query\QueryBuilder;

class ContainsOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'contains';
    }

    public function aliases(): array
    {
        return ['like', 'includes'];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        $query->where($field, 'LIKE', "%{$value}%");
    }
}

class InOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'in';
    }

    public function aliases(): array
    {
        return [];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        $values = is_array($value) ? $value : explode(',', $value);
        $values = array_map('trim', $values);
        $query->whereIn($field, $values);
    }
}

class BetweenOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'between';
    }

    public function aliases(): array
    {
        return ['range'];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        $values = is_array($value) ? $value : explode(',', $value);

        if (count($values) !== 2) {
            throw new \InvalidArgumentException(
                "Between operator requires exactly 2 values"
            );
        }

        $query->whereBetween($field, [trim($values[0]), trim($values[1])]);
    }
}
```

### Operator Registry

```php
<?php

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Api\Filtering\Exceptions\InvalidOperatorException;

class OperatorRegistry
{
    /** @var array<string, FilterOperatorInterface> */
    private static array $operators = [];

    /** @var bool */
    private static bool $initialized = false;

    /**
     * Register default operators
     */
    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        self::register(new EqualOperator());
        self::register(new NotEqualOperator());
        self::register(new GreaterThanOperator());
        self::register(new GreaterThanOrEqualOperator());
        self::register(new LessThanOperator());
        self::register(new LessThanOrEqualOperator());
        self::register(new ContainsOperator());
        self::register(new StartsWithOperator());
        self::register(new EndsWithOperator());
        self::register(new InOperator());
        self::register(new NotInOperator());
        self::register(new BetweenOperator());
        self::register(new NullOperator());
        self::register(new NotNullOperator());

        self::$initialized = true;
    }

    /**
     * Register an operator
     */
    public static function register(FilterOperatorInterface $operator): void
    {
        self::$operators[$operator->name()] = $operator;

        foreach ($operator->aliases() as $alias) {
            self::$operators[$alias] = $operator;
        }
    }

    /**
     * Get an operator by name or alias
     */
    public static function get(string $name): FilterOperatorInterface
    {
        self::initialize();

        if (!isset(self::$operators[$name])) {
            throw new InvalidOperatorException("Unknown operator: {$name}");
        }

        return self::$operators[$name];
    }

    /**
     * Get all operator names and aliases
     *
     * @return array<string>
     */
    public static function getAliases(): array
    {
        self::initialize();
        return array_keys(self::$operators);
    }
}
```

---

## Search Engine Adapters

### Search Adapter Interface

```php
<?php

namespace Glueful\Api\Filtering\Contracts;

interface SearchAdapterInterface
{
    /**
     * Search documents
     *
     * @param string $query Search query
     * @param array $options Search options
     * @return SearchResult
     */
    public function search(string $query, array $options = []): SearchResult;

    /**
     * Index a document
     */
    public function index(string $id, array $document): void;

    /**
     * Delete a document
     */
    public function delete(string $id): void;

    /**
     * Check if adapter is available
     */
    public function isAvailable(): bool;
}
```

### Elasticsearch Adapter

```php
<?php

namespace Glueful\Api\Filtering\Adapters;

use Elastic\Elasticsearch\Client;
use Glueful\Api\Filtering\Contracts\SearchAdapterInterface;

class ElasticsearchAdapter implements SearchAdapterInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly string $index,
    ) {}

    public function search(string $query, array $options = []): SearchResult
    {
        $body = [
            'query' => [
                'multi_match' => [
                    'query' => $query,
                    'fields' => $options['fields'] ?? ['*'],
                    'type' => 'best_fields',
                    'fuzziness' => 'AUTO',
                ],
            ],
            'from' => $options['offset'] ?? 0,
            'size' => $options['limit'] ?? 20,
        ];

        // Add filters
        if (!empty($options['filters'])) {
            $body['query'] = [
                'bool' => [
                    'must' => [$body['query']],
                    'filter' => $this->buildFilters($options['filters']),
                ],
            ];
        }

        // Add sorting
        if (!empty($options['sort'])) {
            $body['sort'] = $this->buildSort($options['sort']);
        }

        $response = $this->client->search([
            'index' => $this->index,
            'body' => $body,
        ]);

        return new SearchResult(
            hits: array_map(
                fn($hit) => $hit['_source'],
                $response['hits']['hits']
            ),
            total: $response['hits']['total']['value'],
            took: $response['took'],
        );
    }

    public function index(string $id, array $document): void
    {
        $this->client->index([
            'index' => $this->index,
            'id' => $id,
            'body' => $document,
        ]);
    }

    public function delete(string $id): void
    {
        $this->client->delete([
            'index' => $this->index,
            'id' => $id,
        ]);
    }

    public function isAvailable(): bool
    {
        try {
            return $this->client->ping()->asBool();
        } catch (\Exception) {
            return false;
        }
    }

    private function buildFilters(array $filters): array
    {
        $esFilters = [];

        foreach ($filters as $filter) {
            $esFilters[] = match ($filter->operator) {
                'eq' => ['term' => [$filter->field => $filter->value]],
                'in' => ['terms' => [$filter->field => (array) $filter->value]],
                'gte' => ['range' => [$filter->field => ['gte' => $filter->value]]],
                'lte' => ['range' => [$filter->field => ['lte' => $filter->value]]],
                'between' => [
                    'range' => [
                        $filter->field => [
                            'gte' => $filter->value[0],
                            'lte' => $filter->value[1],
                        ],
                    ],
                ],
                default => ['term' => [$filter->field => $filter->value]],
            };
        }

        return $esFilters;
    }

    private function buildSort(array $sorts): array
    {
        return array_map(
            fn($sort) => [$sort->field => ['order' => strtolower($sort->direction)]],
            $sorts
        );
    }
}
```

### Searchable Trait for Models

```php
<?php

namespace Glueful\Api\Filtering\Concerns;

trait Searchable
{
    /**
     * Get the searchable fields for the model
     */
    public function toSearchableArray(): array
    {
        return $this->toArray();
    }

    /**
     * Get the search index name
     */
    public function searchableIndex(): string
    {
        return $this->getTable();
    }

    /**
     * Make the model searchable
     */
    public function makeSearchable(): void
    {
        $adapter = app(SearchAdapterInterface::class);
        $adapter->index($this->getKey(), $this->toSearchableArray());
    }

    /**
     * Remove from search index
     */
    public function removeFromSearch(): void
    {
        $adapter = app(SearchAdapterInterface::class);
        $adapter->delete($this->getKey());
    }

    /**
     * Search the model
     */
    public static function search(string $query, array $options = []): SearchResult
    {
        $adapter = app(SearchAdapterInterface::class);
        return $adapter->search($query, array_merge([
            'index' => (new static())->searchableIndex(),
        ], $options));
    }
}
```

---

## Attributes

```php
<?php

namespace Glueful\Api\Filtering\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Filterable
{
    /**
     * @param array<string> $fields Allowed filterable fields
     * @param int $maxFilters Maximum number of filters
     * @param int $maxDepth Maximum nesting depth
     */
    public function __construct(
        public readonly array $fields,
        public readonly int $maxFilters = 20,
        public readonly int $maxDepth = 3,
    ) {}
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Searchable
{
    /**
     * @param array<string> $fields Searchable fields
     * @param string|null $adapter Search adapter to use
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $adapter = null,
    ) {}
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Sortable
{
    /**
     * @param array<string> $fields Sortable fields
     * @param string|null $default Default sort
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $default = null,
    ) {}
}
```

---

## Implementation Phases

### Phase 1: Core Infrastructure (Week 1)

**Deliverables:**
- [ ] `FilterParser` class
- [ ] `ParsedFilter` and `ParsedSort` value objects
- [ ] Basic operators (eq, ne, gt, gte, lt, lte)
- [ ] `OperatorRegistry`
- [ ] `FilterBuilder` class

**Acceptance Criteria:**
```php
$parser = new FilterParser();
$filters = $parser->parseFilters($request);
// Returns [ParsedFilter(field: 'status', operator: 'eq', value: 'active')]
```

### Phase 2: QueryFilter Classes (Week 2)

**Deliverables:**
- [ ] `QueryFilter` base class
- [ ] Custom filter method support
- [ ] Sorting implementation
- [ ] Basic search (LIKE queries)
- [ ] Field whitelisting

**Acceptance Criteria:**
```php
class UserFilter extends QueryFilter {
    protected array $filterable = ['status', 'role'];
    protected array $searchable = ['name', 'email'];
}

$users = User::query()->tap(fn($q) => $filter->apply($q))->get();
```

### Phase 3: Advanced Operators (Week 2-3)

**Deliverables:**
- [ ] All operators (in, nin, between, contains, null, etc.)
- [ ] Attribute-based configuration
- [ ] `FilterMiddleware`
- [ ] Error handling

**Acceptance Criteria:**
```
GET /users?filter[status][in]=active,pending&filter[age][between]=18,65
```

### Phase 4: Search Engine Integration (Week 3-4)

**Deliverables:**
- [ ] `SearchAdapterInterface`
- [ ] `ElasticsearchAdapter`
- [ ] `MeilisearchAdapter`
- [ ] `Searchable` trait
- [ ] `scaffold:filter` command

**Acceptance Criteria:**
```php
$results = Post::search('php api tutorial', [
    'filters' => [ParsedFilter::create('status', 'eq', 'published')],
]);
```

---

## Testing Strategy

### Unit Tests

```php
<?php

namespace Glueful\Tests\Unit\Api\Filtering;

use PHPUnit\Framework\TestCase;
use Glueful\Api\Filtering\FilterParser;
use Symfony\Component\HttpFoundation\Request;

class FilterParserTest extends TestCase
{
    public function testParsesSimpleEqualityFilter(): void
    {
        $request = Request::create('/users', 'GET', [
            'filter' => ['status' => 'active'],
        ]);

        $parser = new FilterParser();
        $filters = $parser->parseFilters($request);

        $this->assertCount(1, $filters);
        $this->assertEquals('status', $filters[0]->field);
        $this->assertEquals('eq', $filters[0]->operator);
        $this->assertEquals('active', $filters[0]->value);
    }

    public function testParsesOperatorFilters(): void
    {
        $request = Request::create('/users', 'GET', [
            'filter' => ['age' => ['gte' => '18']],
        ]);

        $parser = new FilterParser();
        $filters = $parser->parseFilters($request);

        $this->assertEquals('age', $filters[0]->field);
        $this->assertEquals('gte', $filters[0]->operator);
        $this->assertEquals('18', $filters[0]->value);
    }

    public function testParsesSortDescending(): void
    {
        $request = Request::create('/users', 'GET', [
            'sort' => '-created_at,name',
        ]);

        $parser = new FilterParser();
        $sorts = $parser->parseSorts($request);

        $this->assertCount(2, $sorts);
        $this->assertEquals('created_at', $sorts[0]->field);
        $this->assertEquals('DESC', $sorts[0]->direction);
        $this->assertEquals('name', $sorts[1]->field);
        $this->assertEquals('ASC', $sorts[1]->direction);
    }
}
```

### Integration Tests

```php
<?php

namespace Glueful\Tests\Integration\Api\Filtering;

use Glueful\Testing\TestCase;

class FilteringIntegrationTest extends TestCase
{
    public function testFiltersUsersByStatus(): void
    {
        User::factory()->count(5)->create(['status' => 'active']);
        User::factory()->count(3)->create(['status' => 'inactive']);

        $response = $this->get('/api/users?filter[status]=active');

        $response->assertStatus(200);
        $response->assertJsonCount(5, 'data');
    }

    public function testFiltersWithComparisonOperator(): void
    {
        User::factory()->create(['age' => 17]);
        User::factory()->count(3)->create(['age' => 25]);

        $response = $this->get('/api/users?filter[age][gte]=18');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
    }

    public function testSearchesUsers(): void
    {
        User::factory()->create(['name' => 'John Doe']);
        User::factory()->create(['name' => 'Jane Smith']);

        $response = $this->get('/api/users?search=john');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function testSortsResults(): void
    {
        User::factory()->create(['name' => 'Zara']);
        User::factory()->create(['name' => 'Alice']);
        User::factory()->create(['name' => 'Bob']);

        $response = $this->get('/api/users?sort=name');

        $response->assertStatus(200);
        $names = array_column($response->json('data'), 'name');
        $this->assertEquals(['Alice', 'Bob', 'Zara'], $names);
    }
}
```

---

## Configuration Reference

```php
// config/api.php
return [
    'filtering' => [
        'enabled' => true,

        // Parameters
        'filter_param' => 'filter',
        'sort_param' => 'sort',
        'search_param' => 'search',
        'search_fields_param' => 'search_fields',

        // Limits
        'max_filters' => 20,
        'max_depth' => 3,
        'max_sort_fields' => 5,

        // Allowed operators
        'allowed_operators' => [
            'eq', 'ne', 'gt', 'gte', 'lt', 'lte',
            'contains', 'starts', 'ends',
            'in', 'nin', 'between',
            'null', 'not_null',
        ],

        // Search engine
        'search' => [
            'driver' => 'database', // database, elasticsearch, meilisearch
            'elasticsearch' => [
                'hosts' => [env('ELASTICSEARCH_HOST', 'localhost:9200')],
                'index_prefix' => env('APP_NAME', 'glueful') . '_',
            ],
            'meilisearch' => [
                'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
                'key' => env('MEILISEARCH_KEY'),
            ],
        ],
    ],
];
```

---

## Security Considerations

1. **Field Whitelisting**: Always define `$filterable` and `$sortable` arrays
2. **SQL Injection**: Use parameterized queries (handled by QueryBuilder)
3. **DoS Prevention**: Limit filter depth and count
4. **Sensitive Fields**: Never allow filtering on passwords, tokens, secrets
5. **Search Injection**: Sanitize search input before passing to search engines
