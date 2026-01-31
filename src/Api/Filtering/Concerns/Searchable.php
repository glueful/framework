<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Concerns;

use Glueful\Api\Filtering\Contracts\SearchAdapterInterface;
use Glueful\Api\Filtering\SearchResult;
use Glueful\Bootstrap\ApplicationContext;
use Psr\Container\ContainerInterface;

/**
 * Searchable trait for ORM models
 *
 * Provides search engine integration for models, allowing them to be
 * indexed and searched through configured search adapters.
 *
 * Usage:
 * ```php
 * class Post extends Model
 * {
 *     use Searchable;
 *
 *     protected array $searchable = ['title', 'body', 'excerpt'];
 * }
 *
 * // Index a model
 * $post->makeSearchable();
 *
 * // Search
 * $results = Post::search('php tutorial');
 * ```
 */
trait Searchable
{
    /**
     * Boot the searchable trait
     */
    public static function bootSearchable(): void
    {
        // Auto-index on save if enabled
        static::saved(function ($model) {
            if ($model->shouldAutoIndex()) {
                $model->makeSearchable();
            }
        });

        // Remove from index on delete
        static::deleted(function ($model) {
            $model->removeFromSearch();
        });
    }

    /**
     * Get the searchable data for the model
     *
     * Override this method to customize what gets indexed.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        // Get the searchable fields if defined
        $searchableFields = $this->getSearchableFields();

        if ($searchableFields !== []) {
            $data = [];
            foreach ($searchableFields as $field) {
                $data[$field] = $this->getAttribute($field);
            }
            return $data;
        }

        // Default to all model attributes
        return $this->toArray();
    }

    /**
     * Get the search index name for the model
     */
    public function searchableIndex(): string
    {
        // Allow custom index name through property
        if (property_exists($this, 'searchIndex') && $this->searchIndex !== null) {
            return $this->searchIndex;
        }

        // Default to table name with optional prefix
        $prefix = $this->getSearchIndexPrefix();

        return $prefix . $this->getTable();
    }

    /**
     * Get the searchable fields for the model
     *
     * @return array<string>
     */
    public function getSearchableFields(): array
    {
        if (property_exists($this, 'searchable')) {
            return $this->searchable;
        }

        return [];
    }

    /**
     * Get the search index prefix from configuration
     */
    protected function getSearchIndexPrefix(): string
    {
        $context = $this->resolveSearchContext();
        if ($context !== null) {
            return (string) config($context, 'api.filtering.search.index_prefix', '');
        }

        return '';
    }

    /**
     * Check if the model should auto-index on save
     */
    public function shouldAutoIndex(): bool
    {
        if (property_exists($this, 'autoIndex')) {
            return (bool) $this->autoIndex;
        }

        // Check configuration
        $context = $this->resolveSearchContext();
        if ($context !== null) {
            return (bool) config($context, 'api.filtering.search.auto_index', false);
        }

        return false;
    }

    /**
     * Make the model searchable (index it)
     */
    public function makeSearchable(): void
    {
        $adapter = $this->getSearchAdapter();

        if ($adapter === null || !$adapter->isAvailable()) {
            return;
        }

        $document = array_merge(
            $this->toSearchableArray(),
            ['_type' => static::class]
        );

        $adapter->index($this->getSearchKey(), $document);
    }

    /**
     * Update the model in the search index
     */
    public function updateSearchable(): void
    {
        $adapter = $this->getSearchAdapter();

        if ($adapter === null || !$adapter->isAvailable()) {
            return;
        }

        $document = array_merge(
            $this->toSearchableArray(),
            ['_type' => static::class]
        );

        $adapter->update($this->getSearchKey(), $document);
    }

    /**
     * Remove the model from the search index
     */
    public function removeFromSearch(): void
    {
        $adapter = $this->getSearchAdapter();

        if ($adapter === null || !$adapter->isAvailable()) {
            return;
        }

        $adapter->delete($this->getSearchKey());
    }

    /**
     * Get the key to use for search indexing
     */
    public function getSearchKey(): string
    {
        return (string) $this->getKey();
    }

    /**
     * Search the model's index
     *
     * @param string $query Search query
     * @param array<string, mixed> $options Search options
     */
    public static function search(string $query, array $options = []): SearchResult
    {
        $instance = new static();
        $adapter = $instance->getSearchAdapter();

        if ($adapter === null || !$adapter->isAvailable()) {
            return SearchResult::empty();
        }

        // Add searchable fields if not specified
        if (!isset($options['fields'])) {
            $searchableFields = $instance->getSearchableFields();
            if ($searchableFields !== []) {
                $options['fields'] = $searchableFields;
            }
        }

        return $adapter->search($query, $options);
    }

