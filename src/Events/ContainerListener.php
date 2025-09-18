<?php

declare(strict_types=1);

namespace Glueful\Events;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;

final class ContainerListener
{
    /** @var callable|null */
    private $resolved = null;

    public function __construct(
        private string $serviceReference, // '@id' or '@id:method'
        private ContainerInterface $container
    ) {
    }

    public function __invoke(object $event): void
    {
        $callable = $this->resolved ??= $this->resolveCallable();
        $callable($event);
    }

    private function resolveCallable(): callable
    {
        [$serviceId, $method] = array_pad(
            explode(':', ltrim($this->serviceReference, '@'), 2),
            2,
            null
        );

        try {
            $service = $this->container->get((string)$serviceId);
        } catch (NotFoundExceptionInterface | ContainerExceptionInterface $e) {
            throw $e;
        }

        if ($method === null) {
            if (!is_callable($service)) {
                $class = is_object($service) ? get_class($service) : (string)$service;
                throw new \LogicException("Listener '{$serviceId}' is not invokable. Resolved: {$class}");
            }
            return $service; // invokable object or callable
        }

        $callable = [$service, $method];
        if (!is_callable($callable)) {
            $class = is_object($service) ? get_class($service) : (string)$service;
            throw new \LogicException(
                "Listener '{$serviceId}:{$method}' is not callable. " .
                "Resolved class: {$class}; attempted method: {$method}"
            );
        }
        return $callable;
    }

    public static function identityOf(string $serviceReference): string
    {
        [$id, $method] = array_pad(explode(':', ltrim($serviceReference, '@'), 2), 2, null);
        return $id . '::' . ($method ?? '__invoke');
    }
}
