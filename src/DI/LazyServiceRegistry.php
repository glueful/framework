<?php

declare(strict_types=1);

namespace Glueful\DI;

class LazyServiceRegistry
{
    private Container $container;
    /** @var array<string, string> */
    private array $lazyServices = [];
    /** @var array<string, string> */
    private array $backgroundServices = [];
    /** @var array<string, string> */
    private array $deferredServices = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function lazy(string $id, string $factory): void
    {
        $this->lazyServices[$id] = $factory;

        // Only set proxy if container supports dynamic services
        try {
            if (!$this->container->has($id)) {
                $this->container->set($id, new LazyServiceProxy($factory));
            }
        } catch (\Exception $e) {
            // If container is compiled or doesn't support dynamic services,
            // the service should already be defined through service providers
            // Just log and continue
            error_log("Unable to register lazy service '$id': " . $e->getMessage());
        }
    }

    public function background(string $id, string $factory): void
    {
        $this->backgroundServices[$id] = $factory;
        // Background services initialize after response is sent
    }

    public function deferred(string $id, string $factory): void
    {
        $this->deferredServices[$id] = $factory;

        // Deferred services initialize on first request handling
        try {
            if (!$this->container->has($id)) {
                $this->container->set($id, new DeferredServiceProxy($factory));
            }
        } catch (\Exception $e) {
            // If container is compiled, service should be pre-defined
            error_log("Unable to register deferred service '$id': " . $e->getMessage());
        }
    }

    public function initializeBackground(): void
    {
        foreach ($this->backgroundServices as $id => $factory) {
            try {
                $service = $factory::create();
                $this->container->set($id, $service);
            } catch (\Throwable $e) {
                // Log but don't fail - background services are non-critical
                error_log("Background service {$id} failed: " . $e->getMessage());
            }
        }
    }

    public function activateRequestTime(): void
    {
        foreach ($this->deferredServices as $id => $factory) {
            $service = $this->container->get($id);
            if ($service instanceof DeferredServiceProxy) {
                $service->activateRequestTime();
            }
        }
    }

    /**
     * Get statistics about lazy loading
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $stats = [
            'lazy_services' => count($this->lazyServices),
            'background_services' => count($this->backgroundServices),
            'deferred_services' => count($this->deferredServices),
            'instantiated' => []
        ];

        // Check which lazy services have been instantiated
        foreach ($this->lazyServices as $id => $factory) {
            $service = $this->container->get($id);
            if ($service instanceof LazyServiceProxy) {
                $stats['instantiated'][$id] = $service->isInstantiated();
            }
        }

        return $stats;
    }
}
