<?php

declare(strict_types=1);

namespace Glueful\DI;

class LazyServiceProxy
{
    private string $factory;
    private ?object $instance = null;

    public function __construct(string $factory)
    {
        $this->factory = $factory;
    }

    /**
     * @param array<int, mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        if ($this->instance === null) {
            $this->instance = ($this->factory)::create();
        }

        // @phpstan-ignore-next-line Variable method call is intentional for proxy pattern
        return $this->instance->{$method}(...$args);
    }

    public function __get(string $name): mixed
    {
        if ($this->instance === null) {
            $this->instance = ($this->factory)::create();
        }

        // @phpstan-ignore-next-line Variable property access is intentional for proxy pattern
        return $this->instance->{$name};
    }

    public function __set(string $name, mixed $value): void
    {
        if ($this->instance === null) {
            $this->instance = ($this->factory)::create();
        }

        // @phpstan-ignore-next-line Variable property access is intentional for proxy pattern
        $this->instance->{$name} = $value;
    }

    public function __isset(string $name): bool
    {
        if ($this->instance === null) {
            $this->instance = ($this->factory)::create();
        }

        // @phpstan-ignore-next-line Variable property access is intentional for proxy pattern
        return isset($this->instance->{$name});
    }

    /**
     * Get the actual instance (forces instantiation)
     */
    public function getInstance(): object
    {
        if ($this->instance === null) {
            $this->instance = ($this->factory)::create();
        }

        return $this->instance;
    }

    /**
     * Check if the service has been instantiated
     */
    public function isInstantiated(): bool
    {
        return $this->instance !== null;
    }
}
