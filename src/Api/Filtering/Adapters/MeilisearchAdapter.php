<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Adapters;

use Glueful\Api\Filtering\ParsedFilter;
use Glueful\Api\Filtering\ParsedSort;
use Glueful\Api\Filtering\SearchResult;
use Glueful\Database\Connection;

/**
 * Meilisearch search adapter
 *
 * Provides full-text search functionality through Meilisearch.
 *
 * **Installation:**
 * ```bash
 * composer require meilisearch/meilisearch-php:^1.0
 * ```
 *
 * **Configuration (config/api.php):**
 * ```php
 * 'filtering' => [
 *     'search' => [
 *         'driver' => 'meilisearch',
 *         'meilisearch' => [
 *             'host' => 'http://localhost:7700',
 *             'key' => 'your-api-key',
 *         ],
 *     ],
 * ],
 * ```
 *
 * Features:
 * - Typo-tolerant search
 * - Faceted search and filtering
 * - Sorting and pagination
 * - Lightning-fast search (designed for end-user search)
 *
 * @package Glueful\Api\Filtering\Adapters
 */
class MeilisearchAdapter extends SearchAdapter
{
    /**
     * Meilisearch client instance
     * Type hint omitted for optional dependency support
     *
     * @var object|null
     */
    private ?object $client = null;

    /**
     * Meilisearch index instance
     *
     * @var object|null
     */
    private ?object $index = null;

    /**
     * @param string $indexName The Meilisearch index name
     * @param array<string, mixed> $config Meilisearch configuration
     * @param Connection|null $connection Optional database connection for tracking
     */
    public function __construct(
        string $indexName,
        private readonly array $config = [],
        ?Connection $connection = null
    ) {
        parent::__construct($indexName, $connection);
        $this->initializeClient();
    }

    /**
     * Initialize Meilisearch client
     */
    private function initializeClient(): void
    {
        /** @disregard P1009 Optional dependency */
        if (!class_exists(\Meilisearch\Client::class)) {
            return;
        }

        $host = $this->config['host'] ?? $this->getDefaultHost();
        $apiKey = $this->config['key'] ?? $this->getDefaultApiKey();

        /** @disregard P1009 Optional dependency */
        $this->client = new \Meilisearch\Client($host, $apiKey);
        $this->index = $this->client->index($this->indexName);
    }

    /**
     * Get default Meilisearch host from config or environment
     */
    private function getDefaultHost(): string
    {
        $searchConfig = $this->getConfig();
        return $searchConfig['meilisearch']['host']
            ?? (getenv('MEILISEARCH_HOST') ?: 'http://localhost:7700');
    }

    /**
     * Get default Meilisearch API key from config or environment
     */
    private function getDefaultApiKey(): ?string
    {
        $searchConfig = $this->getConfig();
        return $searchConfig['meilisearch']['key']
            ?? (getenv('MEILISEARCH_KEY') ?: null);
    }