    /**
     * Bulk index multiple models
     *
     * @param iterable<static> $models
     */
    public static function makeAllSearchable(iterable $models): void
    {
        $instance = new static();
        $adapter = $instance->getSearchAdapter();

        if ($adapter === null || !$adapter->isAvailable()) {
            return;
        }

        $documents = [];
        foreach ($models as $model) {
            $documents[$model->getSearchKey()] = array_merge(
                $model->toSearchableArray(),
                ['_type' => static::class]
            );
        }

        if ($documents !== []) {
            $adapter->bulkIndex($documents);
        }
    }

    /**
     * Remove all models from the search index
     *
     * @param iterable<static> $models
     */
    public static function removeAllFromSearch(iterable $models): void
    {
        $instance = new static();
        $adapter = $instance->getSearchAdapter();

        if ($adapter === null || !$adapter->isAvailable()) {
            return;
        }

        foreach ($models as $model) {
            $adapter->delete($model->getSearchKey());
        }
    }

    /**
     * Get the search adapter for this model
     */
    protected function getSearchAdapter(): ?SearchAdapterInterface
    {
        $context = $this->resolveSearchContext();
        if ($context !== null && $context->hasContainer()) {
            $container = $context->getContainer();
            if ($container->has(SearchAdapterInterface::class)) {
                return $container->get(SearchAdapterInterface::class);
            }
        }

        // Try to get the configured adapter
        return $this->resolveSearchAdapter($context);
    }

    /**
     * Resolve the search adapter based on configuration
     */
    protected function resolveSearchAdapter(?ApplicationContext $context): ?SearchAdapterInterface
    {
        if ($context === null) {
            return null;
        }

        $driver = (string) config($context, 'api.filtering.search.driver', 'database');
        $index = $this->searchableIndex();

        return match ($driver) {
            'elasticsearch' => $this->createElasticsearchAdapter($index, $context),
            'meilisearch' => $this->createMeilisearchAdapter($index, $context),
            'database' => $this->createDatabaseAdapter($index),
            default => null,
        };
    }

    /**
     * Create Elasticsearch adapter
     */
    private function createElasticsearchAdapter(
        string $index,
        ?ApplicationContext $context,
    ): ?SearchAdapterInterface {
        if (!class_exists(\Glueful\Api\Filtering\Adapters\ElasticsearchAdapter::class)) {
            return null;
        }

        $config = $context !== null
            ? (array) config($context, 'api.filtering.search.elasticsearch', [])
            : [];

        return new \Glueful\Api\Filtering\Adapters\ElasticsearchAdapter($index, $config);
    }

    /**
     * Create Meilisearch adapter
     */
    private function createMeilisearchAdapter(
        string $index,
        ?ApplicationContext $context,
    ): ?SearchAdapterInterface {
        if (!class_exists(\Glueful\Api\Filtering\Adapters\MeilisearchAdapter::class)) {
            return null;
        }

        $config = $context !== null
            ? (array) config($context, 'api.filtering.search.meilisearch', [])
            : [];

        return new \Glueful\Api\Filtering\Adapters\MeilisearchAdapter($index, $config);
    }

    /**
     * Create Database adapter
     */
    private function createDatabaseAdapter(string $index): ?SearchAdapterInterface
    {
        if (!class_exists(\Glueful\Api\Filtering\Adapters\DatabaseAdapter::class)) {
            return null;
        }

        return new \Glueful\Api\Filtering\Adapters\DatabaseAdapter(
            $this->getTable(),
            $this->getSearchableFields()
        );
    }

    private function resolveSearchContext(): ?ApplicationContext
    {
        if (method_exists($this, 'getContext')) {
            $context = $this->getContext();
            if ($context instanceof ApplicationContext) {
                return $context;
            }
        }

        if (method_exists(static::class, 'getContainer')) {
            $container = static::getContainer();
            if ($container instanceof ContainerInterface && $container->has(ApplicationContext::class)) {
                $context = $container->get(ApplicationContext::class);
                if ($context instanceof ApplicationContext) {
                    return $context;
                }
            }
        }

        return null;
    }
}
