<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering;

/**
 * Value object representing search results
 *
 * Encapsulates search results with metadata including total count,
 * execution time, and pagination information.
 */
final readonly class SearchResult
{
    /**
     * @param array<int, array<string, mixed>> $hits Search result documents
     * @param int $total Total number of matching documents
     * @param float $took Search execution time in milliseconds
     * @param int $offset Current pagination offset
     * @param int $limit Results per page limit
     * @param array<string, mixed> $meta Additional metadata from search engine
     */
    public function __construct(
        public array $hits,
        public int $total,
        public float $took = 0.0,
        public int $offset = 0,
        public int $limit = 20,
        public array $meta = [],
    ) {
    }

    /**
     * Create from search response
     *
     * @param array<int, array<string, mixed>> $hits
     * @param array<string, mixed> $meta
     */
    public static function create(
        array $hits,
        int $total,
        float $took = 0.0,
        int $offset = 0,
        int $limit = 20,
        array $meta = [],
    ): self {
        return new self($hits, $total, $took, $offset, $limit, $meta);
    }

    /**
     * Create empty result
     */
    public static function empty(): self
    {
        return new self([], 0);
    }

    /**
     * Check if there are any results
     */
    public function hasHits(): bool
    {
        return count($this->hits) > 0;
    }

    /**
     * Check if there are no results
     */
    public function isEmpty(): bool
    {
        return count($this->hits) === 0;
    }

    /**
     * Get the number of hits in this result set
     */
    public function count(): int
    {
        return count($this->hits);
    }

    /**
     * Check if there are more results available
     */
    public function hasMore(): bool
    {
        return ($this->offset + count($this->hits)) < $this->total;
    }

    /**
     * Get the current page number (1-indexed)
     */
    public function currentPage(): int
    {
        if ($this->limit <= 0) {
            return 1;
        }

        return (int) floor($this->offset / $this->limit) + 1;
    }

    /**
     * Get total number of pages
     */
    public function totalPages(): int
    {
        if ($this->limit <= 0 || $this->total <= 0) {
            return 1;
        }

        return (int) ceil($this->total / $this->limit);
    }

    /**
     * Get IDs from hits
     *
     * @param string $idField Field name containing the ID
     * @return array<int, string|int>
     */
    public function getIds(string $idField = 'id'): array
    {
        return array_column($this->hits, $idField);
    }

    /**
     * Pluck a specific field from all hits
     *
     * @return array<int, mixed>
     */
    public function pluck(string $field): array
    {
        return array_column($this->hits, $field);
    }

    /**
     * Convert to array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hits' => $this->hits,
            'total' => $this->total,
            'took' => $this->took,
            'pagination' => [
                'offset' => $this->offset,
                'limit' => $this->limit,
                'current_page' => $this->currentPage(),
                'total_pages' => $this->totalPages(),
                'has_more' => $this->hasMore(),
            ],
            'meta' => $this->meta,
        ];
    }
}