    /**
     * {@inheritdoc}
     */
    public function search(string $query, array $options = []): SearchResult
    {
        if (!$this->isAvailable()) {
            return SearchResult::empty();
        }

        $startTime = microtime(true);

        $offset = $options['offset'] ?? 0;
        $limit = $options['limit'] ?? 20;

        // Build search parameters
        $searchParams = [
            'offset' => $offset,
            'limit' => $limit,
        ];

        // Add attributes to search on
        $fields = $options['fields'] ?? [];
        if ($fields !== [] && $fields !== ['*']) {
            $searchParams['attributesToSearchOn'] = $fields;
        }

        // Add filters
        $filters = $options['filters'] ?? [];
        if ($filters !== []) {
            $filterString = $this->buildFilters($filters);
            if ($filterString !== '') {
                $searchParams['filter'] = $filterString;
            }
        }

        // Add sorting
        $sorts = $options['sorts'] ?? [];
        if ($sorts !== []) {
            $searchParams['sort'] = $this->buildSort($sorts);
        }

        // Execute search
        $response = $this->index->search($query, $searchParams);

        $took = (microtime(true) - $startTime) * 1000;

        return new SearchResult(
            hits: $response->getHits(),
            total: $response->getEstimatedTotalHits() ?? count($response->getHits()),
            took: $response->getProcessingTimeMs() ?? $took,
            offset: $offset,
            limit: $limit,
            meta: [
                'adapter' => 'meilisearch',
                'query' => $response->getQuery(),
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        if ($this->client === null) {
            return false;
        }

        try {
            $this->client->health();
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
        return 'meilisearch';
    }

    /**
     * {@inheritdoc}
     */
    protected function doIndex(string $id, array $document): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $data = $this->prepareDocument($document);
        $data['id'] = $id;

        $this->index->addDocuments([$data], 'id');
    }

    /**
     * {@inheritdoc}
     */
    protected function doUpdate(string $id, array $document): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $data = $this->prepareDocument($document);
        $data['id'] = $id;

        $this->index->updateDocuments([$data], 'id');
    }

    /**
     * {@inheritdoc}
     */
    protected function doDelete(string $id): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        try {
            $this->index->deleteDocument($id);
        } catch (\Exception) {
            // Document might not exist
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function doBulkIndex(array $documents): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $prepared = [];
        foreach ($documents as $id => $document) {
            $doc = $this->prepareDocument($document);
            $doc['id'] = $id;
            $prepared[] = $doc;
        }

        if ($prepared !== []) {
            $this->index->addDocuments($prepared, 'id');
        }
    }

    /**
     * Build Meilisearch filter string
     *
     * @param array<ParsedFilter> $filters
     */
    private function buildFilters(array $filters): string
    {
        $filterParts = [];

        foreach ($filters as $filter) {
            if (!$filter instanceof ParsedFilter) {
                continue;
            }

            $part = match ($filter->operator) {
                'eq', '=' => "{$filter->field} = " . $this->formatValue($filter->value),
                'ne', '!=' => "{$filter->field} != " . $this->formatValue($filter->value),
                'gt', '>' => "{$filter->field} > " . $this->formatValue($filter->value),
                'gte', '>=' => "{$filter->field} >= " . $this->formatValue($filter->value),
                'lt', '<' => "{$filter->field} < " . $this->formatValue($filter->value),
                'lte', '<=' => "{$filter->field} <= " . $this->formatValue($filter->value),
                'in' => $this->buildInFilter($filter),
                'nin', 'not_in' => $this->buildNotInFilter($filter),
                'null', 'is_null' => "{$filter->field} IS NULL",
                'not_null' => "{$filter->field} IS NOT NULL",
                'between' => $this->buildBetweenFilter($filter),
                default => "{$filter->field} = " . $this->formatValue($filter->value),
            };

            if ($part !== null) {
                $filterParts[] = $part;
            }
        }

        return implode(' AND ', $filterParts);
    }

    /**
     * Build IN filter for Meilisearch
     */
    private function buildInFilter(ParsedFilter $filter): string
    {
        $values = $filter->getValueAsArray();
        $formatted = array_map(fn($v) => $this->formatValue($v), $values);

        return "{$filter->field} IN [" . implode(', ', $formatted) . "]";
    }

    /**
     * Build NOT IN filter for Meilisearch
     */
    private function buildNotInFilter(ParsedFilter $filter): string
    {
        $values = $filter->getValueAsArray();
        $formatted = array_map(fn($v) => $this->formatValue($v), $values);

        return "NOT {$filter->field} IN [" . implode(', ', $formatted) . "]";
    }

    /**
     * Build BETWEEN filter for Meilisearch
     */
    private function buildBetweenFilter(ParsedFilter $filter): ?string
    {
        $values = $filter->getValueAsArray();
        if (count($values) !== 2) {
            return null;
        }

        $min = $this->formatValue($values[0]);
        $max = $this->formatValue($values[1]);

        return "({$filter->field} >= {$min} AND {$filter->field} <= {$max})";
    }

    /**
     * Format value for Meilisearch filter
     *
     * @param mixed $value
     */
    private function formatValue(mixed $value): string
    {
        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        // String values need quotes
        return '"' . addslashes((string) $value) . '"';
    }

    /**
     * Build Meilisearch sort array
     *
     * @param array<ParsedSort> $sorts
     * @return array<string>
     */
    private function buildSort(array $sorts): array
    {
        $sortArray = [];

        foreach ($sorts as $sort) {
            if (!$sort instanceof ParsedSort) {
                continue;
            }

            $direction = strtolower($sort->direction) === 'desc' ? ':desc' : ':asc';
            $sortArray[] = $sort->field . $direction;
        }

        return $sortArray;
    }

    /**
     * Prepare document for indexing
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

    /**
     * Update index settings
     *
     * @param array<string, mixed> $settings
     */
    public function updateSettings(array $settings): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $this->index->updateSettings($settings);
    }

    /**
     * Set searchable attributes
     *
     * @param array<string> $attributes
     */
    public function setSearchableAttributes(array $attributes): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $this->index->updateSearchableAttributes($attributes);
    }

    /**
     * Set filterable attributes
     *
     * @param array<string> $attributes
     */
    public function setFilterableAttributes(array $attributes): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $this->index->updateFilterableAttributes($attributes);
    }

    /**
     * Set sortable attributes
     *
     * @param array<string> $attributes
     */
    public function setSortableAttributes(array $attributes): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $this->index->updateSortableAttributes($attributes);
    }

    /**
     * Delete the index
     */
    public function deleteIndex(): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        try {
            $this->client->deleteIndex($this->indexName);
        } catch (\Exception) {
            // Index might not exist
        }
    }

    /**
     * Get the Meilisearch client
     *
     * @return object|null
     */
    public function getClient(): ?object
    {
        return $this->client;
    }

    /**
     * Get the Meilisearch index
     *
     * @return object|null
     */
    public function getIndex(): ?object
    {
        return $this->index;
    }
}
