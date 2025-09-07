<?php

declare(strict_types=1);

namespace Glueful\DI;

class DeferredServiceProxy
{
    private string $factory;
    private ?object $instance = null;
    private bool $requestTime = false;

    public function __construct(string $factory)
    {
        $this->factory = $factory;
    }

    /**
     * @param array<int, mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        $this->ensureInstantiated();
        // @phpstan-ignore-next-line Variable method call is intentional for proxy pattern
        return $this->instance?->{$method}(...$args);
    }

    public function __get(string $name): mixed
    {
        $this->ensureInstantiated();
        // @phpstan-ignore-next-line Variable property access is intentional for proxy pattern
        return $this->instance?->{$name};
    }

    public function __set(string $name, mixed $value): void
    {
        $this->ensureInstantiated();
        if ($this->instance) {
            // @phpstan-ignore-next-line Variable property access is intentional for proxy pattern
            $this->instance->{$name} = $value;
        }
    }

    public function __isset(string $name): bool
    {
        $this->ensureInstantiated();
        // @phpstan-ignore-next-line Variable property access is intentional for proxy pattern
        return isset($this->instance?->{$name});
    }

    /**
     * Mark that we're now in request time (triggers instantiation)
     */
    public function activateRequestTime(): void
    {
        $this->requestTime = true;
    }

    /**
     * Get the actual instance (forces instantiation)
     */
    public function getInstance(): object
    {
        $this->ensureInstantiated();
        return $this->instance;
    }

    /**
     * Check if the service has been instantiated
     */
    public function isInstantiated(): bool
    {
        return $this->instance !== null;
    }

    private function ensureInstantiated(): void
    {
        if ($this->instance === null) {
            $this->instance = ($this->factory)::create();
        }
    }
}
