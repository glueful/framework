<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Adapters;

use Glueful\Api\Filtering\ParsedFilter;
use Glueful\Api\Filtering\ParsedSort;
use Glueful\Api\Filtering\SearchResult;
use Glueful\Database\Connection;

/**
 * Elasticsearch search adapter
 *
 * Provides full-text search functionality through Elasticsearch.
 *
 * **Installation:**
 * ```bash
 * composer require elasticsearch/elasticsearch:^8.0
 * ```
 *
 * **Configuration (config/api.php):**
 * ```php
 * 'filtering' => [
 *     'search' => [
 *         'driver' => 'elasticsearch',
 *         'elasticsearch' => [
 *             'hosts' => ['localhost:9200'],
 *         ],
 *     ],
 * ],
 * ```
 *
 * Features:
 * - Multi-match queries with fuzziness
 * - Boolean filters
 * - Sorting and pagination
 * - Bulk indexing support
 *
 * @package Glueful\Api\Filtering\Adapters
 */
class ElasticsearchAdapter extends SearchAdapter
{
    /**
     * Elasticsearch client instance
     * Type hint omitted for optional dependency support
     *
     * @var object|null
     */
    private ?object $client = null;

    /**
     * @param string $indexName The Elasticsearch index name
     * @param array<string, mixed> $config Elasticsearch configuration
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
     * Initialize Elasticsearch client
     */
    private function initializeClient(): void
    {
        /** @disregard P1009 Optional dependency */
        if (!class_exists(\Elastic\Elasticsearch\ClientBuilder::class)) {
            return;
        }

        $hosts = $this->config['hosts'] ?? [
            $this->getDefaultHost()
        ];

        /** @disregard P1009 Optional dependency */
        $this->client = \Elastic\Elasticsearch\ClientBuilder::create()
            ->setHosts($hosts)
            ->build();
    }

