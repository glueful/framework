<?php

declare(strict_types=1);

namespace Glueful\DTOs;

/**
 * Paginated Response DTO
 *
 * Standard DTO for paginated API responses with metadata about pagination
 * and flexible content serialization based on groups.
 */
class PaginatedResponseDTO
{
    /** @var array<int, mixed> */
    public array $data = [];
    public PaginationMetaDTO $pagination;

    /** @var array<string, mixed>|null */
    public ?array $meta = null;

    /** @var array<string, string|null>|null */
    public ?array $links = null;
    public ?string $requestId = null;
    public ?float $executionTime = null;
    public ?string $memoryUsage = null;
    public \DateTime $generatedAt;

    /**
     * @param array<int, mixed> $data
     */
    public function __construct(
        array $data = [],
        int $currentPage = 1,
        int $perPage = 20,
        int $total = 0,
        ?int $totalPages = null
    ) {
        $this->data = $data;
        $this->pagination = new PaginationMetaDTO(
            $currentPage,
            $perPage,
            $total,
            $totalPages ?? (int) ceil($total / $perPage)
        );
        $this->generatedAt = new \DateTime();
    }

    /**
     * Create paginated response from array data
     *
     * @param array<int, mixed> $data
     * @param array<string, mixed>|null $meta
     */
    public static function create(
        array $data,
        int $currentPage,
        int $perPage,
        int $total,
        ?array $meta = null
    ): self {
        $response = new self($data, $currentPage, $perPage, $total);
        $response->meta = $meta;
        return $response;
    }

    /**
     * Add pagination links
     *
     * @param array<string, mixed> $params
     */
    public function withLinks(string $baseUrl, array $params = []): self
    {
        $pagination = $this->pagination;
        $queryParams = array_merge($params, ['per_page' => $pagination->perPage]);

        $this->links = [
            'first' => $this->buildUrl($baseUrl, array_merge($queryParams, ['page' => 1])),
            'last' => $this->buildUrl($baseUrl, array_merge($queryParams, ['page' => $pagination->totalPages])),
            'prev' => $pagination->currentPage > 1
                ? $this->buildUrl($baseUrl, array_merge($queryParams, ['page' => $pagination->currentPage - 1]))
                : null,
            'next' => $pagination->currentPage < $pagination->totalPages
                ? $this->buildUrl($baseUrl, array_merge($queryParams, ['page' => $pagination->currentPage + 1]))
                : null,
        ];

        return $this;
    }

    /**
     * Add metadata
     *
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): self
    {
        $this->meta = array_merge($this->meta ?? [], $meta);
        return $this;
    }

    /**
     * Add debug information
     */
    public function withDebugInfo(string $requestId, float $executionTime, int $memoryUsage): self
    {
        $this->requestId = $requestId;
        $this->executionTime = $executionTime;
        $this->memoryUsage = $this->formatBytes($memoryUsage);
        return $this;
    }

    /**
     * Build URL with query parameters
     *
     * @param array<string, mixed> $params
     */
    private function buildUrl(string $baseUrl, array $params): string
    {
        $query = http_build_query($params);
        return $baseUrl . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Format bytes to human-readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = floor(log($bytes, 1024));
        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }

    /**
     * Check if there are more pages
     */
    public function hasMorePages(): bool
    {
        return $this->pagination->currentPage < $this->pagination->totalPages;
    }

    /**
     * Check if this is the first page
     */
    public function isFirstPage(): bool
    {
        return $this->pagination->currentPage === 1;
    }

    /**
     * Check if this is the last page
     */
    public function isLastPage(): bool
    {
        return $this->pagination->currentPage >= $this->pagination->totalPages;
    }

    /**
     * Get total count
     */
    public function getTotalCount(): int
    {
        return $this->pagination->total;
    }

    /**
     * Get current page count
     */
    public function getCurrentPageCount(): int
    {
        return count($this->data);
    }
}
