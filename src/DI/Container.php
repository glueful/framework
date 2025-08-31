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

    public function get(string $id): mixed
    {
        try {
            return $this->container->get($id);
        } catch (\Throwable $e) {
            $class = get_class($e);
            if ($class === '\\Symfony\\Component\\DependencyInjection\\Exception\\ServiceNotFoundException'
                || str_contains($class, 'ServiceNotFound')) {
                $suggestion = $this->findClosestServiceId($id);
                $available = $this->getServiceIds();
                $preview = array_slice($available, 0, 10);
                $message = "Service [{$id}] not found.";
                if ($suggestion !== null) {
                    $message .= " Did you mean [{$suggestion}]?";
                }
                if (!empty($preview)) {
                    $more = max(count($available) - count($preview), 0);
                    $message .= " Available services: " . implode(', ', $preview) . ($more > 0 ? "... and {$more} more." : '');
                }
                throw new \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException($id, previous: $e, message: $message);
            }
            throw $e;
        }
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

    public function getServiceIds(): array
    {
        // Handle both ContainerBuilder and compiled containers
        if ($this->container instanceof \Symfony\Component\DependencyInjection\ContainerBuilder) {
            return $this->container->getServiceIds();
        }

        // For compiled containers, we need to use reflection to get service IDs
        if (method_exists($this->container, 'getServiceIds')) {
            return $this->container->getServiceIds();
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
