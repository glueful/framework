<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering;

use Glueful\Api\Filtering\Operators\OperatorRegistry;
use Glueful\Database\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base class for model-specific query filters
 *
 * Extend this class to create custom filters for your models.
 * Define filterable, sortable, and searchable fields to control
 * which fields can be filtered, sorted, and searched.
 *
 * @example
 * ```php
 * class UserFilter extends QueryFilter
 * {
 *     protected ?array $filterable = ['status', 'role', 'created_at'];
 *     protected ?array $sortable = ['name', 'created_at'];
 *     protected array $searchable = ['name', 'email'];
 *     protected ?string $defaultSort = '-created_at';
 *
 *     // Custom filter for status with "any" support
 *     public function filterStatus(string $value, string $operator): void
 *     {
 *         if ($value === 'any') return;
 *         $this->query->where('status', $value);
 *     }
 * }
 * ```
 */
abstract class QueryFilter
{
    protected QueryBuilder $query;
    protected Request $request;
    protected FilterParser $parser;

    /**
     * Fields allowed for filtering
     * Override in subclass to restrict filterable fields
     * Null means all fields are allowed
     *
     * @var array<string>|null
     */
    protected ?array $filterable = null;

    /**
     * Fields allowed for sorting
     * Null means all fields are allowed
     *
     * @var array<string>|null
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
     * Use '-' prefix for descending (e.g., '-created_at')
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

    /**
     * Create a new query filter
     *
     * @param Request $request The HTTP request
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->parser = new FilterParser($this->maxDepth, $this->maxFilters);
    }

    /**
     * Apply filters to a query builder
     *
     * @param QueryBuilder $query The query builder to filter
     * @return QueryBuilder The filtered query builder
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
            $sorts = $this->getDefaultSorts();
        }
        foreach ($sorts as $sort) {
            $this->applySort($sort);
        }

        return $this->query;
    }

    /**
     * Apply a single filter
     *
     * @param ParsedFilter $filter The filter to apply
     */
    protected function applyFilter(ParsedFilter $filter): void
    {
        // Check if field is filterable
        if (!$this->isFilterable($filter->field)) {
            return;
        }

        // Check for custom filter method (filterFieldName)
        $method = 'filter' . $this->studly($filter->field);
        if (method_exists($this, $method)) {
            call_user_func([$this, $method], $filter->value, $filter->operator);
            return;
        }

        // Apply standard filter using operator
        $this->applyStandardFilter($filter);
    }

    /**
     * Apply standard filter using operator registry
     *
     * @param ParsedFilter $filter The filter to apply
     */
    protected function applyStandardFilter(ParsedFilter $filter): void
    {
        $operator = OperatorRegistry::get($filter->operator);
        $operator->apply($this->query, $filter->field, $filter->value);
    }

    /**
     * Apply full-text search
     *
     * @param string $search The search query
     */
    protected function applySearch(string $search): void
    {
        $fields = $this->getSearchableFields();

        if ($fields === []) {
            return;
        }

        // Build OR conditions for each searchable field
        $this->query->where(function (QueryBuilder $query) use ($search, $fields) {
            $first = true;
            foreach ($fields as $field) {
                if ($first) {
                    $query->where($field, 'LIKE', "%{$search}%");
                    $first = false;
                } else {
                    $query->orWhere($field, 'LIKE', "%{$search}%");
                }
            }
        });
    }

    /**
     * Apply sorting
     *
     * @param ParsedSort $sort The sort to apply
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
     *
     * @param string $field The field name
     * @return bool
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
     *
     * @param string $field The field name
     * @return bool
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
     *
     * @return array<string>
     */
    protected function getSearchableFields(): array
    {
        $requestFields = $this->parser->parseSearchFields($this->request);

        if ($requestFields !== null) {
            // Only allow fields that are in the searchable list
            return array_intersect($requestFields, $this->searchable);
        }

        return $this->searchable;
    }

    /**
     * Get default sorts
     *
     * @return array<ParsedSort>
     */
    protected function getDefaultSorts(): array
    {
        if ($this->defaultSort === null) {
            return [];
        }

        return $this->parser->parseSortString($this->defaultSort);
    }

    /**
     * Get the current query builder
     *
     * @return QueryBuilder
     */
    protected function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * Get the current request
     *
     * @return Request
     */
    protected function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Convert string to studly case
     *
     * @param string $value The string to convert
     * @return string
     */
    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $value)));
    }
}
