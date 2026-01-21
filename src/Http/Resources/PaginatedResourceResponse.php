<?php

declare(strict_types=1);

namespace Glueful\Http\Resources;

use Glueful\Http\Response;

/**
 * Paginated Resource Response
 *
 * Handles pagination metadata and link generation for resource collections.
 * Provides a fluent interface for building paginated API responses with
 * consistent structure and navigation links.
 *
 * @example
 * ```php
 * // From QueryBuilder pagination
 * $result = User::query()->paginate(page: 2, perPage: 25);
 *
 * return PaginatedResourceResponse::fromQueryResult($result, UserResource::class)
 *     ->withBaseUrl('/api/users')
 *     ->toResponse();
 *
 * // Manual configuration
 * return PaginatedResourceResponse::make($users, UserResource::class)
 *     ->setPage(2)
 *     ->setPerPage(25)
 *     ->setTotal(150)
 *     ->withBaseUrl('/api/users')
 *     ->toResponse();
 * ```
 *
 * @package Glueful\Http\Resources
 */
class PaginatedResourceResponse
{
    /**
     * The resource collection
     *
     * @var ResourceCollection<JsonResource<mixed>>
     */
    protected ResourceCollection $collection;

    /**
     * Current page number
     */
    protected int $currentPage = 1;

    /**
     * Items per page
     */
    protected int $perPage = 15;

    /**
     * Total number of items
     */
    protected int $total = 0;

    /**
     * Total number of pages
     */
    protected int $lastPage = 1;

    /**
     * First item number on current page
     */
    protected int $from = 0;

    /**
     * Last item number on current page
     */
    protected int $to = 0;

    /**
     * Base URL for pagination links
     */
    protected ?string $baseUrl = null;

    /**
     * Additional query parameters for links
     *
     * @var array<string, string|int>
     */
    protected array $queryParams = [];

    /**
     * Whether to include pagination links
     */
    protected bool $includeLinks = true;

    /**
     * Additional response data
     *
     * @var array<string, mixed>
     */
    protected array $additional = [];

    /**
     * Create a new paginated resource response
     *
     * @param ResourceCollection<JsonResource<mixed>> $collection
     */
    public function __construct(ResourceCollection $collection)
    {
        $this->collection = $collection;
    }

    /**
     * Create from an iterable of resources
     *
     * @param iterable<mixed> $resources
     * @param class-string<JsonResource<mixed>>|null $resourceClass
     * @return static
     */
    public static function make(iterable $resources, ?string $resourceClass = null): static
    {
        if ($resourceClass !== null) {
            /** @var ResourceCollection<JsonResource<mixed>> $collection */
            $collection = new AnonymousResourceCollection($resources, $resourceClass);
        } else {
            /** @var ResourceCollection<JsonResource<mixed>> $collection */
            $collection = ResourceCollection::make($resources);
        }

        return new static($collection);
    }

    /**
     * Create from a QueryBuilder pagination result
     *
     * @param array<string, mixed> $result Pagination result from QueryBuilder
     * @param class-string<JsonResource<mixed>>|null $resourceClass
     * @return static
     */
    public static function fromQueryResult(array $result, ?string $resourceClass = null): static
    {
        $data = $result['data'] ?? [];

        $instance = static::make($data, $resourceClass);

        // Set pagination metadata
        $instance->currentPage = $result['current_page'] ?? 1;
        $instance->perPage = $result['per_page'] ?? 15;
        $instance->total = $result['total'] ?? 0;
        $instance->lastPage = $result['last_page'] ?? 1;
        $instance->from = $result['from'] ?? 0;
        $instance->to = $result['to'] ?? 0;

        return $instance;
    }

    /**
     * Create from ORM Builder pagination result
     *
     * @param array<string, mixed> $result Pagination result with 'data' and 'meta' keys
     * @param class-string<JsonResource<mixed>>|null $resourceClass
     * @return static
     */
    public static function fromOrmResult(array $result, ?string $resourceClass = null): static
    {
        $data = $result['data'] ?? [];
        $meta = $result['meta'] ?? [];

        $instance = static::make($data, $resourceClass);

        // Set pagination metadata from meta array
        $instance->currentPage = $meta['current_page'] ?? 1;
        $instance->perPage = $meta['per_page'] ?? 15;
        $instance->total = $meta['total'] ?? 0;
        $instance->lastPage = $meta['total_pages'] ?? $meta['last_page'] ?? 1;
        $instance->from = $meta['from'] ?? 0;
        $instance->to = $meta['to'] ?? 0;

        return $instance;
    }

