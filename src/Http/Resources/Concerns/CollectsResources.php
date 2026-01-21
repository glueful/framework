<?php

declare(strict_types=1);

namespace Glueful\Http\Resources\Concerns;

use Glueful\Http\Resources\JsonResource;

/**
 * Resource Collection Helpers
 *
 * Provides methods for collecting resources into resource instances.
 *
 * @package Glueful\Http\Resources\Concerns
 */
trait CollectsResources
{
    /**
     * The resource class this collection collects
     *
     * @var string|null
     */
    public ?string $collects = null;

    /**
     * Collect resources into resource instances
     *
     * @param iterable<mixed> $resources
     * @return array<int|string, JsonResource<mixed>>
     */
    protected function collectResources(iterable $resources): array
    {
        $collected = [];

        foreach ($resources as $resource) {
            if ($resource instanceof JsonResource) {
                $collected[] = $resource;
            } else {
                $collected[] = $this->newResourceInstance($resource);
            }
        }

        return $collected;
    }

    /**
     * Create a new resource instance for the given item
     *
     * @param mixed $resource The resource data
     * @return JsonResource<mixed>
     */
    protected function newResourceInstance(mixed $resource): JsonResource
    {
        $collects = $this->collects();

        return new $collects($resource);
    }

    /**
     * Get the resource class this collection collects
     *
     * @return string
     */
    public function collects(): string
    {
        if ($this->collects !== null) {
            return $this->collects;
        }

        return JsonResource::class;
    }

    /**
     * Set the resource class this collection collects
     *
     * @param string $collects
     * @return static
     */
    public function setCollects(string $collects): static
    {
        $this->collects = $collects;

        return $this;
    }
}
