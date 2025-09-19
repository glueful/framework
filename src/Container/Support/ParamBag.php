<?php

declare(strict_types=1);

namespace Glueful\Container\Support;

final class ParamBag
{
    /** @param array<string, mixed> $params */
    public function __construct(private array $params)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->params[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->params);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->params;
    }
}
