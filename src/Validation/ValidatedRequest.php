<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Symfony\Component\HttpFoundation\Request;

/**
 * Validated Request wrapper
 *
 * Provides access to validated data after validation passes.
 * Type-hint this in controller methods to get validated data.
 *
 * @example
 * public function store(ValidatedRequest $request): Response
 * {
 *     $email = $request->get('email');
 *     $allData = $request->validated();
 *     $subset = $request->only(['email', 'name']);
 * }
 */
class ValidatedRequest
{
    /**
     * @param Request $request The underlying HTTP request
     * @param array<string, mixed> $validated The validated data
     */
    public function __construct(
        private Request $request,
        private array $validated = []
    ) {
    }

    /**
     * Create from a validated request
     *
     * Retrieves validated data from request attributes.
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            $request,
            $request->attributes->get('validated', [])
        );
    }

    /**
     * Get all validated data
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->validated;
    }

    /**
     * Get all validated data (alias for validated())
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->validated;
    }

    /**
     * Get a specific validated value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->validated[$key] ?? $default;
    }

    /**
     * Get a validated value as string
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key);
        return is_string($value) ? $value : $default;
    }

    /**
     * Get a validated value as integer
     */
    public function integer(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Get a validated value as float
     */
    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);
        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Get a validated value as boolean
     */
    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        return $default;
    }

    /**
     * Get a validated value as array
     *
     * @param array<mixed> $default
     * @return array<mixed>
     */
    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key);
        return is_array($value) ? $value : $default;
    }

    /**
     * Get only specified keys from validated data
     *
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->validated, array_flip($keys));
    }

    /**
     * Get all validated data except specified keys
     *
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->validated, array_flip($keys));
    }

    /**
     * Check if validated data has a key
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->validated);
    }

    /**
     * Check if validated data has any of the given keys
     *
     * @param array<string> $keys
     */
    public function hasAny(array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a key is present and not empty
     */
    public function filled(string $key): bool
    {
        if (!$this->has($key)) {
            return false;
        }

        $value = $this->get($key);
        return $value !== '' && $value !== null && $value !== [];
    }

    /**
     * Check if a key is missing or empty
     */
    public function missing(string $key): bool
    {
        return !$this->has($key);
    }

    /**
     * Get the underlying HTTP request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Get the original (unvalidated) input from the request
     */
    public function input(string $key, mixed $default = null): mixed
    {
        // Check query parameters first, then request (POST) parameters
        $queryParams = $this->request->query->all();
        if (array_key_exists($key, $queryParams)) {
            return $queryParams[$key];
        }

        $requestParams = $this->request->request->all();
        if (array_key_exists($key, $requestParams)) {
            return $requestParams[$key];
        }

        return $default;
    }

    /**
     * Get query parameter from the original request
     */
    public function query(string $key, mixed $default = null): mixed
    {
        $params = $this->request->query->all();
        return array_key_exists($key, $params) ? $params[$key] : $default;
    }

    /**
     * Get POST parameter from the original request
     */
    public function post(string $key, mixed $default = null): mixed
    {
        $params = $this->request->request->all();
        return array_key_exists($key, $params) ? $params[$key] : $default;
    }

    /**
     * Get route parameter from the request
     */
    public function route(string $key, mixed $default = null): mixed
    {
        $routeParams = $this->request->attributes->get('route_params', []);
        return $routeParams[$key] ?? $default;
    }

    /**
     * Merge additional data with validated data
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function merge(array $data): array
    {
        return array_merge($this->validated, $data);
    }

    /**
     * Get validated data with defaults filled in
     *
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public function withDefaults(array $defaults): array
    {
        return array_merge($defaults, $this->validated);
    }

    /**
     * Convert to array (same as validated())
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->validated;
    }

    /**
     * Dynamically access validated data
     */
    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /**
     * Check if key exists
     */
    public function __isset(string $name): bool
    {
        return $this->has($name);
    }
}
