<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering;

use Glueful\Api\Filtering\Exceptions\InvalidFilterException;
use Glueful\Api\Filtering\Operators\OperatorRegistry;
use Symfony\Component\HttpFoundation\Request;

/**
 * Parser for filter, sort, and search query parameters
 *
 * Parses URL query parameters into structured filter, sort, and search objects.
 *
 * Supported syntax:
 * - Filters: filter[field]=value, filter[field][operator]=value
 * - Sorting: sort=field,-field2 (- prefix for descending)
 * - Search: search=query&search_fields=field1,field2
 */
class FilterParser
{
    private const FILTER_PARAM = 'filter';
    private const SORT_PARAM = 'sort';
    private const SEARCH_PARAM = 'search';
    private const SEARCH_FIELDS_PARAM = 'search_fields';

    /**
     * Create a new filter parser
     *
     * @param int $maxDepth Maximum nesting depth for filters
     * @param int $maxFilters Maximum number of filters allowed
     */
    public function __construct(
        private readonly int $maxDepth = 3,
        private readonly int $maxFilters = 20,
    ) {
    }

    /**
     * Parse filter parameters from request
     *
     * @param Request $request The HTTP request
     * @return array<ParsedFilter> Array of parsed filters
     * @throws InvalidFilterException If filters exceed limits
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
            throw InvalidFilterException::tooManyFilters($this->maxFilters, count($filters));
        }

        return $filters;
    }

    /**
     * Recursively parse filter array
     *
     * @param array<string, mixed> $data The filter data
     * @param string $prefix Field name prefix for nested filters
     * @param array<ParsedFilter> $filters Accumulated filters
     * @param int $depth Current nesting depth
     * @throws InvalidFilterException If depth exceeds limit
     */
    private function parseFilterArray(
        array $data,
        string $prefix,
        array &$filters,
        int $depth
    ): void {
        if ($depth > $this->maxDepth) {
            throw InvalidFilterException::maxDepthExceeded($this->maxDepth);
        }

        foreach ($data as $key => $value) {
            $field = $prefix !== '' ? "{$prefix}.{$key}" : (string) $key;

            if (is_array($value)) {
                // Check if this is an operator array
                if ($this->isOperatorArray($value)) {
                    foreach ($value as $operator => $operatorValue) {
                        $filters[] = new ParsedFilter(
                            field: $prefix !== '' ? $prefix : (string) $key,
                            operator: (string) $operator,
                            value: $operatorValue,
                        );
                    }
                } else {
                    // Nested field (e.g., filter[user][name]=value)
                    $this->parseFilterArray($value, $field, $filters, $depth + 1);
                }
            } else {
                // Simple equality filter (e.g., filter[status]=active)
                $filters[] = new ParsedFilter(
                    field: $field,
                    operator: 'eq',
                    value: $value,
                );
            }
        }
    }

    /**
     * Check if array contains operator keys
     *
     * @param array<string, mixed> $value The array to check
     * @return bool True if array contains operator keys
     */
    private function isOperatorArray(array $value): bool
    {
        $operators = OperatorRegistry::getAliases();
        $keys = array_keys($value);

        foreach ($keys as $key) {
            if (in_array((string) $key, $operators, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse sort parameter from request
     *
     * @param Request $request The HTTP request
     * @return array<ParsedSort> Array of parsed sorts
     */
    public function parseSorts(Request $request): array
    {
        $sortParam = $request->query->get(self::SORT_PARAM);

        if ($sortParam === null || !is_string($sortParam)) {
            return [];
        }

        return $this->parseSortString($sortParam);
    }

    /**
     * Parse sort string into ParsedSort objects
     *
     * @param string $sortString The sort string (e.g., '-created_at,name')
     * @return array<ParsedSort> Array of parsed sorts
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

            $sorts[] = ParsedSort::fromString($field);
        }

        return $sorts;
    }

    /**
     * Parse search parameter from request
     *
     * @param Request $request The HTTP request
     * @return string|null The search query or null if not present
     */
    public function parseSearch(Request $request): ?string
    {
        $search = $request->query->get(self::SEARCH_PARAM);

        if ($search === null || !is_string($search) || trim($search) === '') {
            return null;
        }

        return trim($search);
    }

    /**
     * Parse search fields from request
     *
     * @param Request $request The HTTP request
     * @return array<string>|null Array of field names or null if not specified
     */
    public function parseSearchFields(Request $request): ?array
    {
        $fields = $request->query->get(self::SEARCH_FIELDS_PARAM);

        if ($fields === null || !is_string($fields)) {
            return null;
        }

        $fieldArray = array_map('trim', explode(',', $fields));
        return array_filter($fieldArray, fn($f) => $f !== '');
    }

    /**
     * Get configured maximum depth
     *
     * @return int
     */
    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    /**
     * Get configured maximum filters
     *
     * @return int
     */
    public function getMaxFilters(): int
    {
        return $this->maxFilters;
    }
}
