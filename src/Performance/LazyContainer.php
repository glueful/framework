<?php

namespace Glueful\Performance;

class LazyContainer
{
    /**
     * @var array<string, \Closure> Factory functions for lazy loading
     */
    private array $factories = [];

    /**
     * @var array<string, mixed> Cached instances
     */
    private array $instances = [];

    /**
     * Register a factory for lazy loading
     */
    public function register(string $id, \Closure $factory): void
    {
        $this->factories[$id] = $factory;
    }

    /**
     * Get or create an instance
     *
     * @return mixed The resolved instance
     */
    public function get(string $id): mixed
    {
        if (!isset($this->instances[$id])) {
            if (!isset($this->factories[$id])) {
                throw new \Exception("No factory registered for '{$id}'");
            }

            $this->instances[$id] = ($this->factories[$id])();
        }

        return $this->instances[$id];
    }
}
