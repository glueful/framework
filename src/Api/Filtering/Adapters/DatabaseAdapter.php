<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Adapters;

use Glueful\Api\Filtering\ParsedFilter;
use Glueful\Api\Filtering\ParsedSort;
use Glueful\Api\Filtering\SearchResult;
use Glueful\Database\Connection;

/**
 * Database search adapter using LIKE queries
 *
 * Provides full-text search functionality using database LIKE queries.
 * This is the default adapter when no external search engine is configured.
 *
 * While not as powerful as dedicated search engines, it works out of the box
 * with no additional infrastructure required.
 */
class DatabaseAdapter extends SearchAdapter
{
    private string $table;

    /**
     * @var array<string>
     */
    private array $searchableFields = [];

    /**
     * @param string $table Database table name
     * @param array<string> $searchableFields Fields to search in
     * @param Connection|null $connection Optional database connection
     */
    public function __construct(
        string $table,
        array $searchableFields = [],
        ?Connection $connection = null
    ) {
        parent::__construct($table, $connection);
        $this->table = $table;
        $this->searchableFields = $searchableFields;
    }

    /**
     * {@inheritdoc}
     */
    public function search(string $query, array $options = []): SearchResult
    {
        $startTime = microtime(true);

        $fields = $options['fields'] ?? $this->searchableFields;
        $offset = $options['offset'] ?? 0;
        $limit = $options['limit'] ?? 20;

        if ($fields === []) {
            return SearchResult::empty();
        }

        // Build the base query
        $queryBuilder = $this->db->table($this->table);

        // Apply search conditions (OR across fields)
        $queryBuilder->where(function ($q) use ($query, $fields) {
            $first = true;
            foreach ($fields as $field) {
                if ($first) {
                    $q->where($field, 'LIKE', "%{$query}%");
                    $first = false;
                } else {
                    $q->orWhere($field, 'LIKE', "%{$query}%");
                }
            }
        });

        // Apply additional filters
        $filters = $options['filters'] ?? [];
        if ($filters !== []) {
            $this->applyFilters($queryBuilder, $filters);
        }

        // Get total count before pagination
        $countQuery = clone $queryBuilder;
        $total = $countQuery->count();

        // Apply sorting
        $sorts = $options['sorts'] ?? [];
        if ($sorts !== []) {
            $this->applySorts($queryBuilder, $sorts);
        }

        // Apply pagination
        $queryBuilder->offset($offset)->limit($limit);

        // Execute query
        $hits = $queryBuilder->get();

        $took = (microtime(true) - $startTime) * 1000;

        return new SearchResult(
            hits: $hits,
            total: $total,
            took: $took,
            offset: $offset,
            limit: $limit,
            meta: ['adapter' => 'database']
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        try {
            // Test database connection
            $this->db->table($this->table)->limit(1)->get();
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'database';
    }

    /**
     * {@inheritdoc}
     */
    protected function doIndex(string $id, array $document): void
    {
        // For database adapter, indexing means ensuring the data is in the table
        // This is typically a no-op since data is already in the database
        // But we can update the document if needed
        $existing = $this->db->table($this->table)
            ->where('id', $id)
            ->first();

        if ($existing === null) {
            // Remove internal fields
            $data = $this->prepareDocument($document);
            $data['id'] = $id;
            $this->db->table($this->table)->insert($data);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function doUpdate(string $id, array $document): void
    {
        $data = $this->prepareDocument($document);

        $this->db->table($this->table)
            ->where('id', $id)
            ->update($data);
    }

    /**
     * {@inheritdoc}
     */
    protected function doDelete(string $id): void
    {
        $this->db->table($this->table)
            ->where('id', $id)
            ->delete();
    }

    /**
     * Set searchable fields
     *
     * @param array<string> $fields
     * @return self
     */
    public function setSearchableFields(array $fields): self
    {
        $this->searchableFields = $fields;
        return $this;
    }

    /**
     * Get searchable fields
     *
     * @return array<string>
     */
    public function getSearchableFields(): array
    {
        return $this->searchableFields;
    }

    /**
     * Apply filters to query builder
     *
     * @param mixed $queryBuilder
     * @param array<ParsedFilter> $filters
     */
    private function applyFilters($queryBuilder, array $filters): void
    {
        foreach ($filters as $filter) {
            if (!$filter instanceof ParsedFilter) {
                continue;
            }

            match ($filter->operator) {
                'eq', '=' => $queryBuilder->where($filter->field, $filter->value),
                'ne', '!=' => $queryBuilder->where($filter->field, '!=', $filter->value),
                'gt', '>' => $queryBuilder->where($filter->field, '>', $filter->value),
                'gte', '>=' => $queryBuilder->where($filter->field, '>=', $filter->value),
                'lt', '<' => $queryBuilder->where($filter->field, '<', $filter->value),
                'lte', '<=' => $queryBuilder->where($filter->field, '<=', $filter->value),
                'in' => $queryBuilder->whereIn($filter->field, $filter->getValueAsArray()),
                'nin', 'not_in' => $queryBuilder->whereNotIn($filter->field, $filter->getValueAsArray()),
                'null', 'is_null' => $queryBuilder->whereNull($filter->field),
                'not_null' => $queryBuilder->whereNotNull($filter->field),
                'contains', 'like' => $queryBuilder->where($filter->field, 'LIKE', "%{$filter->value}%"),
                'starts' => $queryBuilder->where($filter->field, 'LIKE', "{$filter->value}%"),
                'ends' => $queryBuilder->where($filter->field, 'LIKE', "%{$filter->value}"),
                'between' => $this->applyBetweenFilter($queryBuilder, $filter),
                default => $queryBuilder->where($filter->field, $filter->value),
            };
        }
    }

    /**
     * Apply between filter
     *
     * @param mixed $queryBuilder
     */
    private function applyBetweenFilter($queryBuilder, ParsedFilter $filter): void
    {
        $values = $filter->getValueAsArray();
        if (count($values) === 2) {
            $queryBuilder->whereBetween($filter->field, $values[0], $values[1]);
        }
    }

    /**
     * Apply sorts to query builder
     *
     * @param mixed $queryBuilder
     * @param array<ParsedSort> $sorts
     */
    private function applySorts($queryBuilder, array $sorts): void
    {
        foreach ($sorts as $sort) {
            if (!$sort instanceof ParsedSort) {
                continue;
            }

            $queryBuilder->orderBy($sort->field, $sort->direction);
        }
    }

    /**
     * Prepare document for database insertion
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function prepareDocument(array $document): array
    {
        // Remove internal fields
        unset($document['_type'], $document['_id'], $document['_index']);

        return $document;
    }
}
