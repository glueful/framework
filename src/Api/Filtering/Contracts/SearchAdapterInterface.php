<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Contracts;

use Glueful\Api\Filtering\SearchResult;

/**
 * Contract for search engine adapters
 *
 * Implementations provide search functionality through various backends
 * including database LIKE queries, Elasticsearch, Meilisearch, and Algolia.
 */
interface SearchAdapterInterface
{
    /**
     * Search documents
     *
     * @param string $query Search query string
     * @param array<string, mixed> $options Search options including:
     *   - fields: array of fields to search
     *   - filters: array of ParsedFilter objects
     *   - sorts: array of ParsedSort objects
     *   - offset: pagination offset
     *   - limit: results limit
     * @return SearchResult Search results with hits, total count, and timing
     */
    public function search(string $query, array $options = []): SearchResult;

    /**
     * Index a document
     *
     * @param string $id Document identifier
     * @param array<string, mixed> $document Document data to index
     */
    public function index(string $id, array $document): void;

    /**
     * Update an indexed document
     *
     * @param string $id Document identifier
     * @param array<string, mixed> $document Updated document data
     */
    public function update(string $id, array $document): void;

    /**
     * Delete a document from the index
     *
     * @param string $id Document identifier
     */
    public function delete(string $id): void;

    /**
     * Bulk index multiple documents
     *
     * @param array<string, array<string, mixed>> $documents Map of ID => document data
     */
    public function bulkIndex(array $documents): void;

    /**
     * Check if the search adapter is available and configured
     */
    public function isAvailable(): bool;

    /**
     * Get the name of this adapter
     */
    public function getName(): string;
}