    /**
     * Set the current page
     *
     * @param int $page
     * @return static
     */
    public function setPage(int $page): static
    {
        $this->currentPage = max(1, $page);
        $this->recalculateBounds();

        return $this;
    }

    /**
     * Set items per page
     *
     * @param int $perPage
     * @return static
     */
    public function setPerPage(int $perPage): static
    {
        $this->perPage = max(1, $perPage);
        $this->recalculateBounds();

        return $this;
    }

    /**
     * Set the total number of items
     *
     * @param int $total
     * @return static
     */
    public function setTotal(int $total): static
    {
        $this->total = max(0, $total);
        $this->recalculateBounds();

        return $this;
    }

    /**
     * Recalculate pagination bounds
     */
    protected function recalculateBounds(): void
    {
        $this->lastPage = max(1, (int) ceil($this->total / $this->perPage));
        $offset = ($this->currentPage - 1) * $this->perPage;
        $this->from = $this->total > 0 ? $offset + 1 : 0;
        $this->to = min($offset + $this->perPage, $this->total);
    }

    /**
     * Set the base URL for pagination links
     *
     * @param string $url
     * @return static
     */
    public function withBaseUrl(string $url): static
    {
        $this->baseUrl = rtrim($url, '/');

        return $this;
    }

    /**
     * Add query parameters to pagination links
     *
     * @param array<string, string|int> $params
     * @return static
     */
    public function withQueryParams(array $params): static
    {
        $this->queryParams = array_merge($this->queryParams, $params);

        return $this;
    }

    /**
     * Disable pagination links
     *
     * @return static
     */
    public function withoutLinks(): static
    {
        $this->includeLinks = false;

        return $this;
    }

    /**
     * Add additional data to the response
     *
     * @param array<string, mixed> $data
     * @return static
     */
    public function additional(array $data): static
    {
        $this->additional = array_merge($this->additional, $data);

        return $this;
    }

    /**
     * Check if there's a next page
     *
     * @return bool
     */
    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * Check if there's a previous page
     *
     * @return bool
     */
    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    /**
     * Get pagination links
     *
     * @return array<string, string|null>
     */
    public function getLinks(): array
    {
        if (!$this->includeLinks || $this->baseUrl === null) {
            return [];
        }

        return [
            'first' => $this->buildUrl(1),
            'last' => $this->buildUrl($this->lastPage),
            'prev' => $this->hasPreviousPage() ? $this->buildUrl($this->currentPage - 1) : null,
            'next' => $this->hasNextPage() ? $this->buildUrl($this->currentPage + 1) : null,
        ];
    }

    /**
     * Build a pagination URL
     *
     * @param int $page
     * @return string
     */
    protected function buildUrl(int $page): string
    {
        $params = array_merge($this->queryParams, ['page' => $page]);

        if ($this->perPage !== 15) {
            $params['per_page'] = $this->perPage;
        }

        return $this->baseUrl . '?' . http_build_query($params);
    }

    /**
     * Get pagination metadata
     *
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return [
            'current_page' => $this->currentPage,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'total_pages' => $this->lastPage,
            'from' => $this->from,
            'to' => $this->to,
            'has_next_page' => $this->hasNextPage(),
            'has_previous_page' => $this->hasPreviousPage(),
        ];
    }

    /**
     * Create an HTTP response from the paginated collection
     *
     * @param int $status HTTP status code
     * @param array<string, string> $headers Additional headers
     * @return Response
     */
    public function toResponse(int $status = 200, array $headers = []): Response
    {
        $data = [
            'success' => true,
            'data' => $this->collection->resolve(),
        ];

        // Add pagination metadata
        $data = array_merge($data, $this->getMeta());

        // Add pagination links
        $links = $this->getLinks();
        if ($links !== []) {
            $data['links'] = $links;
        }

        // Add additional data
        $data = array_merge($data, $this->additional);

        return new Response($data, $status, $headers);
    }

    /**
     * Convert to array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'data' => $this->collection->resolve(),
        ];

        $data = array_merge($data, $this->getMeta());

        $links = $this->getLinks();
        if ($links !== []) {
            $data['links'] = $links;
        }

        return array_merge($data, $this->additional);
    }

    /**
     * Get the underlying collection
     *
     * @return ResourceCollection<JsonResource<mixed>>
     */
    public function getCollection(): ResourceCollection
    {
        return $this->collection;
    }
}
