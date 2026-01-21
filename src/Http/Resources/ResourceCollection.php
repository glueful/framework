<?php

declare(strict_types=1);

namespace Glueful\Http\Resources;

use ArrayIterator;
use Countable;
use Glueful\Http\Resources\Concerns\CollectsResources;
use Glueful\Http\Response;
use IteratorAggregate;
use JsonSerializable;

/**
 * Resource Collection
 *
 * Transforms a collection of resources into a consistent API response.
 * Supports pagination, additional data, and custom wrapping.
 *
 * Example usage:
 * ```php
 * class UserCollection extends ResourceCollection
 * {
 *     public string $collects = UserResource::class;
 *
 *     public function toArray(): array
 *     {
 *         return [
 *             'data' => $this->collection,
 *             'summary' => [
 *                 'total_users' => $this->count(),
 *             ],
 *         ];
 *     }
 * }
 * ```
 *
 * @template TResource of JsonResource
 * @implements IteratorAggregate<int, TResource>
 * @package Glueful\Http\Resources
 */
class ResourceCollection implements Countable, IteratorAggregate, JsonSerializable
{
    use CollectsResources;

    /**
     * The collection of resources
     *
     * @var array<TResource>
     */
    public array $collection;

    /**
     * Additional data to be added to the response
     *
     * @var array<string, mixed>
     */
    public array $with = [];

    /**
     * Additional meta data
     *
     * @var array<string, mixed>
     */
    public array $additional = [];

    /**
     * Pagination data if paginated
     *
     * @var array<string, mixed>|null
     */
    protected ?array $pagination = null;

    /**
     * Whether to preserve keys when collecting
     */
    protected bool $preserveKeys = false;

    /**
     * Create a new resource collection
     *
     * @param iterable<mixed> $resource
     */
    public function __construct(iterable $resource)
    {
        $this->collection = $this->collectResources($resource);
    }

    /**
     * Create a new collection instance
     *
     * @param iterable<mixed> $resource
     * @return static
     */
    public static function make(iterable $resource): static
    {
        /** @phpstan-ignore-next-line */
        return new static($resource);
    }

    /**
     * Transform the collection into an array
     *
     * Override this method to customize the collection transformation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->resolve(),
        ];
    }

    /**
     * Set pagination data
     *
     * @param array<string, mixed> $pagination
     * @return static
     */
    public function withPagination(array $pagination): static
    {
        $this->pagination = $pagination;

        return $this;
    }

    /**
     * Set pagination from query builder result
     *
     * Supports both QueryBuilder flat format and ORM meta format:
     * - Flat: { data: [], current_page: 1, per_page: 15, total: 100, last_page: 7 }
     * - Meta: { data: [], meta: { current_page: 1, per_page: 15, total: 100 } }
     *
     * @param array<string, mixed> $result Result from QueryBuilder::paginate() or ORM
     * @return static
     */
    public function withPaginationFrom(array $result): static
    {
        // Handle ORM format with 'meta' key
        if (isset($result['meta'])) {
            $meta = $result['meta'];
            $currentPage = $meta['current_page'] ?? 1;
            $perPage = $meta['per_page'] ?? 15;
            $total = $meta['total'] ?? 0;
            $lastPage = $meta['total_pages'] ?? $meta['last_page'] ?? (int) ceil($total / $perPage);

            $this->pagination = [
                'current_page' => $currentPage,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $lastPage,
                'from' => $meta['from'] ?? (($currentPage - 1) * $perPage + 1),
                'to' => $meta['to'] ?? min($currentPage * $perPage, $total),
                'has_next_page' => $meta['has_next_page'] ?? ($currentPage < $lastPage),
                'has_previous_page' => $meta['has_previous_page'] ?? ($currentPage > 1),
            ];

            return $this;
        }

        // Handle QueryBuilder flat format
        $currentPage = $result['current_page'] ?? 1;
        $perPage = $result['per_page'] ?? 15;
        $total = $result['total'] ?? 0;
        $lastPage = $result['last_page'] ?? (int) ceil($total / $perPage);

        $this->pagination = [
            'current_page' => $currentPage,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $lastPage,
            'from' => $result['from'] ?? (($currentPage - 1) * $perPage + 1),
            'to' => $result['to'] ?? min($currentPage * $perPage, $total),
            'has_next_page' => $result['has_more'] ?? ($currentPage < $lastPage),
            'has_previous_page' => $currentPage > 1,
        ];

        return $this;
    }

    /**
     * Add pagination links to the response
     *
     * @param string $baseUrl The base URL for pagination links
     * @param array<string, string|int> $queryParams Additional query parameters
     * @return static
     */
    public function withLinks(string $baseUrl, array $queryParams = []): static
    {
        if ($this->pagination === null) {
            return $this;
        }

        $currentPage = $this->pagination['current_page'] ?? 1;
        $lastPage = $this->pagination['total_pages'] ?? 1;
        $perPage = $this->pagination['per_page'] ?? 15;

        $buildUrl = function (int $page) use ($baseUrl, $queryParams, $perPage): string {
            $params = array_merge($queryParams, ['page' => $page]);
            if ($perPage !== 15) {
                $params['per_page'] = $perPage;
            }
            return rtrim($baseUrl, '/') . '?' . http_build_query($params);
        };

        $this->pagination['links'] = [
            'first' => $buildUrl(1),
            'last' => $buildUrl($lastPage),
            'prev' => $currentPage > 1 ? $buildUrl($currentPage - 1) : null,
            'next' => $currentPage < $lastPage ? $buildUrl($currentPage + 1) : null,
        ];

        return $this;
    }

    /**
     * Resolve the collection to an array of resolved resources
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolve(): array
    {
        return array_map(
            fn(JsonResource $resource) => $resource->resolve(),
            $this->preserveKeys ? $this->collection : array_values($this->collection)
        );
    }

    /**
     * Create an HTTP response from the collection
     *
     * @param int $status HTTP status code
     * @param array<string, string> $headers Additional headers
     * @return Response
     */
    public function toResponse(int $status = 200, array $headers = []): Response
    {
        $data = [
            'success' => true,
            'data' => $this->resolve(),
        ];

        // Add pagination if present
        if ($this->pagination !== null) {
            $data = array_merge($data, $this->pagination);
        }

        // Get with data from method (subclasses can override with())
        $withData = $this->with();

        // Add additional data
        $data = array_merge($data, $withData, $this->with, $this->additional);

        return new Response($data, $status, $headers);
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
     * Get additional data that should always be included
     *
     * Override this method to add data that should always be present.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [];
    }

    /**
     * Preserve keys when iterating
     *
     * @return static
     */
    public function preserveKeys(): static
    {
        $this->preserveKeys = true;

        return $this;
    }

    /**
     * Get the count of items in the collection
     */
    public function count(): int
    {
        return count($this->collection);
    }

    /**
     * Check if the collection is empty
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Check if the collection is not empty
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Get an iterator for the collection
     *
     * @return ArrayIterator<int, TResource>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->collection);
    }

    /**
     * Convert to array for JSON serialization
     *
     * @return array<int, array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return $this->resolve();
    }

    /**
     * Convert to JSON string
     */
    public function toJson(int $options = 0): string
    {
        $json = json_encode($this->jsonSerialize(), $options);

        return $json !== false ? $json : '[]';
    }

    /**
     * Convert the collection to a string (JSON)
     */
    public function __toString(): string
    {
        return $this->toJson();
    }
}
