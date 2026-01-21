<?php

declare(strict_types=1);

namespace Glueful\Http\Resources\Concerns;

/**
 * Delegates Property Access to Resource
 *
 * Allows accessing properties on the resource class as if they were
 * properties on the underlying resource data (array, object, or model).
 *
 * @package Glueful\Http\Resources\Concerns
 */
trait DelegatesToResource
{
    /**
     * Dynamically get a property from the resource
     */
    public function __get(string $key): mixed
    {
        return $this->getResourceAttribute($key);
    }

    /**
     * Dynamically check if a property exists on the resource
     */
    public function __isset(string $key): bool
    {
        return $this->hasResourceAttribute($key);
    }

    /**
     * Get an attribute from the underlying resource
     */
    protected function getResourceAttribute(string $key): mixed
    {
        if (is_array($this->resource)) {
            return $this->resource[$key] ?? null;
        }

        if (is_object($this->resource)) {
            return $this->resource->$key ?? null;
        }

        return null;
    }

    /**
     * Check if the underlying resource has an attribute
     */
    protected function hasResourceAttribute(string $key): bool
    {
        if (is_array($this->resource)) {
            return array_key_exists($key, $this->resource);
        }

        if (is_object($this->resource)) {
            return isset($this->resource->$key) || property_exists($this->resource, $key);
        }

        return false;
    }

    /**
     * Get the underlying resource
     */
    public function getResource(): mixed
    {
        return $this->resource;
    }
}
