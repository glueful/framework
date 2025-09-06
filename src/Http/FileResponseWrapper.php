<?php

declare(strict_types=1);

namespace Glueful\Http;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Wrapper for BinaryFileResponse to support Laravel-style header() method chaining
 *
 * This wrapper allows ServiceProviders to use the fluent API pattern:
 * response()->file($path)->header('Content-Type', 'image/png')
 */
class FileResponseWrapper
{
    public function __construct(private BinaryFileResponse $response)
    {
    }

    /**
     * Add a header to the response for method chaining
     */
    public function header(string $key, string $value): self
    {
        $this->response->headers->set($key, $value);
        return $this;
    }

    /**
     * Get the underlying BinaryFileResponse
     */
    public function getResponse(): BinaryFileResponse
    {
        return $this->response;
    }

    /**
     * Forward method calls to the underlying BinaryFileResponse
     * @param array<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        // @phpstan-ignore-next-line - Dynamic method call is intentional for wrapper
        $result = $this->response->{$method}(...$arguments);

        // If the method returns the response instance, return this wrapper for chaining
        if ($result === $this->response) {
            return $this;
        }

        return $result;
    }

    /**
     * Forward property access to the underlying BinaryFileResponse
     */
    public function __get(string $property): mixed
    {
        // @phpstan-ignore-next-line - Dynamic property access is intentional for wrapper
        return $this->response->{$property};
    }

    /**
     * Forward property setting to the underlying BinaryFileResponse
     */
    public function __set(string $property, mixed $value): void
    {
        // @phpstan-ignore-next-line - Dynamic property access is intentional for wrapper
        $this->response->{$property} = $value;
    }
}