    /**
     * Get default Elasticsearch host from config or environment
     */
    private function getDefaultHost(): string
    {
        $searchConfig = $this->getConfig();
        return $searchConfig['elasticsearch']['hosts'][0]
            ?? (getenv('ELASTICSEARCH_HOST') ?: 'localhost:9200');
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

        $fields = $options['fields'] ?? ['*'];
        $offset = $options['offset'] ?? 0;
        $limit = $options['limit'] ?? 20;

        // Build the search body
        $body = [
            'query' => $this->buildQuery($query, $fields, $options),
            'from' => $offset,
            'size' => $limit,
        ];

        // Add sorting
        $sorts = $options['sorts'] ?? [];
        if ($sorts !== []) {
            $body['sort'] = $this->buildSort($sorts);
        }

        // Execute search
        $response = $this->client->search([
            'index' => $this->indexName,
            'body' => $body,
        ]);

        $responseData = $response->asArray();
        $took = (microtime(true) - $startTime) * 1000;

        $hits = array_map(
            fn($hit) => array_merge($hit['_source'], ['_score' => $hit['_score'] ?? null]),
            $responseData['hits']['hits'] ?? []
        );

        $total = is_array($responseData['hits']['total'])
            ? $responseData['hits']['total']['value']
            : $responseData['hits']['total'];

        return new SearchResult(
            hits: $hits,
            total: (int) $total,
            took: $responseData['took'] ?? $took,
            offset: $offset,
            limit: $limit,
            meta: [
                'adapter' => 'elasticsearch',
                'max_score' => $responseData['hits']['max_score'] ?? null,
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
            return $this->client->ping()->asBool();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'elasticsearch';
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

        $this->client->index([
            'index' => $this->indexName,
            'id' => $id,
            'body' => $data,
        ]);
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

        $this->client->update([
            'index' => $this->indexName,
            'id' => $id,
            'body' => ['doc' => $data],
        ]);
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
            $this->client->delete([
                'index' => $this->indexName,
                'id' => $id,
            ]);
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

        $params = ['body' => []];

        foreach ($documents as $id => $document) {
            $params['body'][] = [
                'index' => [
                    '_index' => $this->indexName,
                    '_id' => $id,
                ]
            ];
            $params['body'][] = $this->prepareDocument($document);
        }

        if ($params['body'] !== []) {
            $this->client->bulk($params);
        }
    }

    /**
     * Build the Elasticsearch query
     *
     * @param string $query Search query
     * @param array<string> $fields Fields to search
     * @param array<string, mixed> $options Search options
     * @return array<string, mixed>
     */
    private function buildQuery(string $query, array $fields, array $options): array
    {
        $mainQuery = [
            'multi_match' => [
                'query' => $query,
                'fields' => $fields,
                'type' => 'best_fields',
                'fuzziness' => 'AUTO',
            ],
        ];

        // Add filters if present
        $filters = $options['filters'] ?? [];
        if ($filters !== []) {
            return [
                'bool' => [
                    'must' => [$mainQuery],
                    'filter' => $this->buildFilters($filters),
                ],
            ];
        }

        return $mainQuery;
    }

    /**
     * Build Elasticsearch filters
     *
     * @param array<ParsedFilter> $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildFilters(array $filters): array
    {
        $esFilters = [];

        foreach ($filters as $filter) {
            if (!$filter instanceof ParsedFilter) {
                continue;
            }

            $esFilters[] = match ($filter->operator) {
                'eq', '=' => ['term' => [$filter->field => $filter->value]],
                'ne', '!=' => ['bool' => ['must_not' => ['term' => [$filter->field => $filter->value]]]],
                'gt', '>' => ['range' => [$filter->field => ['gt' => $filter->value]]],
                'gte', '>=' => ['range' => [$filter->field => ['gte' => $filter->value]]],
                'lt', '<' => ['range' => [$filter->field => ['lt' => $filter->value]]],
                'lte', '<=' => ['range' => [$filter->field => ['lte' => $filter->value]]],
                'in' => ['terms' => [$filter->field => $filter->getValueAsArray()]],
                'nin', 'not_in' => [
                    'bool' => ['must_not' => ['terms' => [$filter->field => $filter->getValueAsArray()]]]
                ],
                'null', 'is_null' => ['bool' => ['must_not' => ['exists' => ['field' => $filter->field]]]],
                'not_null' => ['exists' => ['field' => $filter->field]],
                'contains', 'like' => ['wildcard' => [$filter->field => "*{$filter->value}*"]],
                'starts' => ['prefix' => [$filter->field => $filter->value]],
                'ends' => ['wildcard' => [$filter->field => "*{$filter->value}"]],
                'between' => $this->buildBetweenFilter($filter),
                default => ['term' => [$filter->field => $filter->value]],
            };
        }

        return $esFilters;
    }

    /**
     * Build between filter for Elasticsearch
     *
     * @return array<string, mixed>
     */
    private function buildBetweenFilter(ParsedFilter $filter): array
    {
        $values = $filter->getValueAsArray();
        if (count($values) !== 2) {
            return ['match_all' => new \stdClass()];
        }

        return [
            'range' => [
                $filter->field => [
                    'gte' => $values[0],
                    'lte' => $values[1],
                ],
            ],
        ];
    }

    /**
     * Build Elasticsearch sort
     *
     * @param array<ParsedSort> $sorts
     * @return array<int, array<string, array<string, string>>>
     */
    private function buildSort(array $sorts): array
    {
        $esSort = [];

        foreach ($sorts as $sort) {
            if (!$sort instanceof ParsedSort) {
                continue;
            }

            $esSort[] = [
                $sort->field => ['order' => strtolower($sort->direction)]
            ];
        }

        return $esSort;
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
        unset($document['_type'], $document['_id'], $document['_index'], $document['_score']);

        return $document;
    }

    /**
     * Create the index with optional mappings
     *
     * @param array<string, mixed> $mappings Index mappings
     * @param array<string, mixed> $settings Index settings
     */
    public function createIndex(array $mappings = [], array $settings = []): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $params = ['index' => $this->indexName];

        if ($mappings !== [] || $settings !== []) {
            $params['body'] = [];
            if ($mappings !== []) {
                $params['body']['mappings'] = $mappings;
            }
            if ($settings !== []) {
                $params['body']['settings'] = $settings;
            }
        }

        $this->client->indices()->create($params);
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
            $this->client->indices()->delete(['index' => $this->indexName]);
        } catch (\Exception) {
            // Index might not exist
        }
    }

    /**
     * Check if the index exists
     */
    public function indexExists(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        return $this->client->indices()->exists(['index' => $this->indexName])->asBool();
    }

    /**
     * Refresh the index (make recent changes searchable)
     */
    public function refreshIndex(): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $this->client->indices()->refresh(['index' => $this->indexName]);
    }

    /**
     * Get the Elasticsearch client
     *
     * @return object|null
     */
    public function getClient(): ?object
    {
        return $this->client;
    }
}
