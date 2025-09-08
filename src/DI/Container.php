<?php

declare(strict_types=1);

namespace Glueful\DI;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;

/**
 * Glueful Container - Clean interface over pure Symfony DI
 */
class Container implements ContainerInterface
{
    /** @var array<string, mixed> Runtime service instances that override container definitions */
    private array $instances = [];

    public function __construct(
        private SymfonyContainerInterface $container
    ) {
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function get(string $id): mixed
    {
        // Check runtime instances first (highest precedence)
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        try {
            return $this->container->get($id);
        } catch (\Throwable $e) {
            $class = get_class($e);
            if (
                $class === '\\Symfony\\Component\\DependencyInjection\\Exception\\ServiceNotFoundException'
                || str_contains($class, 'ServiceNotFound')
            ) {
                $suggestion = $this->findClosestServiceId($id);
                $available = $this->getServiceIds();
                $preview = array_slice($available, 0, 10);
                $message = "Service [{$id}] not found.";
                if ($suggestion !== null) {
                    $message .= " Did you mean [{$suggestion}]?";
                }
                if (count($preview) > 0) {
                    $more = max(count($available) - count($preview), 0);
                    $message .= " Available services: " . implode(', ', $preview) .
                        ($more > 0 ? "... and {$more} more." : '');
                }
                throw new \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException(
                    $id,
                    null,
                    null,
                    [],
                    $message
                );
            }
            throw $e;
        }
    }

    /**
     * Optional variant of get() with generic return.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T|null
     */
    public function getOptional(string $id): mixed
    {
        return $this->has($id) ? $this->get($id) : null;
    }

    public function has(string $id): bool
    {
        // Service exists if it's in runtime instances OR in the container
        return isset($this->instances[$id]) || $this->container->has($id);
    }

    public function getSymfonyContainer(): SymfonyContainerInterface
    {
        return $this->container;
    }

    public function isCompiled(): bool
    {
        // ContainerBuilder has isCompiled() method after compilation
        if ($this->container instanceof \Symfony\Component\DependencyInjection\ContainerBuilder) {
            return $this->container->isCompiled();
        }
        // Non-ContainerBuilder instances (dumped containers) are always compiled
        return true;
    }

    /**
     * Indicates whether the container is frozen (compiled) and immutable.
     * Alias of isCompiled() for readability with extension docs.
     */
    public function isFrozen(): bool
    {
        return $this->isCompiled();
    }

    public function getParameter(string $name): mixed
    {
        return $this->container->getParameter($name);
    }

    public function hasParameter(string $name): bool
    {
        return $this->container->hasParameter($name);
    }

    /**
     * @return array<string>
     */
    public function getServiceIds(): array
    {
        // Handle both ContainerBuilder and compiled containers
        if ($this->container instanceof \Symfony\Component\DependencyInjection\ContainerBuilder) {
            return $this->container->getServiceIds();
        }

        // For compiled containers, try to get service IDs via reflection
        try {
            $reflection = new \ReflectionClass($this->container);
            if ($reflection->hasMethod('getServiceIds')) {
                $method = $reflection->getMethod('getServiceIds');
                if ($method->isPublic()) {
                    return $method->invoke($this->container);
                }
            }
        } catch (\ReflectionException) {
            // Fallback to empty array if reflection fails
        }

        // Fallback for basic containers - return empty array
        return [];
    }

    private function findClosestServiceId(string $needle): ?string
    {
        $services = $this->getServiceIds();
        $closest = null;
        $distance = -1;
        foreach ($services as $service) {
            $lev = levenshtein($needle, (string) $service);
            if ($lev <= 3 && ($distance < 0 || $lev < $distance)) {
                $closest = (string) $service;
                $distance = $lev;
            }
        }
        return $closest;
    }

    /**
     * Set a service in the container
     *
     * @param string $id Service identifier
     * @param mixed $service Service instance
     * @return void
     */
    public function set(string $id, mixed $service): void
    {
        if ($this->container instanceof \Symfony\Component\DependencyInjection\ContainerBuilder) {
            // Disallow mutations once compiled/frozen
            if ($this->container->isCompiled()) {
                throw new \RuntimeException('Cannot set services on compiled container');
            }
            $this->container->set($id, $service);
            return;
        }
        throw new \RuntimeException('Cannot set services on compiled container');
    }

    /**
     * Register a service provider
     *
     * @param object $serviceProvider Service provider instance
     * @return void
     */
    public function register(object $serviceProvider): void
    {
        // Check if the service provider has a register method
        if (method_exists($serviceProvider, 'register')) {
            // Pass the underlying Symfony container, not the wrapper
            $serviceProvider->register($this->container);
        }
    }

    /**
     * Bind a specific instance to a service identifier
     *
     * This creates a runtime override that takes precedence over container definitions.
     * Useful for request-scoped customization, middleware overrides, and testing.
     *
     * @param string $id Service identifier (typically a class name)
     * @param mixed $instance The instance to bind
     * @return void
     *
     * @example
     * // Override default Projector with custom expanders for this request
     * $container->instance(Projector::class, $customProjector);
     *
     * // Now all calls to get(Projector::class) return the custom instance
     * $projector = $container->get(Projector::class); // returns $customProjector
     */
    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /**
     * Remove a runtime instance binding
     *
     * After calling this, get() will fall back to the container's definition.
     *
     * @param string $id Service identifier to remove
     * @return void
     */
    public function forgetInstance(string $id): void
    {
        unset($this->instances[$id]);
    }

    /**
     * Clear all runtime instance bindings
     *
     * Useful for cleaning up after requests or in testing scenarios.
     *
     * @return void
     */
    public function clearInstances(): void
    {
        $this->instances = [];
    }

    /**
     * Get all currently bound runtime instances
     *
     * @return array<string, mixed> Service ID => Instance mappings
     */
    public function getInstances(): array
    {
        return $this->instances;
    }
}
