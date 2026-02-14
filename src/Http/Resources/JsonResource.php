<?php

declare(strict_types=1);

namespace Glueful\Http\Resources;

use ArrayAccess;
use Glueful\Http\Resources\Concerns\ConditionallyLoadsAttributes;
use Glueful\Http\Resources\Concerns\DelegatesToResource;
use Glueful\Http\Resources\Support\MissingValue;
use Glueful\Http\Response;
use JsonSerializable;

/**
 * JSON Resource - API Response Transformer
 *
 * Transforms models/data into consistent JSON API responses.
 * Extend this class to define your resource transformations.
 *
 * Example usage:
 * ```php
 * class UserResource extends JsonResource
 * {
 *     public function toArray(): array
 *     {
 *         return [
 *             'id' => $this->uuid,
 *             'name' => $this->name,
 *             'email' => $this->email,
 *             'posts' => PostResource::collection($this->whenLoaded('posts')),
 *         ];
 *     }
 * }
 *
 * // In controller
 * return UserResource::make($user)->toResponse();
 * return UserResource::collection($users)->toResponse();
 * ```
 *
 * @template TResource
 * @implements ArrayAccess<string, mixed>
 * @package Glueful\Http\Resources
 */
class JsonResource implements JsonSerializable, ArrayAccess
{
    use ConditionallyLoadsAttributes;
    use DelegatesToResource;

    /**
     * The HTTP request associated with this resource (set by middleware)
     */
    public ?\Symfony\Component\HttpFoundation\Request $request = null;

    /**
     * The resource instance being transformed
     *
     * @var TResource
     */
    public mixed $resource;

    /**
     * Additional data to be added to the response
     *
     * @var array<string, mixed>
     */
    public array $with = [];

    /**
     * Additional meta data for the response
     *
     * @var array<string, mixed>
     */
    public array $additional = [];

    /**
     * The "data" wrapper to use
     *
     * Set to null to disable wrapping.
     */
    public static ?string $wrap = 'data';

    /**
     * Create a new resource instance
     *
     * @param TResource $resource
     */
    public function __construct(mixed $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Create a new resource instance
     *
     * @param TResource $resource
     * @return static
     */
    public static function make(mixed $resource): static
    {
        return new static($resource);
    }

    /**
     * Create a new resource collection
     *
     * @param iterable<TResource> $resources
     * @return AnonymousResourceCollection<static>
     */
    public static function collection(iterable $resources): AnonymousResourceCollection
    {
        return new AnonymousResourceCollection($resources, static::class);
    }

    /**
     * Transform the resource into an array
     *
     * Override this method to define your transformation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->resource === null) {
            return [];
        }

        // Default: return all attributes
        if (is_array($this->resource)) {
            return $this->resource;
        }

        if (method_exists($this->resource, 'toArray')) {
            return $this->resource->toArray();
        }

        if (method_exists($this->resource, 'getAttributes')) {
            return $this->resource->getAttributes();
        }

        return (array) $this->resource;
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
     * Disable data wrapping globally
     */
    public static function withoutWrapping(): void
    {
        static::$wrap = null;
    }

    /**
     * Set custom wrapper key
     */
    public static function wrap(string $value): void
    {
        static::$wrap = $value;
    }

    /**
     * Resolve the resource to an array
     *
     * Handles filtering of missing values and resolving nested resources.
     *
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $data = $this->toArray();

        // Filter out missing values
        $data = $this->filterMissingValues($data);

        // Resolve nested resources
        $data = $this->resolveNestedResources($data);

        // Merge conditional arrays
        $data = $this->mergeConditionalArrays($data);

        return $data;
    }

    /**
     * Filter out MissingValue instances from the data
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function filterMissingValues(array $data): array
    {
        $filtered = [];

        foreach ($data as $key => $value) {
            if ($value instanceof MissingValue) {
                continue;
            }

            if (is_array($value)) {
                $filtered[$key] = $this->filterMissingValues($value);
            } else {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Resolve nested JsonResource instances
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function resolveNestedResources(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof JsonResource) {
                $data[$key] = $value->resolve();
            } elseif ($value instanceof ResourceCollection) {
                $data[$key] = $value->resolve();
            } elseif (is_array($value)) {
                $data[$key] = $this->resolveNestedResources($value);
            }
        }

        return $data;
    }

    /**
     * Merge conditional arrays (from mergeWhen) into the parent array
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mergeConditionalArrays(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_int($key) && is_array($value)) {
                // This is a merge array, merge it into the result
                $result = array_merge($result, $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Create an HTTP response from the resource
     *
     * @param int $status HTTP status code
     * @param array<string, string> $headers Additional headers
     * @return Response
     */
    public function toResponse(int $status = 200, array $headers = []): Response
    {
        $data = $this->resolve();

        // Apply wrapping
        if (static::$wrap !== null) {
            $data = [static::$wrap => $data];
        }

        // Merge additional data
        $withData = array_merge($this->with(), $this->with);
        $data = array_merge($data, $withData, $this->additional);

        // Add success flag
        $data = array_merge(['success' => true], $data);

        return new Response($data, $status, $headers);
    }

    /**
     * Prepare the resource for JSON serialization
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->resolve();
    }

    /**
     * Transform the resource to a JSON string
     */
    public function toJson(int $options = 0): string
    {
        $json = json_encode($this->jsonSerialize(), $options);

        return $json !== false ? $json : '{}';
    }

    /**
     * Determine if the given attribute exists on the resource
     */
    public function offsetExists(mixed $offset): bool
    {
        if (is_array($this->resource)) {
            return isset($this->resource[$offset]);
        }

        if (is_object($this->resource)) {
            return isset($this->resource->$offset);
        }

        return false;
    }

    /**
     * Get an attribute from the resource
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (is_array($this->resource)) {
            return $this->resource[$offset] ?? null;
        }

        if (is_object($this->resource)) {
            return $this->resource->$offset ?? null;
        }

        return null;
    }

    /**
     * Set an attribute on the resource (not supported)
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Resources are read-only
    }

    /**
     * Unset an attribute on the resource (not supported)
     */
    public function offsetUnset(mixed $offset): void
    {
        // Resources are read-only
    }

    /**
     * Convert the resource to a string (JSON)
     */
    public function __toString(): string
    {
        return $this->toJson();
    }
}
