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
        return $this->container->has($id);
    }

    public function getSymfonyContainer(): SymfonyContainerInterface
    {
        return $this->container;
    }

    public function isCompiled(): bool
    {
        return !$this->container instanceof \Symfony\Component\DependencyInjection\ContainerBuilder;
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
}
