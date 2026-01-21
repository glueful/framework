<?php

declare(strict_types=1);

namespace Glueful\Http\Resources;

/**
 * Anonymous Resource Collection
 *
 * Created automatically when calling JsonResource::collection().
 * Wraps a collection of items using the specified resource class.
 *
 * @template TResource of JsonResource
 * @extends ResourceCollection<TResource>
 * @package Glueful\Http\Resources
 */
class AnonymousResourceCollection extends ResourceCollection
{
    /**
     * Create a new anonymous resource collection
     *
     * @param iterable<mixed> $resource The collection of items
     * @param class-string<TResource> $collects The resource class to use
     */
    public function __construct(iterable $resource, string $collects)
    {
        $this->collects = $collects;

        parent::__construct($resource);
    }

    /**
     * Collect resources into the appropriate resource class
     *
     * @param iterable<mixed> $resources
     * @return array<int|string, JsonResource<mixed>>
     */
    protected function collectResources(iterable $resources): array
    {
        $collected = [];
        $collects = $this->collects ?? JsonResource::class;

        foreach ($resources as $key => $resource) {
            if ($resource instanceof JsonResource) {
                $collected[$key] = $resource;
            } else {
                $collected[$key] = new $collects($resource);
            }
        }

        return $collected;
    }
}
